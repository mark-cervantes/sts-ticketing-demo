<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @see SPEC §4.5 / §4.6 / ADR-007
     */
    public function up(): void
    {
        Schema::create('issue_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->timestamp('created_at')->nullable();
            // No updated_at per SPEC §4.5

            // Composite unique: one share record per user per issue
            $table->unique(['issue_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issue_shares');
    }
};
