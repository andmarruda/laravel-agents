<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_approvals', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('action')->index();
            $table->string('fingerprint', 64);
            $table->string('status', 32)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('metadata');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_approvals');
    }
};
