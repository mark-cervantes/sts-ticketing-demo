<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('rules'); // 'rules', 'openrouter', 'ollama', 'custom'
            $table->string('base_url')->nullable();        // e.g., https://openrouter.ai/api/v1
            $table->text('api_key')->nullable();           // encrypted at model level
            $table->string('model')->nullable();           // e.g., google/gemini-2.5-flash
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
