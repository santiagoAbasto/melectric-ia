<?php

use App\Catalog\CatalogImporter;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Excel con la misma estructura que «Clasificación catálogo productos B2B V2» (hojas B2B y MCBs & RCCBs) y filas
 * reales copiadas del archivo del cliente.
 *
 * @param  list<array{0:string|int,1:string,2:string,3:string}>|null  $rows
 */
function catalogXlsx(?array $rows = null): string
{
    $rows ??= [
        [194994, 'HL-C32/3-HX', 'Interruptor auto. curva tipo C 4,5kA, Tripolar 32A', 'Termomagnetica 4.5kA 3P C'],
        [139082, 'mMC6-C32/3', 'Interruptor auto. curva tipo C 6kA, Tripolar 32 A', 'Termomagnetica 6kA 3P C'],
        [139122, 'mMCM-C32/3', 'Interruptor auto. curva tipo C 10kA, Tripolar 32 A', 'Termomagnetica 10kA 3P C'],
        [138999, 'mMC6-C32/2', 'Interruptor auto. curva tipo C 6kA, Bipolar 32 A', 'Termomagnetica 6kA 2P C'],
        [142787, 'mRCM-40/4/003', 'Interruptor diferencial Tetrapolar 40 A 30mA', 'Diferenciales AC 4P'],
        [277260, 'DILM32-10(230V50HZ,240V60HZ)', 'Contactor tripolar AC3 - 3 x 380 V - 15 kW / 32 A – bob 220 Vca – 1 NA', 'Contactores Serie DILM desde 15 hasta 32A'],
        [277274, 'DILM32-10(RDC24)', 'Contactor tripolar AC3 - 3 x 380 V - 15 kW / 32 A – 24 Vcc – 1 NA', 'Contactores Serie DILM desde 15 hasta 32A'],
        [277004, 'DILM17-10(230V50HZ,240V60HZ)', 'Contactor tripolar AC3 - 3 x 380 V - 7.5 kW / 17 A – bob 220 Vca – 1 NA', 'Contactores Serie DILM desde 15 hasta 32A'],
        [72735, 'PKZM0-1,6', 'Interruptor protector de motor, 0,55 kW 380 VAC Reg. 1-1.6 A', 'Guardamotores hasta 32A'],
        [46938, 'PKZM0-16', 'Interruptor protector de motor, 7,5 kW 380 VAC Reg. 10-16 A', 'Guardamotores hasta 32A'],
        [278453, 'ZB32-24', 'Relé térmico para DILM17 hasta DILM32 Reg. 16-24 A', 'Relé Térmico DILM17 a 32'],
        ['ZCSADG 3-0,4/30', 'CSADG 3-0,4/30', 'Un: 400 V; Qn: 30KVAR; Cn: 3x220,8μF; 136x261mm', 'Capacitores'],
    ];
    $book = new Spreadsheet;
    $b2b = $book->getActiveSheet()->setTitle('B2B');
    $b2b->fromArray([['Código', 'Desc Breve', 'Desc Detallada', 'B2B (Modelo (Agrupador)'], ...$rows]);
    $mcb = $book->createSheet()->setTitle('MCBs & RCCBs');
    $mcb->fromArray([[null, 'Imagen', 'Código', 'FAMILIA', 'Subfamilia', 'MARCA', 'Descripción', 'Polos']], null, 'A5');
    $mcb->fromArray([
        [null, null, 194994, 'Termomagnéticas', 'Curva C 4.5kA', 'EATON', 'HL-C32/3-HX', 3],
        [null, null, 139082, 'Termomagnéticas', 'Curva C 6kA', 'EATON', 'mMC6-C32/3', 3],
        [null, null, 139122, 'Termomagnéticas', 'Curva C 10kA', 'EATON', 'mMCM-C32/3', 3],
        [null, null, 138999, 'Termomagnéticas', 'Curva C 6kA', 'EATON', 'mMC6-C32/2', 2],
        [null, null, 142787, 'Diferenciales', '30mA', 'EATON', 'mRCM-40/4/003', 4],
    ], null, 'A6');
    $path = tempnam(sys_get_temp_dir(), 'mel_').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

/** Importa el catálogo de prueba y deja reglas, prompts y perfiles sembrados. */
function seedCatalog(): void
{
    (new DatabaseSeeder)->run();
    app(CatalogImporter::class)->import(catalogXlsx());
}

function userWith(Role $role): User
{
    return User::factory()->create(['role' => $role, 'is_active' => true]);
}
