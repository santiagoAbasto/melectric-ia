<?php

declare(strict_types=1);

namespace App\Conversation;

use App\AI\AIOrchestrator;
use App\AI\DTOs\AiAnswer;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Vision\VisionPipeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Conversaciones por canal (WebChannel hoy; WhatsApp y correo se enchufan como otro canal sin tocar este servicio).
 * Modos: AI_AUTO responde solo · AI_ASSISTED sólo sugiere al operador · HUMAN / PAUSED sin IA ·
 * ENGINEERING_REVIEW: la respuesta técnica queda como sugerencia hasta que un ingeniero la aprueba.
 */
final class ConversationService
{
    public const MODES = ['AI_AUTO', 'AI_ASSISTED', 'HUMAN', 'ENGINEERING_REVIEW', 'PAUSED'];

    public const VISIBLE_TO_CUSTOMER = ['CUSTOMER', 'AI', 'HUMAN'];

    public function __construct(private readonly AIOrchestrator $ai) {}

    public function start(string $name, ?string $company = null, ?string $email = null, string $channel = 'web'): Conversation
    {
        $customer = Customer::query()->create(['name' => $name, 'company' => $company, 'email' => $email]);

        return Conversation::query()->create(['customer_id' => $customer->id, 'channel' => $channel, 'public_token' => Str::random(48),
            'status' => 'OPEN', 'mode' => 'AI_AUTO', 'last_message_at' => now()]);
    }

    /** Mensaje del cliente: se guarda y, si el modo lo permite, responde la IA. Devuelve los mensajes nuevos visibles. */
    public function receive(Conversation $c, string $body): array
    {
        $body = Str::limit(trim($body), 2000, '');
        $in = $this->add($c, 'CUSTOMER', 'TEXT', $body);
        $c->update(['status' => 'OPEN', 'unread_count' => $c->unread_count + 1, 'last_message_at' => now()]);
        if ($c->mode !== 'AI_AUTO') {
            return [$in];
        }
        $answer = $this->ai->answer($body, $c, 'CHAT');

        return [$in, ...$this->deliver($c, $answer)];
    }

    /** Foto del cliente: se guarda en disco privado y la IA responde después de devolver la respuesta HTTP (el OCR tarda). */
    public function receiveImage(Conversation $c, UploadedFile $file): Message
    {
        $mime = VisionPipeline::validate($file->getRealPath());
        $path = 'chat-uploads/'.Str::uuid().'.'.['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        $msg = $this->add($c, 'CUSTOMER', 'IMAGE', 'Foto enviada', ['image' => $path, 'mime' => $mime]);
        $c->update(['status' => 'OPEN', 'unread_count' => $c->unread_count + 1, 'last_message_at' => now()]);
        if ($c->mode === 'AI_AUTO') {
            $full = Storage::disk('local')->path($path);
            dispatch(fn () => $this->deliver($c->refresh(), $this->ai->answerImage($full, $c)))->afterResponse();
        }

        return $msg;
    }

    /** @return list<Message> */
    public function deliver(Conversation $c, AiAnswer $a): array
    {
        $c->update(['last_confidence' => $a->confidence]);
        $type = $a->table ? 'TECHNICAL_TABLE' : ($a->products ? 'PRODUCT' : 'TEXT');

        if ($a->outcome === 'HANDOFF' && $a->handoff === 'ENGINEERING' && in_array('SAFETY_LOW_CONFIDENCE_REVIEW', $a->rulesFired, true)) {
            // La selección técnica no sale sin aprobación: queda como sugerencia interna
            $this->add($c, 'AI', 'AI_SUGGESTION', $a->text, $a->payload() + ['pending_review' => true], $a->runId);
            $c->update(['mode' => 'ENGINEERING_REVIEW', 'category' => 'ENGINEERING', 'handoff_reason' => $a->handoffReason]);

            return [$this->add($c, 'AI', 'TEXT', 'Estoy verificando esta selección con el equipo técnico de MElectric antes de confirmártela. Te respondemos por este chat a la brevedad.', ['outcome' => 'HANDOFF'], $a->runId)];
        }
        $msg = $this->add($c, 'AI', $type, $a->text, $a->payload(), $a->runId);
        if ($a->outcome === 'HANDOFF') {
            $c->update(['mode' => 'HUMAN', 'category' => match ($a->handoff) {
                'SALES' => 'SALES', 'ENGINEERING' => 'ENGINEERING', 'TECHNICAL' => 'TECHNICAL', default => 'GENERAL'
            },
                'handoff_reason' => $a->handoffReason]);
        } elseif ($a->outcome === 'ASKED_INPUT') {
            $c->update(['status' => 'WAITING_CUSTOMER']);
        }

        return [$msg];
    }

    public function suggest(Conversation $c, User $operator): Message
    {
        $last = $c->messages()->where('sender', 'CUSTOMER')->latest('id')->first();
        $answer = $this->ai->answer((string) $last?->body, $c, 'ASSIST');

        return $this->add($c, 'AI', 'AI_SUGGESTION', $answer->text, $answer->payload(), $answer->runId, $operator->id);
    }

    public function reply(Conversation $c, User $operator, string $body, ?Message $fromSuggestion = null): Message
    {
        $msg = $this->add($c, 'HUMAN', $fromSuggestion?->payload ? ($fromSuggestion->payload['table'] ? 'TECHNICAL_TABLE' : ($fromSuggestion->payload['products'] ? 'PRODUCT' : 'TEXT')) : 'TEXT',
            trim($body), $fromSuggestion?->payload, $fromSuggestion?->ai_run_id, $operator->id);
        $c->update(['unread_count' => 0, 'status' => 'OPEN', 'assigned_user_id' => $c->assigned_user_id ?? $operator->id, 'last_message_at' => now()]);

        return $msg;
    }

    /** El ingeniero aprueba (y puede editar) la sugerencia retenida: recién ahí la ve el cliente. */
    public function approve(Conversation $c, Message $suggestion, User $engineer, ?string $editedText = null): Message
    {
        $sent = $this->add($c, 'AI', $suggestion->payload['table'] ?? null ? 'TECHNICAL_TABLE' : (($suggestion->payload['products'] ?? []) ? 'PRODUCT' : 'TEXT'),
            $editedText ?: $suggestion->body, ($suggestion->payload ?? []) + ['approved_by' => $engineer->name], $suggestion->ai_run_id, $engineer->id);
        $suggestion->update(['payload' => ($suggestion->payload ?? []) + ['pending_review' => false, 'approved_message_id' => $sent->id]]);
        $c->update(['mode' => 'HUMAN']);
        AuditLog::record('engineering.solution_approved', $suggestion, ['text' => $suggestion->body], ['text' => $sent->body, 'edited' => (bool) $editedText]);

        return $sent;
    }

    public function setMode(Conversation $c, string $mode, User $by): void
    {
        $before = $c->only(['mode']);
        $c->update(['mode' => $mode]);
        AuditLog::record('conversation.mode_changed', $c, $before, ['mode' => $mode, 'by' => $by->id]);
    }

    private function add(Conversation $c, string $sender, string $type, string $body, ?array $payload = null, ?int $runId = null, ?int $userId = null): Message
    {
        return $c->messages()->create(['sender' => $sender, 'type' => $type, 'body' => $body, 'payload' => $payload, 'ai_run_id' => $runId,
            'user_id' => $userId, 'channel' => $c->channel, 'sent_at' => now(), 'delivery_status' => 'SENT']);
    }
}
