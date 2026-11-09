<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos con versiones, páginas, tablas (celdas con valor crudo y normalizado), fragmentos para búsqueda,
 * vínculos documento↔producto y conflictos de conocimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->string('family_key')->index();      // agrupa versiones del mismo documento
            $t->string('version_label')->nullable();
            $t->string('doc_type', 32)->default('CATALOG'); // CATALOG, DATASHEET, MANUAL, CERTIFICATE, PRICE_LIST
            $t->string('brand')->nullable();
            $t->string('status', 16)->default('CURRENT');   // CURRENT, SUPERSEDED, DRAFT, ARCHIVED, UNKNOWN
            $t->foreignId('supersedes_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->date('document_date')->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->string('disk', 16)->default('local');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 64);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64)->unique();
            $t->unsignedSmallInteger('pages')->default(0);
            $t->string('processing_status', 16)->default('QUEUED'); // QUEUED, PROCESSING, SUCCEEDED, FAILED, PARTIAL, NEEDS_REVIEW
            $t->json('processing_log')->nullable();
            $t->json('stats')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('document_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('page');
            $t->longText('text');
            $t->timestamps();
            $t->unique(['document_id', 'page']);
        });

        Schema::create('document_tables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('page');
            $t->unsignedSmallInteger('index_on_page');
            $t->string('title')->nullable();
            $t->json('header_lines');           // líneas de encabezado tal cual
            $t->unsignedSmallInteger('row_count');
            $t->unsignedSmallInteger('col_count');
            $t->string('extractor', 48);
            $t->decimal('confidence', 4, 3);
            $t->string('status', 16)->default('NEEDS_REVIEW'); // EXTRACTED, NEEDS_REVIEW, VERIFIED, REJECTED
            $t->timestamps();
            $t->index(['document_id', 'page']);
        });

        Schema::create('document_table_cells', function (Blueprint $t) {
            $t->id();
            $t->foreignId('table_id')->constrained('document_tables')->cascadeOnDelete();
            $t->unsignedSmallInteger('row');
            $t->unsignedSmallInteger('col');
            $t->string('raw_value');
            $t->decimal('normalized_value', 16, 6)->nullable();
            $t->string('unit', 8)->nullable();
            $t->unique(['table_id', 'row', 'col']);
        });

        Schema::create('table_footnotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('table_id')->constrained('document_tables')->cascadeOnDelete();
            $t->text('text');
        });

        Schema::create('document_chunks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('page');
            $t->string('kind', 16);             // TEXT, TABLE
            $t->foreignId('table_id')->nullable()->constrained('document_tables')->nullOnDelete();
            $t->text('content');
            $t->string('content_hash', 64);
            $t->timestamps();
            $t->index(['document_id', 'page']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE document_chunks ADD FULLTEXT document_chunks_ft (content)');
        }

        // Índice vectorial reconstruible: la fuente de verdad son document_chunks.
        Schema::create('chunk_embeddings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chunk_id')->constrained('document_chunks')->cascadeOnDelete();
            $t->string('model', 64);
            $t->unsignedSmallInteger('dimensions');
            $t->longText('vector');             // JSON float[] normalizado (norma 1)
            $t->string('content_hash', 64);
            $t->timestamps();
            $t->unique(['chunk_id', 'model']);
        });

        Schema::create('document_product_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('family_id')->nullable()->constrained('product_families')->nullOnDelete();
            $t->string('reference_raw');
            $t->unsignedSmallInteger('page');
            $t->foreignId('table_id')->nullable()->constrained('document_tables')->nullOnDelete();
            $t->string('link_method', 32);      // exact_reference, internal_code, base_reference, series_prefix
            $t->decimal('confidence', 4, 3);
            $t->boolean('verified')->default(false);
            $t->timestamps();
            $t->index(['product_id', 'document_id']);
        });

        Schema::create('knowledge_conflicts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('definition_id')->nullable()->constrained('specification_definitions');
            $t->string('field');
            $t->foreignId('spec_a_id')->nullable()->constrained('product_specifications')->nullOnDelete();
            $t->foreignId('spec_b_id')->nullable()->constrained('product_specifications')->nullOnDelete();
            $t->string('value_a');
            $t->string('source_a');
            $t->string('value_b');
            $t->string('source_b');
            $t->string('status', 16)->default('OPEN'); // OPEN, RESOLVED, DISMISSED
            $t->string('resolution')->nullable();      // A, B, NONE
            $t->text('resolution_note')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'status']);
        });

        Schema::table('product_specifications', fn (Blueprint $t) => $t->foreign('document_id')->references('id')->on('documents')->nullOnDelete());
        Schema::table('product_relationships', fn (Blueprint $t) => $t->foreign('document_id')->references('id')->on('documents')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('product_relationships', fn (Blueprint $t) => $t->dropForeign(['document_id']));
        Schema::table('product_specifications', fn (Blueprint $t) => $t->dropForeign(['document_id']));
        foreach (['knowledge_conflicts', 'document_product_links', 'chunk_embeddings', 'document_chunks', 'table_footnotes',
            'document_table_cells', 'document_tables', 'document_pages', 'documents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
