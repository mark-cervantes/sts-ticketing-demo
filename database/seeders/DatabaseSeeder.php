<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DatabaseSeeder orchestrates all seeders in dependency order.
 *
 * IMPORTANT: WithoutModelEvents MUST NOT be used here.
 * Both Issue::saving (needs_attention computation) and Category::creating
 * (slug auto-generation) depend on model events firing during seeding.
 *
 * Order: Category → User → Issue → Comment → IssueShare
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Wrapped in a transaction so partial re-seeds are atomic.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call([
                CategorySeeder::class,
                UserSeeder::class,
                IssueSeeder::class,
                CommentSeeder::class,
                IssueShareSeeder::class,
            ]);
        });
    }
}
