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
        Schema::create('issue_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_by')->constrained('users');
            $table->string('title')->nullable();
            $table->timestamps();

            $table->index('issue_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issue_conversations');
    }
};
