<?php

use App\AI\PromptRegistry;
use App\Documents\DocumentIngestionService;
use App\Enums\Role;
use App\Models\AiRule;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\KnowledgeConflict;
use App\Models\Message;
use App\Models\Product;
use App\Models\PromptDefinition;
use App\Sources\PublicWebsiteConnector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    seedCatalog();
});

it('procesa un catálogo PDF real: tablas, especificaciones con página, vínculos y conflictos', function () {
    $svc = app(DocumentIngestionService::class);
    $doc = $svc->process($svc->register(base_path('tests/Fixtures/contactores.pdf'), ['access_level' => 'PUBLIC']));
    expect($doc->pages)->toBe(6)->and($doc->stats['tables'])->toBeGreaterThan(5)->and($doc->stats['table_specs'])->toBeGreaterThan(0)
        ->and(collect($doc->processing_log)->pluck('step')->all())->toContain('pages', 'tables', 'table_specs', 'references', 'chunks', 'embeddings');

    $dilm32 = Product::query()->where('reference', 'DILM32-10(RDC24)')->firstOrFail();
    $fromTable = $dilm32->specifications()->where('extractor', 'contactor_table@1')->whereHas('definition', fn ($d) => $d->where('key', 'motor_power'))->firstOrFail();
    expect($fromTable->value)->toBe(15000.0)->and($fromTable->page)->toBe(3)->and($fromTable->document_id)->toBe($doc->id);

    // El catálogo dice 18 A para el DILM17 y el Excel 17 A: conflicto real detectado
    expect(KnowledgeConflict::query()->whereHas('product', fn ($q) => $q->where('reference', 'like', 'DILM17%'))->where('field', 'rated_current_ac3')->exists())->toBeTrue();

    // Mismo archivo otra vez: no duplica
    expect($svc->register(base_path('tests/Fixtures/contactores.pdf'))->id)->toBe($doc->id)->and(Document::query()->count())->toBe(1);
})->skip(fn () => ! is_file(base_path('tests/Fixtures/contactores.pdf')),
    'Falta tests/Fixtures/contactores.pdf: bajá «Contactores y Relevos Termicos.pdf» de melectric.com.ar/Blog (no se versiona).');

it('rechaza un archivo que dice ser PDF y no lo es', function () {
    $admin = userWith(Role::ADMIN);
    $fake = UploadedFile::fake()->createWithContent('catalogo.pdf', "<?php echo 'hola';");
    $this->actingAs($admin)->post('/documents', ['file' => $fake])->assertSessionHasErrors('file');
    expect(Document::query()->count())->toBe(0);
});

it('aplica permisos en el backend', function () {
    $this->get('/products')->assertRedirect('/login');
    $viewer = userWith(Role::VIEWER);
    $this->actingAs($viewer)->get('/products')->assertOk();
    $this->actingAs($viewer)->post('/documents', ['file' => UploadedFile::fake()->create('a.pdf', 10)])->assertForbidden();
    $this->actingAs(userWith(Role::SALES_AGENT))->post('/knowledge/specs/1', ['status' => 'VERIFIED'])->assertForbidden();
    $this->actingAs(userWith(Role::SALES_AGENT))->get('/ai')->assertForbidden();
});

it('no deja desactivar una regla de seguridad', function () {
    $rule = AiRule::query()->where('key', 'CONFLICTING_SOURCES_NO_DEFINITIVE')->firstOrFail();
    $this->actingAs(userWith(Role::AI_MANAGER))->post("/ai/rules/{$rule->id}", ['enabled' => false])->assertSessionHas('error');
    expect($rule->refresh()->enabled)->toBeTrue();
});

it('publica prompts sin sobrescribir y permite volver atrás', function () {
    $def = PromptDefinition::query()->where('key', 'answer_writer')->firstOrFail();
    $manager = userWith(Role::AI_MANAGER);
    $this->actingAs($manager)->post("/ai/prompts/{$def->id}", ['body' => 'v2 del redactor', 'change_note' => 'prueba'])->assertRedirect();
    $v2 = $def->versions()->where('version', 2)->firstOrFail();
    expect($v2->status)->toBe('DRAFT');
    $this->actingAs($manager)->post("/ai/prompts/versions/{$v2->id}/publish")->assertRedirect();
    expect(app(PromptRegistry::class)->published('answer_writer')->version)->toBe(2)->and($def->versions()->where('version', 1)->value('status'))->toBe('ARCHIVED');
    $this->actingAs($manager)->post('/ai/prompts/versions/'.$def->versions()->where('version', 1)->value('id').'/publish');
    expect(app(PromptRegistry::class)->published('answer_writer')->version)->toBe(1)
        ->and(AuditLog::query()->where('action', 'ai.prompt_published')->count())->toBe(2);
});

it('atiende el chat web y oculta al cliente las sugerencias internas', function () {
    $token = $this->postJson('/chat/start', ['name' => 'Juan', 'company' => 'Tableros SRL'])->assertOk()->json('token');
    $res = $this->postJson("/chat/{$token}/messages", ['body' => 'HL-C32/3-HX'])->assertOk()->json('messages');
    expect($res)->toHaveCount(2)->and($res[1]['sender'])->toBe('AI')->and($res[1]['citations'])->not->toBeEmpty()
        ->and($res[1])->not->toHaveKey('rules');
    $c = Conversation::query()->firstOrFail();
    Message::query()->create(['conversation_id' => $c->id, 'sender' => 'AI', 'type' => 'AI_SUGGESTION', 'body' => 'interno']);
    $all = $this->getJson("/chat/{$token}/messages")->json('messages');
    expect(collect($all)->pluck('body'))->not->toContain('interno');
    $this->getJson('/chat/'.str_repeat('x', 48).'/messages')->assertNotFound();
});

it('retiene la selección técnica de baja confianza hasta que ingeniería la aprueba', function () {
    $token = $this->postJson('/chat/start', ['name' => 'Ana'])->json('token');
    $this->postJson("/chat/{$token}/messages", ['body' => '¿Qué relé térmico corresponde a DILM32 para un motor de 20 A?'])->assertOk();
    $c = Conversation::query()->firstOrFail();
    $suggestion = $c->messages()->where('type', 'AI_SUGGESTION')->firstOrFail();
    expect($c->mode)->toBe('ENGINEERING_REVIEW')->and($suggestion->payload['pending_review'])->toBeTrue()
        ->and(collect($this->getJson("/chat/{$token}/messages")->json('messages'))->pluck('body')->implode(' '))->not->toContain('ZB32-24');

    $this->actingAs(userWith(Role::SALES_AGENT))->post("/inbox/{$c->id}/approve/{$suggestion->id}")->assertForbidden();
    $this->actingAs(userWith(Role::TECHNICAL_ENGINEER))->post("/inbox/{$c->id}/approve/{$suggestion->id}")->assertRedirect();
    expect(collect($this->getJson("/chat/{$token}/messages")->json('messages'))->pluck('body')->implode(' '))->toContain('ZB32-24')
        ->and(AuditLog::query()->where('action', 'engineering.solution_approved')->exists())->toBeTrue();
});

it('sincroniza el sitio público sin entrar a la zona con login', function () {
    Http::fake([
        '*/robots.txt' => Http::response(file_get_contents(base_path('tests/Fixtures/site/robots.txt'))),
        '*/Blog' => Http::response(file_get_contents(base_path('tests/Fixtures/site/Blog.html')), 200, ['Content-Type' => 'text/html; charset=utf-8']),
        '*/Home/Faq' => Http::response('<html><title>FAQ</title><body><h2>Garantía de los productos</h2><p>La mayoría cuenta con 12 meses a partir de la compra.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        '*/Home/Nosotros' => Http::response('', 302, ['Location' => '/?ReturnUrl=%2FHome%2FNosotros']),
        '*' => Http::response(file_get_contents(base_path('tests/Fixtures/site/home.html')), 200, ['Content-Type' => 'text/html']),
    ]);
    config(['melectric.crawler.delay_ms' => 0]);
    $stats = app(PublicWebsiteConnector::class)->crawl('https://www.melectric.com.ar', 6);
    expect($stats['new'])->toBeGreaterThan(1)->and(collect($stats['out_of_scope'])->implode(' '))->toContain('requiere login');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/Galeria') || str_contains($r->url(), '/Carrito'));
    expect(Document::query()->where('doc_type', 'WEB_PAGE')->where('title', 'like', '%Faq%')->exists())->toBeTrue();
    $again = app(PublicWebsiteConnector::class)->crawl('https://www.melectric.com.ar', 6);
    expect($again['new'])->toBe(0)->and($again['unchanged'])->toBeGreaterThan(1);
})->skip(fn () => gethostbynamel('www.melectric.com.ar') === false, 'Sin DNS: el guardia SSRF necesita resolver el host');
