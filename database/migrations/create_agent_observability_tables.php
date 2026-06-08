<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_traces', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('name');
            $table->string('status', 32)->index();
            $table->text('status_message')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->decimal('duration_ms', 14, 3)->nullable();
            $table->json('attributes');
            $table->timestamps();
        });

        Schema::create('agent_spans', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('trace_id', 32)->index();
            $table->string('parent_id', 32)->nullable()->index();
            $table->string('name');
            $table->string('kind', 64)->index();
            $table->string('status', 32)->index();
            $table->text('status_message')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->decimal('duration_ms', 14, 3)->nullable();
            $table->json('attributes');
            $table->timestamps();

            $table->foreign('trace_id')->references('id')->on('agent_traces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_spans');
        Schema::dropIfExists('agent_traces');
    }
};
