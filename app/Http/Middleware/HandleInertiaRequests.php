<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\AI\ProviderManager;
use App\Enums\Role;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'initials' => $user->initials(),
                    'role' => $user->role?->value, 'role_label' => $user->role?->label()] : null,
                'can' => $user ? collect(Role::PERMISSIONS)->mapWithKeys(fn ($p) => [$p => $user->hasPermission($p)])->all() : [],
            ],
            'ai' => fn () => $user ? ['provider' => config('melectric.ai.provider'), 'live' => app(ProviderManager::class)->isLive(),
                'writer' => in_array(config('melectric.ai.provider'), config('melectric.ai.writer_providers', []), true)] : null,
            // Contadores de la barra lateral (dos consultas indexadas por estado/modo)
            'nav' => fn () => $user?->hasPermission('conversations.view') ? [
                'unread' => Conversation::query()->where('unread_count', '>', 0)->count(),
                'review' => Conversation::query()->where('mode', 'ENGINEERING_REVIEW')->count(),
            ] : null,
            'flash' => ['message' => fn () => $request->session()->get('message'), 'error' => fn () => $request->session()->get('error')],
        ];
    }
}
