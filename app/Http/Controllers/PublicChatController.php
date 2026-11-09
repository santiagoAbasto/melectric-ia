<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Conversation\ConversationService;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Canal web del cliente: identifica la conversación por un token aleatorio de 48 caracteres, sin cuenta. */
class PublicChatController extends Controller
{
    public function show(): Response
    {
        return inertia('Chat/Customer');
    }

    public function start(Request $request, ConversationService $service): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'company' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:160']]);
        $c = $service->start($data['name'], $data['company'] ?? null, $data['email'] ?? null);

        return response()->json(['token' => $c->public_token]);
    }

    public function messages(string $token, Request $request): JsonResponse
    {
        $c = $this->conversation($token);

        return response()->json(['messages' => $this->visible($c, (int) $request->query('after', 0)), 'mode' => $c->mode]);
    }

    public function send(string $token, Request $request, ConversationService $service): JsonResponse
    {
        $c = $this->conversation($token);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $service->receive($c, $data['body']);

        return response()->json(['messages' => $this->visible($c->refresh(), (int) $request->input('after', 0)), 'mode' => $c->mode]);
    }

    public function image(string $token, Request $request, ConversationService $service): JsonResponse
    {
        $c = $this->conversation($token);
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);
        try {
            $service->receiveImage($c, $request->file('image'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['messages' => $this->visible($c->refresh(), (int) $request->input('after', 0)), 'mode' => $c->mode]);
    }

    public function media(string $token, Message $message): StreamedResponse
    {
        $c = $this->conversation($token);
        abort_unless($message->conversation_id === $c->id && isset($message->payload['image']), 404);

        return self::stream($message);
    }

    public static function stream(Message $message): StreamedResponse
    {
        return Storage::disk('local')->response($message->payload['image'], null,
            ['Content-Type' => $message->payload['mime'] ?? 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=600']);
    }

    private function conversation(string $token): Conversation
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/', $token), 404);

        return Conversation::query()->where('public_token', $token)->firstOrFail();
    }

    private function visible(Conversation $c, int $after): array
    {
        return $c->messages()->where('id', '>', $after)->whereIn('sender', ConversationService::VISIBLE_TO_CUSTOMER)->where('type', '!=', 'AI_SUGGESTION')
            ->orderBy('id')->get()->map(fn (Message $m) => self::present($m, false, $c->public_token))->all();
    }

    /**
     * El cliente no ve rutas internas: los catálogos públicos enlazan al sitio oficial en la página citada y las filas
     * del Excel se nombran como «Catálogo de productos MElectric», sin enlace.
     */
    private static function publicCitations(array $citations): array
    {
        return array_map(fn ($c) => ['n' => $c['n'], 'kind' => $c['kind'], 'locator' => '',
            'label' => $c['kind'] === 'excel' ? 'Catálogo de productos MElectric' : $c['label'],
            'url' => null, 'public_url' => $c['public_url'] ?? null], $citations);
    }

    /** Mensaje para la UI. Al cliente no le llegan reglas ni trazas internas. */
    public static function present(Message $m, bool $internal, ?string $token = null): array
    {
        $p = $m->payload ?? [];
        $image = isset($p['image']) ? ($internal ? "/media/messages/{$m->id}" : "/chat/{$token}/media/{$m->id}") : null;

        return ['id' => $m->id, 'sender' => $m->sender, 'type' => $m->type, 'body' => $m->body, 'at' => $m->created_at?->toIso8601String(),
            'products' => $p['products'] ?? [], 'citations' => $internal ? ($p['citations'] ?? []) : self::publicCitations($p['citations'] ?? []), 'table' => $p['table'] ?? null, 'missing' => $p['missing'] ?? [],
            'user' => $m->user?->name, 'image_url' => $image] + ($internal ? ['outcome' => $p['outcome'] ?? null, 'confidence' => $p['confidence'] ?? null, 'rules' => $p['rules'] ?? [],
                'handoff' => $p['handoff'] ?? null, 'pending_review' => $p['pending_review'] ?? false, 'run_id' => $m->ai_run_id, 'approved_by' => $p['approved_by'] ?? null] : []);
    }
}
