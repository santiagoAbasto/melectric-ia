<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Conversation\ConversationService;
use App\Models\AiFeedback;
use App\Models\AiRun;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Response;

class InboxController extends Controller
{
    public const FILTERS = ['all' => 'Todas', 'unread' => 'Sin leer', 'ai' => 'IA atendiendo', 'engineer' => 'Necesita ingeniería', 'sales' => 'Necesita ventas',
        'mine' => 'Asignadas a mí', 'waiting' => 'Esperando cliente', 'resolved' => 'Resueltas', 'low' => 'Confianza baja'];

    public function index(Request $request, ?Conversation $conversation = null): Response
    {
        $filter = $request->query('filter', 'all');
        $q = Conversation::query()->with(['customer', 'assignee'])->withCount('messages')->orderByDesc('last_message_at');
        match ($filter) {
            'unread' => $q->where('unread_count', '>', 0),
            'ai' => $q->where('mode', 'AI_AUTO'),
            'engineer' => $q->where(fn ($b) => $b->where('mode', 'ENGINEERING_REVIEW')->orWhere('category', 'ENGINEERING')),
            'sales' => $q->where('category', 'SALES'),
            'mine' => $q->where('assigned_user_id', $request->user()->id),
            'waiting' => $q->where('status', 'WAITING_CUSTOMER'),
            'resolved' => $q->where('status', 'RESOLVED'),
            'low' => $q->where('last_confidence', '<', 0.6),
            default => null,
        };
        if ($search = trim((string) $request->query('q'))) {
            $q->whereHas('customer', fn ($b) => $b->where('name', 'like', "%{$search}%")->orWhere('company', 'like', "%{$search}%"));
        }
        $list = $q->limit(100)->get()->map(fn (Conversation $c) => [
            'id' => $c->id, 'customer' => $c->customer->name, 'company' => $c->customer->company, 'mode' => $c->mode, 'status' => $c->status,
            'category' => $c->category, 'unread' => $c->unread_count, 'confidence' => $c->last_confidence, 'assignee' => $c->assignee?->name,
            'last_at' => $c->last_message_at?->toIso8601String(),
            'preview' => Str::limit((string) $c->messages()->latest('id')->value('body'), 80),
        ]);

        $detail = null;
        if ($conversation) {
            $conversation->load(['customer', 'assignee']);
            if ($conversation->unread_count) {
                $conversation->update(['unread_count' => 0]);
            }
            $run = AiRun::query()->where('conversation_id', $conversation->id)->latest('id')->first();
            $detail = [
                'id' => $conversation->id, 'mode' => $conversation->mode, 'status' => $conversation->status, 'category' => $conversation->category,
                'handoff_reason' => $conversation->handoff_reason, 'confidence' => $conversation->last_confidence, 'channel' => $conversation->channel,
                'customer' => $conversation->customer->only(['name', 'company', 'email', 'phone', 'segment']),
                'assignee' => $conversation->assignee?->name, 'technical_context' => $conversation->technical_context ?? [],
                'messages' => $conversation->messages()->with('user')->orderBy('id')->get()->map(fn (Message $m) => PublicChatController::present($m, true))->all(),
                'last_run' => $run ? ['id' => $run->id, 'trace_id' => $run->trace_id, 'intent' => $run->intent, 'entities' => $run->entities, 'rules' => $run->rules_fired,
                    'tools' => $run->tool_calls, 'confidence' => $run->confidence, 'breakdown' => $run->confidence_breakdown, 'outcome' => $run->outcome,
                    'profile' => $run->profile_key, 'model' => $run->model, 'llm_used' => $run->llm_used, 'llm_rejected' => $run->llm_rejected, 'latency_ms' => $run->latency_ms] : null,
            ];
        }

        return inertia('Inbox/Index', ['conversations' => $list, 'filters' => self::FILTERS, 'filter' => $filter, 'q' => $request->query('q', ''),
            'conversation' => $detail, 'modes' => ConversationService::MODES]);
    }

    public function reply(Conversation $conversation, Request $request, ConversationService $service): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000'], 'suggestion_id' => ['nullable', 'integer']]);
        $suggestion = isset($data['suggestion_id']) ? $conversation->messages()->where('type', 'AI_SUGGESTION')->find($data['suggestion_id']) : null;
        $service->reply($conversation, $request->user(), $data['body'], $suggestion);

        return back();
    }

    public function suggest(Conversation $conversation, Request $request, ConversationService $service): RedirectResponse
    {
        $service->suggest($conversation, $request->user());

        return back();
    }

    public function mode(Conversation $conversation, Request $request, ConversationService $service): RedirectResponse
    {
        $data = $request->validate(['mode' => ['nullable', Rule::in(ConversationService::MODES)], 'status' => ['nullable', Rule::in(['OPEN', 'WAITING_CUSTOMER', 'RESOLVED'])]]);
        if (isset($data['mode'])) {
            $service->setMode($conversation, $data['mode'], $request->user());
        }
        if (isset($data['status'])) {
            $conversation->update(['status' => $data['status']]);
        }

        return back();
    }

    public function approve(Conversation $conversation, Message $message, Request $request, ConversationService $service): RedirectResponse
    {
        abort_unless($message->conversation_id === $conversation->id && $message->type === 'AI_SUGGESTION', 404);
        $data = $request->validate(['body' => ['nullable', 'string', 'max:4000']]);
        $service->approve($conversation, $message, $request->user(), $data['body'] ?? null);

        return back()->with('message', 'Respuesta aprobada y enviada al cliente.');
    }

    public function feedback(AiRun $run, Request $request): RedirectResponse
    {
        $data = $request->validate(['verdict' => ['required', Rule::in(['CORRECT', 'INCORRECT', 'NOT_IDENTIFIABLE'])], 'correct_product_id' => ['nullable', 'integer', 'exists:products,id'], 'note' => ['nullable', 'string', 'max:1000']]);
        AiFeedback::query()->create($data + ['ai_run_id' => $run->id, 'user_id' => $request->user()->id]);

        return back()->with('message', 'Gracias: el feedback queda para calibrar la confianza.');
    }
}
