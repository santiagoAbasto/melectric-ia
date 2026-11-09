<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_definitions', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('description');
            $t->timestamps();
        });

        Schema::create('prompt_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('prompt_definition_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 16)->default('DRAFT'); // DRAFT, TESTING, PUBLISHED, ARCHIVED
            $t->longText('body');
            $t->string('change_note')->nullable();
            $t->json('eval_summary')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['prompt_definition_id', 'version']);
        });

        Schema::create('ai_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('name');
            $t->string('purpose');
            $t->string('provider', 32);
            $t->string('model', 64);
            $t->string('prompt_key');
            $t->json('tools');
            $t->json('knowledge_scopes')->nullable();
            $t->decimal('min_confidence', 4, 3)->default(0.6);
            $t->unsignedInteger('max_tokens')->default(1200);
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });

        Schema::create('ai_rules', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('description');
            $t->string('action', 48);
            $t->decimal('threshold', 4, 3)->nullable();
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('company')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('segment', 32)->nullable();
            $t->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->timestamps();
        });

        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 24)->default('web');
            $t->string('public_token', 64)->unique(); // acceso del cliente web a su conversación
            $t->string('status', 24)->default('OPEN'); // OPEN, WAITING_CUSTOMER, RESOLVED
            $t->string('mode', 24)->default('AI_AUTO'); // AI_AUTO, AI_ASSISTED, HUMAN, ENGINEERING_REVIEW, PAUSED
            $t->string('category', 24)->nullable();     // SALES, TECHNICAL, WARRANTY, DISTRIBUTION, GENERAL, ENGINEERING
            $t->string('handoff_reason')->nullable();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('technical_context')->nullable(); // [{key,value,unit,provenance:USER_PROVIDED|AI_INFERRED|CATALOG_CONFIRMED|HUMAN_CONFIRMED}]
            $t->decimal('last_confidence', 4, 3)->nullable();
            $t->unsignedInteger('unread_count')->default(0);
            $t->timestamp('last_message_at')->nullable()->index();
            $t->timestamps();
            $t->index(['status', 'mode']);
        });

        Schema::create('ai_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('trace_id')->unique();
            $t->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('origin', 24);           // CHAT, PLAYGROUND, EVAL, ASSIST
            $t->string('profile_key')->nullable();
            $t->string('provider', 32)->nullable();
            $t->string('model', 64)->nullable();
            $t->foreignId('prompt_version_id')->nullable()->constrained()->nullOnDelete();
            $t->text('input');
            $t->string('intent', 48)->nullable();
            $t->json('entities')->nullable();
            $t->json('rules_fired')->nullable();
            $t->json('tool_calls')->nullable();
            $t->json('sources')->nullable();
            $t->json('confidence_breakdown')->nullable();
            $t->decimal('confidence', 4, 3)->nullable();
            $t->string('outcome', 24)->nullable();  // ANSWERED, ASKED_INPUT, ABSTAINED, HANDOFF
            $t->longText('answer')->nullable();
            $t->boolean('llm_used')->default(false);
            $t->boolean('llm_rejected')->default(false); // el guardia numérico descartó el texto del LLM
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('latency_ms')->default(0);
            $t->decimal('cost_usd', 10, 6)->default(0);
            $t->string('status', 16)->default('OK');
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index('created_at');
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->string('sender', 16);           // CUSTOMER, AI, HUMAN, SYSTEM
            $t->string('type', 24)->default('TEXT'); // TEXT, IMAGE, PRODUCT, PRODUCT_COMPARE, TECHNICAL_TABLE, AI_SUGGESTION, SYSTEM…
            $t->text('body')->nullable();
            $t->json('payload')->nullable();    // productos, citas, tablas, faltantes
            $t->foreignId('ai_run_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 24)->default('web');
            $t->string('external_id')->nullable();
            $t->string('delivery_status', 16)->default('SENT');
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['conversation_id', 'created_at']);
        });

        Schema::create('ai_feedback', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('verdict', 24);          // CORRECT, INCORRECT, NOT_IDENTIFIABLE
            $t->foreignId('correct_product_id')->nullable()->constrained('products')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
        });

        Schema::create('knowledge_gaps', function (Blueprint $t) {
            $t->id();
            $t->string('question_key')->unique(); // intención + entidades normalizadas
            $t->string('example');
            $t->string('intent', 48)->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->string('status', 16)->default('OPEN');
            $t->timestamps();
        });

        Schema::create('eval_runs', function (Blueprint $t) {
            $t->id();
            $t->string('dataset');
            $t->foreignId('prompt_version_id')->nullable()->constrained()->nullOnDelete();
            $t->json('metrics');
            $t->json('results');
            $t->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 64);
            $t->string('entity_type', 64);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('trace_id', 64)->nullable();
            $t->timestamp('created_at')->useCurrent(); // sin updated_at: el registro no se edita
            $t->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'eval_runs', 'knowledge_gaps', 'ai_feedback', 'messages', 'ai_runs', 'conversations',
            'customers', 'ai_rules', 'ai_profiles', 'prompt_versions', 'prompt_definitions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
