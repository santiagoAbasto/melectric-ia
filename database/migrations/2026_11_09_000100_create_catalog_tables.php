<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo con procedencia: cada dato técnico apunta a la fuente y al registro de origen
 * (hoja!fila del Excel o página del PDF). Las especificaciones son filas tipadas, no columnas fijas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 32)->default('viewer')->after('email');
            $t->boolean('is_active')->default(true)->after('role');
        });

        Schema::create('sources', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 32);            // EXCEL, PDF, WEB_PAGE, IMAGE_SET, ADMIN_ENTRY
            $t->string('access_level', 32);    // PUBLIC, ADMIN_UPLOAD, AUTHORIZED_PRIVATE, DERIVED, EXTERNAL_VENDOR
            $t->string('name');
            $t->string('uri', 1024)->nullable();
            $t->string('sha256', 64)->nullable()->index();
            $t->unsignedSmallInteger('authority')->default(50); // precedencia configurable (mayor gana)
            $t->timestamp('source_date')->nullable();          // fecha del contenido, no de la subida
            $t->timestamp('retrieved_at')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
        });

        Schema::create('source_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('source_id')->constrained()->cascadeOnDelete();
            $t->string('locator');             // "B2B!A42", "page:3", "url:/Home/Faq"
            $t->string('hash', 64);
            $t->json('payload');
            $t->timestamps();
            $t->unique(['source_id', 'locator']);
        });

        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('slug')->unique();
            $t->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('slug')->unique();
            $t->string('product_type', 48)->nullable(); // circuit_breaker, rcd, contactor, motor_protector…
            $t->string('rubro', 32)->nullable();        // Domiciliario | Industrial (filtro del Figma)
            $t->timestamps();
        });

        Schema::create('product_families', function (Blueprint $t) {
            $t->id();
            $t->foreignId('brand_id')->nullable()->constrained();
            $t->foreignId('category_id')->nullable()->constrained('product_categories');
            $t->string('name')->unique();      // agrupador B2B: "Contactores Serie DILM hasta 12A"
            $t->string('slug')->unique();
            $t->string('image_path')->nullable();
            $t->string('image_source')->nullable(); // nombre original del archivo (con su codificación rota)
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('internal_code', 32)->unique();   // "Código" del Excel
            $t->string('reference');                      // referencia tal cual: "DILM32-10(230V50HZ,240V60HZ)"
            $t->string('normalized_reference')->index();
            $t->string('base_reference')->nullable()->index(); // sin variante de bobina: "DILM32-10"
            $t->string('series', 32)->nullable()->index();      // DILM, XTCG, mMC6, NZMN2…
            $t->text('description')->nullable();
            $t->foreignId('brand_id')->nullable()->constrained();
            $t->foreignId('category_id')->nullable()->constrained('product_categories');
            $t->foreignId('family_id')->nullable()->constrained('product_families');
            $t->string('subfamily')->nullable();
            $t->string('status', 16)->default('active');
            $t->foreignId('source_record_id')->nullable()->constrained('source_records')->nullOnDelete();
            $t->string('content_hash', 64)->nullable();
            $t->timestamps();
            $t->index(['category_id', 'family_id']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products ADD FULLTEXT products_ft (reference, description)');
        }

        Schema::create('product_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('alias');
            $t->string('normalized')->index();
            $t->string('kind', 32);            // base_reference, catalog_reference, old_reference, ean…
            $t->foreignId('source_record_id')->nullable()->constrained('source_records')->nullOnDelete();
            $t->timestamps();
            $t->unique(['product_id', 'normalized', 'kind']);
        });

        Schema::create('specification_definitions', function (Blueprint $t) {
            $t->id();
            $t->string('key', 48)->unique();   // rated_current, poles, curve, sensitivity, motor_power_ac3…
            $t->string('label');
            $t->string('data_type', 16);       // QUANTITY, RANGE, ENUM, INTEGER, STRING, BOOLEAN
            $t->string('dimension', 24)->nullable(); // current, voltage, power, reactive_power, frequency…
            $t->string('canonical_unit', 8)->nullable();
            $t->boolean('safety_critical')->default(false);
            $t->timestamps();
        });

        Schema::create('product_specifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('definition_id')->constrained('specification_definitions');
            $t->string('raw_value');           // tal como aparece en la fuente: "Reg. 32-40 A"
            $t->decimal('value', 16, 6)->nullable();   // en unidad canónica
            $t->decimal('value_min', 16, 6)->nullable();
            $t->decimal('value_max', 16, 6)->nullable();
            $t->string('value_text')->nullable();     // ENUM/STRING: "C", "AC-3"
            $t->string('unit', 8)->nullable();        // unidad canónica (A, V, W, VAR, Hz…)
            $t->string('qualifier')->nullable();      // condición: "AC-3 380-400 V", "bobina"
            $t->foreignId('source_record_id')->nullable()->constrained('source_records')->nullOnDelete();
            $t->foreignId('document_id')->nullable();  // FK agregada en la migración de documentos
            $t->unsignedSmallInteger('page')->nullable();
            $t->string('extractor', 48);              // description_parser@1, contactor_table@1, admin
            $t->decimal('confidence', 4, 3);
            $t->string('status', 16)->default('EXTRACTED'); // EXTRACTED, NEEDS_REVIEW, VERIFIED, REJECTED
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['definition_id', 'value']);
            $t->index(['definition_id', 'value_text']);
            $t->index(['product_id', 'definition_id']);
        });

        Schema::create('product_relationships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('related_product_id')->nullable()->constrained('products')->cascadeOnDelete();
            $t->foreignId('related_family_id')->nullable()->constrained('product_families')->cascadeOnDelete();
            $t->string('type', 32);            // ACCESSORY_OF, COMPATIBLE_WITH, AUXILIARY_FOR, REPLACEMENT_FOR, PAIRS_WITH…
            $t->string('status', 16)->default('AUTO_EXTRACTED'); // AUTO_EXTRACTED, NEEDS_REVIEW, VERIFIED, REJECTED
            $t->string('evidence', 500);
            $t->foreignId('source_record_id')->nullable()->constrained('source_records')->nullOnDelete();
            $t->foreignId('document_id')->nullable();
            $t->unsignedSmallInteger('page')->nullable();
            $t->decimal('confidence', 4, 3);
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['product_relationships', 'product_specifications', 'specification_definitions', 'product_aliases',
            'products', 'product_families', 'product_categories', 'brands', 'source_records', 'sources'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'is_active']));
    }
};
