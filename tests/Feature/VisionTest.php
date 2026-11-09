<?php

use App\AI\AIOrchestrator;
use App\Models\Conversation;
use App\Vision\OcrProviderInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => seedCatalog());

function png(): string
{
    $path = tempnam(sys_get_temp_dir(), 'img').'.png';
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

    return $path;
}

function fakeOcr(array $lines): void
{
    app()->bind(OcrProviderInterface::class, fn () => new class($lines) implements OcrProviderInterface
    {
        public function __construct(private array $lines) {}

        public function name(): string
        {
            return 'fake-ocr';
        }

        public function available(): bool
        {
            return true;
        }

        public function read(string $imagePath): array
        {
            return $this->lines;
        }
    });
}

it('foto con referencia legible: OCR → búsqueda exacta → producto con evidencia', function () {
    fakeOcr([['text' => 'EATON', 'confidence' => 1.0], ['text' => 'mRCM-40/4/003', 'confidence' => 1.0], ['text' => 'In = 40A', 'confidence' => 0.9]]);
    $a = app(AIOrchestrator::class)->answerImage(png());
    expect($a->outcome)->toBe('ANSWERED')->and($a->products[0]['reference'])->toBe('mRCM-40/4/003')
        ->and($a->text)->toContain('los valores eléctricos salen del catálogo')->and($a->trace['vision']['ocr_fields'][0]['value'])->toBe('40 A');
});

it('foto sin etiqueta legible: no afirma producto y pide otra foto', function () {
    fakeOcr([['text' => '5 13', 'confidence' => 0.3]]);
    $a = app(AIOrchestrator::class)->answerImage(png());
    expect($a->outcome)->toBe('ASKED_INPUT')->and($a->products)->toBe([])->and($a->text)->toContain('foto de frente');
});

it('referencia leída que no existe: no inventa un equivalente', function () {
    fakeOcr([['text' => 'mMC6-B1/3', 'confidence' => 1.0]]);
    $a = app(AIOrchestrator::class)->answerImage(png());
    expect($a->outcome)->toBe('ABSTAINED')->and($a->text)->toContain('mMC6-B1/3')->and($a->products)->toBe([]);
});

it('el chat rechaza archivos que no son imágenes aunque digan .jpg', function () {
    Storage::fake('local');
    $token = $this->postJson('/chat/start', ['name' => 'Foto'])->json('token');
    $fake = UploadedFile::fake()->createWithContent('etiqueta.jpg', '<svg onload="alert(1)"></svg>');
    $this->post("/chat/{$token}/images", ['image' => $fake], ['Accept' => 'application/json'])->assertStatus(422);
    expect(Conversation::query()->first()->messages()->count())->toBe(0);
});
