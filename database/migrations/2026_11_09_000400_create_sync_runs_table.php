<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $t) {
            $t->id();
            $t->string('base_url');
            $t->string('status', 16)->default('QUEUED'); // QUEUED, RUNNING, SUCCEEDED, PARTIAL, FAILED
            $t->json('stats')->nullable();
            $t->json('log')->nullable();
            $t->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
