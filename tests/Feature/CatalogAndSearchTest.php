<?php

use App\Catalog\CatalogImporter;
use App\Models\KnowledgeConflict;
use App\Models\Product;
use App\Models\ProductRelationship;
use App\Models\ProductSpecification;
use App\Search\ProductSearchService;

it('importa el Excel con procedencia y es idempotente', function () {
    $path = catalogXlsx();
    $stats = app(CatalogImporter::class)->import($path);
    expect($stats['new'])->toBe(12)->and($stats['failed'])->toBe(0);

    $p = Product::query()->where('reference', 'HL-C32/3-HX')->firstOrFail();
    expect($p->internal_code)->toBe('194994')->and($p->brand->name)->toBe('Eaton')->and($p->family->category->product_type)->toBe('circuit_breaker')
        ->and($p->sourceRecord->locator)->toBe('B2B!A2')->and($p->sourceRecord->payload['Desc Breve'])->toBe('HL-C32/3-HX');
    $curve = $p->specifications()->whereHas('definition', fn ($d) => $d->where('key', 'curve'))->first();
    expect($curve->value_text)->toBe('C')->and($curve->raw_value)->toContain('curva tipo C');

    $again = app(CatalogImporter::class)->import($path);
    expect($again['new'])->toBe(0)->and($again['unchanged'])->toBe(12)->and(Product::query()->count())->toBe(12);
});

it('extrae relaciones del catálogo como no verificadas', function () {
    app(CatalogImporter::class)->import(catalogXlsx());
    $rel = ProductRelationship::query()->whereHas('product', fn ($q) => $q->where('reference', 'ZB32-24'))->get();
    expect($rel)->toHaveCount(3)->and($rel->pluck('status')->unique()->all())->toBe(['AUTO_EXTRACTED'])
        ->and($rel->first()->type)->toBe('PAIRS_WITH')->and($rel->first()->evidence)->toContain('DILM17 hasta DILM32');
});

it('abre un conflicto cuando dos orígenes del mismo Excel no coinciden', function () {
    $path = catalogXlsx([[139082, 'mMC6-C32/3', 'Interruptor auto. curva tipo C 6kA, Tripolar 40 A', 'Termomagnetica 6kA 3P C']]);
    app(CatalogImporter::class)->import($path);
    $conflict = KnowledgeConflict::query()->where('field', 'rated_current')->firstOrFail();
    expect([$conflict->value_a, $conflict->value_b])->toEqualCanonicalizing(['32 A', '40 A'])
        ->and(ProductSpecification::query()->where('status', 'NEEDS_REVIEW')->count())->toBe(2);
});

it('busca primero por referencia exacta, luego normalizada y luego estructurada', function () {
    app(CatalogImporter::class)->import(catalogXlsx());
    $s = app(ProductSearchService::class);
    expect($s->search('HL-C32/3-HX')->strategy)->toBe('exact_reference')
        ->and($s->search('hl c32 3 hx')->strategy)->toBe('normalized_reference')
        ->and($s->search('PKZM0-1,6')->products()->pluck('reference')->all())->toBe(['PKZM0-1,6']);

    $r = $s->search('interruptor curva C 32A 3 polos');
    expect($r->strategy)->toBe('structured')
        ->and($r->products()->pluck('reference')->sort()->values()->all())->toBe(['HL-C32/3-HX', 'mMC6-C32/3', 'mMCM-C32/3']);

    expect($s->search('Diferencial 4 polos 40 amperes 30 mili')->products()->pluck('reference')->all())->toBe(['mRCM-40/4/003'])
        ->and($s->search('guardamotor para 16 A')->products()->pluck('reference')->all())->toBe(['PKZM0-16'])
        ->and($s->search('capacitor 30 kvar')->products()->pluck('reference')->all())->toBe(['CSADG 3-0,4/30'])
        ->and($s->search('interruptor curva C 37 A 3 polos')->found())->toBeFalse();
});

it('elige el tamaño siguiente de la tabla AC-3 y lo marca', function () {
    app(CatalogImporter::class)->import(catalogXlsx());
    $r = app(ProductSearchService::class)->search('contactor para motor 10 kW');
    expect($r->rules)->toContain('AC3_TABLE_NEXT_SIZE')->and($r->products()->pluck('base_reference')->unique()->values()->all())->toBe(['DILM32-10']);
});
