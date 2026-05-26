<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the old issues.status string column and rebuild indexes using status_id.
     *
     * Drops: single-column 'status' index, composite [status, priority], [user_id, status]
     * Recreates: [status_id, priority], [user_id, status_id]
     *
     * @see task 08.01 / Architecture Note 1 / database/migrations/2026_05_22_194119_create_issues_table.php
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            // Drop old composite indexes that reference the 'status' string column
            $table->dropIndex(['status', 'priority']);
            $table->dropIndex(['user_id', 'status']);

            // Drop the single-column status index
            $table->dropIndex(['status']);

            // Drop the old string column
            $table->dropColumn('status');

            // Recreate composite indexes using status_id
            $table->index(['status_id', 'priority']);
            $table->index(['user_id', 'status_id']);
        });
    }

    /**
     * Reverse the migration (restore old string column + old indexes).
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            // Remove new indexes
            $table->dropIndex(['status_id', 'priority']);
            $table->dropIndex(['user_id', 'status_id']);

            // Restore old string column
            $table->string('status')->default('open')->after('category_id');

            // Restore old indexes
            $table->index('status');
            $table->index(['status', 'priority']);
            $table->index(['user_id', 'status']);
        });
    }
};
