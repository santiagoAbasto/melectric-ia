<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\AI\AIOrchestrator;
use App\AI\EvaluationRunner;
use App\AI\PromptRegistry;
use App\AI\ProviderManager;
use App\AI\RuleEngine;
use App\AI\ToolRegistry;
use App\Models\AiProfile;
use App\Models\AiRule;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\EvalRun;
use App\Models\PromptDefinition;
use App\Models\PromptVersion;
use App\Search\Embeddings\EmbeddingProviderInterface;
use App\Vision\OcrProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Response;

class AiControlController extends Controller
{
    public function index(Request $request, ProviderManager $providers, EmbeddingProviderInterface $embeddings): Response
    {
        $since = fn (int $days) => AiRun::query()->where('created_at', '>=', now()->subDays($days));
        $costBy = fn (string $col) => AiRun::query()->where('created_at', '>=', now()->subDays(30))->groupBy($col)
            ->selectRaw("COALESCE({$col}, '".($col === 'model' ? 'sin LLM' : 'sin dato')."') AS k, COUNT(*) AS runs, SUM(cost_usd) AS cost, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens")->get();

        return inertia('Ai/Index', [
            'tab' => $request->query('tab', 'overview'),
            'overview' => [
                'runs_today' => $since(1)->count(), 'runs_7d' => $since(7)->count(), 'runs_30d' => $since(30)->count(),
                'cost_today' => round((float) $since(1)->sum('cost_usd'), 4), 'cost_7d' => round((float) $since(7)->sum('cost_usd'), 4), 'cost_30d' => round((float) $since(30)->sum('cost_usd'), 4),
                'avg_latency_ms' => (int) $since(7)->avg('latency_ms'), 'avg_confidence' => round((float) $since(7)->avg('confidence'), 3),
                'outcomes' => $since(30)->groupBy('outcome')->selectRaw('outcome, COUNT(*) AS n')->pluck('n', 'outcome'),
                'llm_used' => $since(30)->where('llm_used', true)->count(), 'llm_rejected' => $since(30)->where('llm_rejected', true)->count(),
                'errors' => $since(30)->where('status', '!=', 'OK')->count(),
                'handoffs' => $since(30)->where('outcome', 'HANDOFF')->count(),
            ],
            'costs' => ['by_model' => $costBy('model'), 'by_profile' => $costBy('profile_key'), 'by_origin' => $costBy('origin')],
            'providers' => [
                ['name' => 'anthropic', 'kind' => 'LLM', 'live' => $providers->isLive('anthropic'), 'models' => config('melectric.ai.models.anthropic'), 'note' => ($down = ProviderManager::downReason('anthropic')) ? 'Fuera de línea desde '.$down['at'].': '.$down['reason'] : (filled(config('melectric.ai.anthropic_key')) ? 'ANTHROPIC_API_KEY presente (se verifica al usarla)' : 'Falta ANTHROPIC_API_KEY')],
                ['name' => 'ollama', 'kind' => 'LLM local', 'live' => $providers->isLive('ollama'), 'models' => config('melectric.ai.models.ollama'), 'note' => config('melectric.embeddings.ollama_url')],
                ['name' => $embeddings->model(), 'kind' => 'Embeddings', 'live' => $embeddings->available(), 'models' => null, 'note' => 'Índice reconstruible desde document_chunks'],
                ['name' => app(OcrProviderInterface::class)->name(), 'kind' => 'OCR', 'live' => app(OcrProviderInterface::class)->available(), 'models' => null, 'note' => 'OCR local de macOS (sólo desarrollo). En Linux: tesseract o un modelo de visión con credenciales'],
            ],
            'default_provider' => config('melectric.ai.provider'),
            'profiles' => AiProfile::query()->orderBy('key')->get(),
            'prompts' => PromptDefinition::query()->with(['versions' => fn ($q) => $q->orderByDesc('version')])->get()->map(fn ($d) => [
                'id' => $d->id, 'key' => $d->key, 'description' => $d->description,
                'versions' => $d->versions->map->only(['id', 'version', 'status', 'body', 'change_note', 'eval_summary', 'published_at', 'created_at'])]),
            'rules' => AiRule::query()->orderBy('key')->get()->map(fn ($r) => $r->only(['id', 'key', 'description', 'action', 'threshold', 'enabled']) + ['locked' => in_array($r->key, RuleEngine::LOCKED, true)]),
            'tools' => collect(tap(app(ToolRegistry::class), fn () => app(AIOrchestrator::class))->definitions())->map(fn ($d, $name) => ['name' => $name] + $d)->values(),
            'traces' => AiRun::query()->latest('id')->limit(40)->get(['id', 'trace_id', 'origin', 'profile_key', 'intent', 'outcome', 'confidence', 'provider', 'model', 'llm_used', 'llm_rejected',
                'input', 'latency_ms', 'cost_usd', 'input_tokens', 'output_tokens', 'rules_fired', 'tool_calls', 'status', 'error', 'prompt_version_id', 'created_at']),
            'evals' => EvalRun::query()->latest('id')->limit(10)->get(),
        ]);
    }

    /** Playground (spec §110): misma tubería que el chat, con perfil/proveedor/versión de prompt elegibles y la traza completa. */
    public function playground(Request $request, AIOrchestrator $ai): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000'], 'profile' => ['nullable', 'string', 'exists:ai_profiles,key'],
            'provider' => ['nullable', 'in:anthropic,ollama'], 'use_llm' => ['boolean'], 'prompt_version_id' => ['nullable', 'integer', 'exists:prompt_versions,id']]);
        $a = $ai->answer($data['message'], null, 'PLAYGROUND', array_filter(['profile' => $data['profile'] ?? null, 'provider' => $data['provider'] ?? null,
            'use_llm' => $data['use_llm'] ?? false, 'prompt_version_id' => $data['prompt_version_id'] ?? null], fn ($v) => $v !== null));
        $run = AiRun::query()->find($a->runId);

        return response()->json(['answer' => $a->text, 'outcome' => $a->outcome, 'products' => $a->products, 'citations' => $a->citations, 'table' => $a->table,
            'missing' => $a->missing, 'confidence' => $a->confidence, 'breakdown' => $a->confidenceBreakdown, 'rules' => $a->rulesFired, 'tools' => $a->toolCalls,
            'handoff' => $a->handoff, 'handoff_reason' => $a->handoffReason, 'trace' => $a->trace, 'trace_id' => $a->traceId,
            'run' => $run?->only(['provider', 'model', 'input_tokens', 'output_tokens', 'latency_ms', 'cost_usd', 'llm_used', 'llm_rejected', 'error'])]);
    }

    public function saveDraft(PromptDefinition $definition, Request $request): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:20000'], 'change_note' => ['nullable', 'string', 'max:200']]);
        $version = $definition->versions()->create(['version' => (int) $definition->versions()->max('version') + 1, 'status' => 'DRAFT',
            'body' => $data['body'], 'change_note' => $data['change_note'] ?? null, 'created_by' => $request->user()->id]);
        AuditLog::record('ai.prompt_draft_saved', $version, null, ['version' => $version->version]);

        return back()->with('message', "Borrador v{$version->version} guardado.");
    }

    public function publish(PromptVersion $version, Request $request, PromptRegistry $registry): RedirectResponse
    {
        $before = $registry->published($version->definition->key)?->only(['id', 'version']);
        DB::transaction(fn () => $registry->publish($version));
        AuditLog::record('ai.prompt_published', $version, $before, ['id' => $version->id, 'version' => $version->version]);

        return back()->with('message', "Versión v{$version->version} publicada.");
    }

    public function updateRule(AiRule $rule, Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['boolean'], 'threshold' => ['nullable', 'numeric', 'between:0,1']]);
        if (in_array($rule->key, RuleEngine::LOCKED, true) && ($data['enabled'] ?? true) === false) {
            return back()->with('error', 'Esta regla de seguridad no se puede desactivar; sólo se ajusta su umbral.');
        }
        $before = $rule->only(['enabled', 'threshold']);
        $rule->update(array_filter($data, fn ($v) => $v !== null));
        AuditLog::record('ai.rule_changed', $rule, $before, $rule->only(['enabled', 'threshold']));

        return back()->with('message', 'Regla actualizada.');
    }

    public function runEval(Request $request, EvaluationRunner $runner): RedirectResponse
    {
        $data = $request->validate(['prompt_version_id' => ['nullable', 'integer', 'exists:prompt_versions,id'], 'use_llm' => ['boolean']]);
        $run = $runner->run($data['prompt_version_id'] ?? null, $request->user()->id, (bool) ($data['use_llm'] ?? false));
        if (isset($data['prompt_version_id'])) {
            PromptVersion::query()->whereKey($data['prompt_version_id'])->where('status', 'DRAFT')->update(['status' => 'TESTING', 'eval_summary' => $run->metrics]);
        }

        return back()->with('message', "Evaluación: {$run->metrics['passed']}/{$run->metrics['cases']} casos OK.");
    }
}
