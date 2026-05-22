<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @see SPEC §4.2 / §4.6 / BR-01 / BR-03 / BR-05 / ADR-005
     */
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('priority');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('open');
            $table->string('visibility')->default('private');
            $table->text('summary')->nullable();
            $table->text('suggested_next_action')->nullable();
            $table->string('summary_status')->default('pending');
            $table->boolean('needs_attention')->default(false);
            $table->timestamp('deadline_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Single-column indexes per SPEC §4.6 (user_id + category_id get FK indexes automatically)
            $table->index('status');
            $table->index('priority');
            $table->index('visibility');

            // Composite indexes per SPEC §4.6
            $table->index(['status', 'priority']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
