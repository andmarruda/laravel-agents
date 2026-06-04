<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_memories', function (Blueprint $table) {
            $table->id();
            $table->string('agent');
            $table->string('scope');
            $table->string('key');
            $table->json('value');
            $table->timestamps();

            $table->unique(['agent', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_memories');
    }
};
