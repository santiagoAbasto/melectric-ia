<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case AI_MANAGER = 'ai_manager';
    case KNOWLEDGE_MANAGER = 'knowledge_manager';
    case TECHNICAL_ENGINEER = 'technical_engineer';
    case SUPERVISOR = 'supervisor';
    case SALES_AGENT = 'sales_agent';
    case SUPPORT_AGENT = 'support_agent';
    case VIEWER = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super admin',
            self::ADMIN => 'Administrador',
            self::AI_MANAGER => 'Responsable de IA',
            self::KNOWLEDGE_MANAGER => 'Gestor de conocimiento',
            self::TECHNICAL_ENGINEER => 'Ingeniero técnico',
            self::SUPERVISOR => 'Supervisor',
            self::SALES_AGENT => 'Vendedor',
            self::SUPPORT_AGENT => 'Soporte',
            self::VIEWER => 'Observador',
        };
    }

    /** @return list<string> permisos (spec §128). Los Gates se definen a partir de esta tabla. */
    public function permissions(): array
    {
        $all = self::PERMISSIONS;

        return match ($this) {
            self::SUPER_ADMIN, self::ADMIN => $all,
            self::AI_MANAGER => ['catalog.view', 'documents.review', 'ai.providers.manage', 'ai.models.manage', 'ai.prompts.manage', 'ai.rules.manage', 'ai.evals.run', 'conversations.view', 'analytics.view', 'audit.view'],
            self::KNOWLEDGE_MANAGER => ['catalog.view', 'catalog.manage', 'technical_data.review', 'compatibilities.manage', 'documents.upload', 'documents.review', 'ai.evals.run', 'conversations.view', 'analytics.view'],
            self::TECHNICAL_ENGINEER => ['catalog.view', 'technical_data.review', 'compatibilities.manage', 'documents.review', 'conversations.view', 'conversations.reply', 'engineering.review'],
            self::SUPERVISOR => ['catalog.view', 'conversations.view', 'conversations.reply', 'analytics.view', 'audit.view'],
            self::SALES_AGENT, self::SUPPORT_AGENT => ['catalog.view', 'conversations.view', 'conversations.reply'],
            self::VIEWER => ['catalog.view', 'conversations.view'],
        };
    }

    public const PERMISSIONS = [
        'catalog.view', 'catalog.manage', 'technical_data.review', 'compatibilities.manage', 'documents.upload',
        'documents.review', 'ai.providers.manage', 'ai.models.manage', 'ai.prompts.manage', 'ai.rules.manage',
        'ai.evals.run', 'conversations.view', 'conversations.reply', 'engineering.review', 'analytics.view', 'audit.view',
    ];
}
