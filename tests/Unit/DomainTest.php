<?php

use App\AI\ConfidenceService;
use App\AI\NumericGroundingGuard;
use App\Catalog\ProductReferenceNormalizer as N;
use App\Catalog\RelationshipExtractor;
use App\Catalog\SpecExtractor;
use App\Catalog\Units\UnitService as U;
use App\Documents\LayoutTableExtractor;
use App\Search\ElectricalQueryParser;
use App\Sources\PublicWebsiteConnector;
use App\Sources\UrlGuard;

it('normaliza unidades y nunca confunde mA con A', function () {
    expect(U::parse('30 mA')->value)->toBe(0.03)->and(U::parse('30 A')->value)->toBe(30.0)
        ->and(U::parse('30 mA')->value)->not->toBe(U::parse('30 A')->value)
        ->and(U::parse('15 kW')->value)->toBe(15000.0)->and(U::parse('40 KVAR')->unit)->toBe('VAR')
        ->and(U::number('0,16'))->toBe(0.16)->and(U::number('2.600'))->toBe(2600.0)->and(U::number('4.5'))->toBe(4.5)
        ->and(U::format(0.03, 'A'))->toBe('30 mA')->and(U::format(6000, 'A'))->toBe('6 kA');
    $r = U::parseRange('0,16 a 63 A');
    expect([$r->min, $r->max, $r->unit, $r->raw])->toBe([0.16, 63.0, 'A', '0,16 a 63 A'])
        ->and($r->contains(U::parse('16 A')))->toBeTrue()->and($r->contains(U::parse('16 mA')))->toBeFalse()
        ->and(U::formatRange(0.1, 0.16, 'A'))->toBe('0,1 – 0,16 A');
});

it('normaliza referencias sin unir valores decimales distintos', function () {
    expect(N::normalize('DILM25'))->toBe(N::normalize('DILM-25'))->toBe(N::normalize('dilm 25'))
        ->and(N::normalize('PKZM0-1,6'))->not->toBe(N::normalize('PKZM0-16'))
        ->and(N::normalize('mMC6-C32/3'))->not->toBe(N::normalize('mMC6-C32/2'))
        ->and(N::base('DILM32-10(230V50HZ,240V60HZ)'))->toBe('DILM32-10')
        ->and(N::notes('XTCG012B00B0 (Disponible hasta agotar stock)'))->toBe('Disponible hasta agotar stock')
        ->and(N::series('NZMB1-A160'))->toBe('NZMB1')->and(N::sizeKey('DILM32-10(RDC24)'))->toBe(['DILM', 32]);
});

it('extrae especificaciones con texto crudo y origen', function () {
    $specs = collect((new SpecExtractor)->extract('DILM32-10(RDC240)', 'Contactor tripolar AC3 - 3 x 380 V - 15 kW / 32 A – 220 Vcc – 1 NA', 'contactor'))->keyBy('key');
    expect($specs['motor_power']->value)->toBe(15000.0)->and($specs['motor_power']->raw)->toBe('15 kW')
        ->and($specs['rated_current_ac3']->value)->toBe(32.0)->and($specs['coil_voltage']->qualifier)->toBe('DC')
        ->and($specs['utilization_category']->text)->toBe('AC-3')->and($specs['auxiliary_contacts']->text)->toBe('1NA');

    $mcb = collect((new SpecExtractor)->extract('mMC6-C40/4', 'Interruptor auto. curva tipo C 6kA, Tetrapolar 40 A', 'circuit_breaker', 'Curva C 6kA', 4));
    expect($mcb->where('key', 'rated_current')->pluck('value')->unique()->all())->toBe([40.0])
        ->and($mcb->where('key', 'breaking_capacity')->pluck('value')->unique()->all())->toBe([6000.0])
        ->and($mcb->firstWhere('key', 'poles')->origin)->toBe('sheet');

    $range = collect((new SpecExtractor)->extract('PKZM0-0,16', 'Interruptor protector de motor, 0,06 kW 380 VAC Reg. 0.1-0.16 A', 'motor_protector'))->firstWhere('key', 'current_setting_range');
    expect([$range->min, $range->max])->toBe([0.1, 0.16]);
});

it('lee rangos de compatibilidad escritos en la descripción', function () {
    expect(RelationshipExtractor::targets('Relé térmico para DILM17 hasta DILM32 Reg. 16-24 A'))->toMatchArray([['series' => 'DILM', 'from' => 17, 'to' => 32, 'evidence' => 'DILM17 hasta DILM32']])
        ->and(collect(RelationshipExtractor::targets('Relé térmico para DILM185 ; DILM225 y DILM250 Reg. 50-70 A'))->pluck('from')->all())->toBe([185, 225, 250])
        ->and(RelationshipExtractor::targets('Relé térmico para DILEM Reg. 0.1-0.16 A')[0]['series'])->toBe('DILEM');
});

it('interpreta consultas eléctricas en español', function () {
    $p = new ElectricalQueryParser;
    $q = $p->parse('Diferencial 4 polos 40 amperes 30 mili');
    expect($q->productType)->toBe('rcd')->and($q->values)->toMatchArray(['poles' => 4, 'current' => 40.0, 'sensitivity' => 0.03]);
    $q = $p->parse('contactor 15kw trifasico 400');
    expect($q->productType)->toBe('contactor')->and($q->values)->toMatchArray(['power' => 15000.0, 'voltage' => 400.0, 'phases' => 3]);
    expect($p->parse('interruptor curva C 32A 3 polos')->values)->toMatchArray(['current' => 32.0, 'poles' => 3, 'curve' => 'C'])
        ->and($p->parse('¿Cuántos KVAR necesito?')->intents)->toContain('CALCULATION')
        ->and($p->parse('PKZM0-1,6 precio')->referenceCandidates)->toBe(['PKZM0-1,6'])
        ->and($p->parse('hl c32 3 hx')->referenceCandidates[0])->toBe('hl c32 3 hx');
});

it('rechaza números y afirmaciones del LLM que no están en la evidencia', function () {
    $g = new NumericGroundingGuard;
    expect($g->check('El DILM32 soporta 32 A [1].', 'DILM32 32 A', 'DILM32 32 A')['ok'])->toBeTrue()
        ->and($g->check('El DILM32 soporta 40 A.', 'DILM32 32 A', 'DILM32 32 A')['violations'])->toContain('número sin evidencia: 40')
        ->and($g->check('El DILM32 soporta 32 A.', 'DILM32 32 A', 'DILM32 32 A [1]')['violations'])->toContain('perdió citas [1]')
        ->and($g->numbers('40 A, 5,50 kW y [3]'))->toBe(['40', '5.5'])
        ->and($g->check('Es compatible con el DILM32.', 'ZB32 DILM32', 'Según la descripción…')['ok'])->toBeFalse()
        ->and($g->check('Sale USD 120.', 'USD', 'sin precio')['ok'])->toBeFalse();
});

it('aplica topes de confianza por riesgo', function () {
    $c = new ConfidenceService;
    expect($c->score(['strategy' => 'exact_reference'])['score'])->toBeGreaterThan(0.9)
        ->and($c->score(['strategy' => 'exact_reference', 'conflict' => true])['score'])->toBe(0.4)
        ->and($c->score(['strategy' => 'structured', 'unverified_relation' => true])['score'])->toBeLessThanOrEqual(0.6)
        ->and($c->score(['strategy' => 'none'])['score'])->toBe(0.0)
        ->and($c->score(['strategy' => 'structured'])['breakdown']['calibrated'])->toBeFalse();
});

it('reconstruye una tabla del catálogo conservando celdas vacías', function () {
    $text = "Tabla de Potencias y Corrientes de Contactores DILM - E-Line\n   P [kW]   In [A]   Ith [A]\n"
        ."     3        7        22        XTCG007      DILM7\n     7,5     15,5      22                     DILM15\n    15       32        45        XTCG032      DILM32\n    18,5     40        60        XTCG040      DILM40\n";
    $tables = (new LayoutTableExtractor)->extract($text);
    expect($tables)->toHaveCount(1)->and($tables[0]['title'])->toContain('Potencias')
        ->and($tables[0]['rows'][1])->toBe(['7,5', '15,5', '22', '', 'DILM15'])->and($tables[0]['rows'][2][4])->toBe('DILM32');
});

it('bloquea destinos SSRF y respeta robots.txt', function () {
    foreach (['http://localhost/x', 'http://127.0.0.1/x', 'http://169.254.169.254/latest/meta-data', 'file:///etc/passwd', 'ftp://www.melectric.com.ar/', 'https://evil.example.com/'] as $url) {
        expect(fn () => UrlGuard::assertSafe($url, ['www.melectric.com.ar', 'localhost', '127.0.0.1', '169.254.169.254']))->toThrow(RuntimeException::class);
    }
    expect(UrlGuard::publicIp('10.0.0.1'))->toBeFalse()->and(UrlGuard::publicIp('8.8.8.8'))->toBeTrue();
    $rules = PublicWebsiteConnector::parseRobots(file_get_contents(base_path('tests/Fixtures/site/robots.txt')), 'MElectricAI-Crawler');
    expect($rules)->toContain('/Carrito')->toContain('/CheckOut')->not->toContain('/Home/Faq')
        ->and(PublicWebsiteConnector::parseRobots("User-agent: ClaudeBot\nDisallow: /\n\nUser-agent: *\nDisallow: /privado", 'MElectricAI-Crawler'))->toBe(['/privado']);
});
