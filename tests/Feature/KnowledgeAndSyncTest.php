<?php

use App\AI\AIOrchestrator;
use App\AI\Contracts\AIProviderInterface;
use App\AI\ProviderManager;
use App\AI\Providers\FakeAIProvider;
use App\Documents\DocumentIngestionService;
use App\Enums\Role;
use App\Models\AiProfile;
use App\Models\Document;
use App\Models\Product;
use App\Models\SyncRun;
use App\Sources\WebSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    seedCatalog();
});

it('una nota de conocimiento queda citable y la nueva versión reemplaza a la anterior', function () {
    $this->actingAs(userWith(Role::KNOWLEDGE_MANAGER))->post('/documents/notes', ['title' => 'Plazos de entrega', 'body' => 'Los pedidos de productos en stock se entregan entre 24 y 48 horas hábiles en AMBA.'])->assertRedirect();
    $a = app(AIOrchestrator::class)->answer('¿Cuál es el plazo de entrega de los pedidos en stock?', null, 'TEST', ['use_llm' => false]);
    expect($a->outcome)->toBe('ANSWERED')->and($a->text)->toContain('48 horas')->and(collect($a->citations)->pluck('label')->all())->toContain('MElectric · Plazos de entrega');

    app(DocumentIngestionService::class)->ingestText('Plazos de entrega', 'Desde diciembre, los pedidos en stock se entregan entre 24 y 72 horas hábiles.');
    expect(Document::query()->where('title', 'Plazos de entrega')->pluck('status')->sort()->values()->all())->toBe(['CURRENT', 'SUPERSEDED']);
});

it('incorpora un Word real y rechaza archivos disfrazados', function () {
    $docx = tempnam(sys_get_temp_dir(), 'w').'.docx';
    $zip = new ZipArchive;
    $zip->open($docx, ZipArchive::CREATE);
    $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Garantía extendida para tableros armados por distribuidores oficiales.</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $svc = app(DocumentIngestionService::class);
    $doc = $svc->ingestUpload(new UploadedFile($docx, 'garantia.docx', null, null, true));
    expect($doc->doc_type)->toBe('MANUAL')->and($doc->pagesText()->first()->text)->toContain('Garantía extendida');

    expect(fn () => $svc->ingestUpload(UploadedFile::fake()->createWithContent('falso.docx', 'esto no es un zip')))->toThrow(RuntimeException::class, 'firma ZIP');
    expect(fn () => $svc->ingestUpload(UploadedFile::fake()->createWithContent('binario.txt', "MZ\0\0\0binario")))->toThrow(RuntimeException::class, 'texto plano');
    $this->actingAs(userWith(Role::ADMIN))->post('/documents', ['file' => UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;')])->assertSessionHasErrors('file');
});

it('incorpora una instantánea de sincronización sin pisar el catálogo', function () {
    Http::fake(['*' => Http::response('', 404)]);
    $run = SyncRun::query()->create(['base_url' => 'https://www.melectric.com.ar', 'status' => 'RUNNING']);
    $run = app(WebSyncService::class)->ingestSnapshot($run, [
        'pages' => [
            ['url' => 'https://www.melectric.com.ar/Home/Faq', 'text' => 'Garantía de los productos: la mayoría cuenta con 12 meses a partir de la compra.', 'products' => [
                ['name' => 'Interruptor', 'sku' => '194994', 'mpn' => 'HL-C32/3-HX', 'brand' => 'Eaton', 'description' => '', 'url' => '', 'image' => ''],
                ['name' => 'Producto nuevo', 'sku' => 'X-1', 'mpn' => 'NUEVO-123', 'brand' => 'Eaton', 'description' => '', 'url' => '', 'image' => ''],
            ]],
        ],
        'pdfs' => [['url' => 'https://www.melectric.com.ar/Content/Catalogos/roto.pdf']],
        'out_of_scope' => [['url' => 'https://www.melectric.com.ar/Galeria', 'reason' => 'redirige a login']],
        'errors' => [],
    ]);
    expect($run->status)->toBe('PARTIAL')->and($run->stats['pages_new'])->toBe(1)->and($run->stats['products_matched'])->toBe(1)
        ->and($run->stats['products_unmatched'])->toBe(1)->and($run->stats['unmatched_sample'])->toBe(['NUEVO-123'])->and($run->stats['pdfs_failed'])->toBe(1)
        ->and(Product::query()->where('reference', 'NUEVO-123')->exists())->toBeFalse();
    expect(fn () => app(WebSyncService::class)->start('https://evil.example.com', null))->toThrow(RuntimeException::class, 'no está habilitado');
})->skip(fn () => gethostbynamel('www.melectric.com.ar') === false, 'Sin DNS: el guardia SSRF necesita resolver el host');

it('el redactor recibe las instrucciones internas y un modelo no aprobado no redacta para clientes', function () {
    $fake = new FakeAIProvider;
    app()->instance(ProviderManager::class, new class($fake) extends ProviderManager
    {
        public function __construct(private FakeAIProvider $p) {}

        public function resolve(?string $name = null): AIProviderInterface
        {
            return $this->p;
        }

        public function isLive(?string $name = null): bool
        {
            return true;
        }
    });
    app(AIOrchestrator::class)->answer('interruptor curva C 32A 3 polos', null, 'CHAT', ['use_llm' => true]);
    expect($fake->requests)->toHaveCount(1)->and($fake->requests[0]->system)->toContain('INSTRUCCIONES INTERNAS')->toContain('Sos el redactor');

    config(['melectric.ai.provider' => 'ollama', 'melectric.ai.writer_providers' => ['anthropic']]);
    AiProfile::query()->update(['provider' => 'ollama']);
    $a = app(AIOrchestrator::class)->answer('interruptor curva C 32A 3 polos', null, 'CHAT', ['use_llm' => true]);
    expect($a->trace['llm']['used'])->toBeFalse()->and($fake->requests)->toHaveCount(1);
});
