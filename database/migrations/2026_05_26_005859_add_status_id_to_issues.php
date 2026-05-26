<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add nullable status_id FK to issues, populate from existing status string, then make NOT NULL.
     *
     * Uses raw SQL for data population — never Eloquent in migrations (anti-pattern;
     * models may reference enums that are being deleted).
     *
     * @see task 08.01 / Architecture Note 1 & 3
     */
    public function up(): void
    {
        // Step 1: add nullable FK column
        Schema::table('issues', function (Blueprint $table): void {
            $table->unsignedBigInteger('status_id')->nullable()->after('category_id');
            $table->foreign('status_id')->references('id')->on('statuses')->restrictOnDelete();
        });

        // Step 2: populate via raw UPDATE (no Eloquent — model casts may reference Status enum)
        DB::statement('UPDATE issues SET status_id = s.id FROM statuses s WHERE s.slug = issues.status');

        // Step 3: make NOT NULL now that data is populated
        Schema::table('issues', function (Blueprint $table): void {
            $table->unsignedBigInteger('status_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->dropForeign(['status_id']);
            $table->dropColumn('status_id');
        });
    }
};
