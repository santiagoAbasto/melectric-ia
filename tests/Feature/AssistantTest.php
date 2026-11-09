<?php

use App\AI\AIOrchestrator;
use App\AI\Contracts\AIProviderInterface;
use App\AI\ProviderManager;
use App\AI\Providers\FakeAIProvider;
use App\Conversation\ConversationService;
use App\Enums\Role;
use App\Models\AiRun;
use App\Models\KnowledgeConflict;
use App\Models\Product;
use App\Models\ProductSpecification;

beforeEach(fn () => seedCatalog());

function ask(string $q, array $opts = ['use_llm' => false])
{
    return app(AIOrchestrator::class)->answer($q, null, 'TEST', $opts);
}

it('responde una referencia exacta con cita a la fila del Excel y deja traza', function () {
    $a = ask('HL-C32/3-HX');
    expect($a->outcome)->toBe('ANSWERED')->and($a->text)->toContain('HL-C32/3-HX')->toContain('[1]')
        ->and($a->citations[0]['kind'])->toBe('excel')->and($a->citations[0]['locator'])->toBe('B2B!A2')
        ->and($a->rulesFired)->toContain('EXACT_REFERENCE_LOOKUP')->and($a->confidence)->toBeGreaterThan(0.9);
    $run = AiRun::query()->findOrFail($a->runId);
    expect($run->trace_id)->toBe($a->traceId)->and($run->tool_calls[0]['tool'])->toBe('searchProducts')->and($run->llm_used)->toBeFalse();
});

it('pide los datos que faltan en vez de inventar', function () {
    $a = ask('Necesito interruptor para mi instalación');
    expect($a->outcome)->toBe('ASKED_INPUT')->and($a->products)->toBe([])->and($a->missing)->not->toBeEmpty();
});

it('selecciona un contactor por potencia y pregunta la bobina', function () {
    $a = ask('Necesito un contactor para un motor de 15 kW 400 V');
    expect($a->outcome)->toBe('ANSWERED')->and($a->text)->toContain('DILM32')->toContain('15 kW')->toContain('tensión de bobina');
});

it('no da precio ni hace cálculos eléctricos: deriva', function () {
    $price = ask('¿Cuánto cuesta el PKZM0-16?');
    expect($price->outcome)->toBe('HANDOFF')->and($price->handoff)->toBe('SALES')->and($price->rulesFired)->toContain('PRICE_UNAVAILABLE')->and($price->text)->not->toContain('$');
    $calc = ask('¿Cuántos KVAR necesito?');
    expect($calc->handoff)->toBe('ENGINEERING')->and($calc->rulesFired)->toContain('CALCULATION_REQUIRES_ENGINEERING');
    $volt = ask('contactor 15 kW a 220 V trifásico');
    expect($volt->outcome)->toBe('HANDOFF')->and($volt->rulesFired)->toContain('VOLTAGE_OUTSIDE_CATALOG_TABLE');
});

it('no fabrica referencias inexistentes', function () {
    $a = ask('Busco el producto XYZ-9999');
    expect($a->outcome)->toBe('ABSTAINED')->and($a->products)->toBe([])->and($a->text)->toContain('No encontré la referencia XYZ-9999');
});

it('no afirma compatibilidad no verificada', function () {
    $a = ask('¿Qué relé térmico corresponde a DILM32?');
    expect($a->rulesFired)->toContain('UNVERIFIED_RELATION_NO_CLAIM')->and($a->text)->toContain('No tengo una compatibilidad verificada')
        ->and($a->text)->not->toContain('es compatible')->and($a->confidence)->toBeLessThanOrEqual(0.6);
});

it('ante un conflicto no da el dato como definitivo y, resuelto, deja de advertir', function () {
    $p = Product::query()->where('reference', 'mMC6-C32/3')->firstOrFail();
    $a = ProductSpecification::query()->where('product_id', $p->id)->whereHas('definition', fn ($d) => $d->where('key', 'rated_current'))->firstOrFail();
    $b = $a->replicate()->fill(['value' => 40, 'raw_value' => '40 A (catálogo de prueba)', 'extractor' => 'contactor_table@1']);
    $b->save();
    KnowledgeConflict::query()->create(['product_id' => $p->id, 'definition_id' => $a->definition_id, 'field' => 'rated_current', 'spec_a_id' => $a->id, 'spec_b_id' => $b->id,
        'value_a' => '32 A', 'source_a' => 'Excel', 'value_b' => '40 A', 'source_b' => 'Catálogo p. 3']);

    $withConflict = ask('mMC6-C32/3');
    expect($withConflict->rulesFired)->toContain('CONFLICTING_SOURCES_NO_DEFINITIVE')->and($withConflict->confidence)->toBeLessThanOrEqual(0.4)->and($withConflict->text)->toContain('no coinciden');

    $engineer = userWith(Role::TECHNICAL_ENGINEER);
    $this->actingAs($engineer)->post('/knowledge/conflicts/'.KnowledgeConflict::query()->first()->id, ['resolution' => 'A', 'note' => 'Verificado en placa'])->assertRedirect();
    expect($b->refresh()->status)->toBe('REJECTED')->and($a->refresh()->status)->toBe('VERIFIED');

    $resolved = ask('mMC6-C32/3');
    expect($resolved->rulesFired)->not->toContain('CONFLICTING_SOURCES_NO_DEFINITIVE')->and($resolved->text)->toContain('32 A');
});

it('descarta el texto del LLM si inventa un número y envía el borrador', function () {
    app()->instance(ProviderManager::class, new class extends ProviderManager
    {
        public function resolve(?string $name = null): AIProviderInterface
        {
            return new FakeAIProvider('El mMC6-C32/3 tiene 99 A de corriente.');
        }

        public function isLive(?string $name = null): bool
        {
            return true;
        }
    });
    $a = ask('interruptor curva C 32A 3 polos', ['use_llm' => true]);
    expect($a->trace['llm']['used'])->toBeTrue()->and($a->trace['llm']['rejected'])->toBeTrue()
        ->and($a->trace['llm']['violations'])->toContain('número sin evidencia: 99')->and($a->text)->not->toContain('99 A');
});

it('si el proveedor de IA falla, responde igual desde el catálogo', function () {
    app()->instance(ProviderManager::class, new class extends ProviderManager
    {
        public function resolve(?string $name = null): AIProviderInterface
        {
            throw new RuntimeException('proveedor caído');
        }

        public function isLive(?string $name = null): bool
        {
            return true;
        }
    });
    $a = ask('interruptor curva C 32A 3 polos', ['use_llm' => true]);
    expect($a->outcome)->toBe('ANSWERED')->and($a->text)->toContain('mMC6-C32/3')->and(AiRun::query()->find($a->runId)->status)->toBe('LLM_ERROR');
});

it('reutiliza el contexto técnico de la conversación', function () {
    $c = app(ConversationService::class)->start('Ana');
    app(AIOrchestrator::class)->answer('contactor para motor de 15 kW', $c, 'TEST', ['use_llm' => false]);
    expect($c->refresh()->technical_context['values']['power']['provenance'])->toBe('USER_PROVIDED');
    $next = app(AIOrchestrator::class)->answer('¿y con bobina de 24 Vcc?', $c, 'TEST', ['use_llm' => false]);
    expect($next->text)->toContain('DILM32-10(RDC24)');
});
