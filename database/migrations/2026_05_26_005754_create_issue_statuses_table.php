<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the statuses table and seed the three default rows.
     *
     * Seeds must be in the migration (not just the StatusSeeder) so that
     * migration 2 can run UPDATE issues SET status_id = ... WHERE slug = issues.status.
     *
     * @see task 08.01 / SPEC §4.2 / SRS §FR-02
     */
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('color', 7)->default('#6b7280');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Seed the three default statuses so migration 2 can map slug → id
        DB::table('statuses')->insert([
            [
                'name' => 'Open',
                'slug' => 'open',
                'color' => '#22c55e',
                'sort_order' => 0,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'In Progress',
                'slug' => 'in_progress',
                'color' => '#3b82f6',
                'sort_order' => 1,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Resolved',
                'slug' => 'resolved',
                'color' => '#a855f7',
                'sort_order' => 2,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statuses');
    }
};
