<?php

namespace Database\Seeders;

use App\Models\IssueStatus;
use Illuminate\Database\Seeder;

/**
 * Seed default workflow statuses.
 *
 * Uses firstOrCreate on slug to be idempotent (safe to re-run).
 * Must run before IssueSeeder because IssueFactory references these rows.
 *
 * @see task 08.01 / SPEC §4.2
 */
class StatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            [
                'slug' => 'open',
                'name' => 'Open',
                'color' => '#22c55e',
                'sort_order' => 0,
                'is_default' => true,
            ],
            [
                'slug' => 'in_progress',
                'name' => 'In Progress',
                'color' => '#3b82f6',
                'sort_order' => 1,
                'is_default' => false,
            ],
            [
                'slug' => 'resolved',
                'name' => 'Resolved',
                'color' => '#a855f7',
                'sort_order' => 2,
                'is_default' => false,
            ],
        ];

        foreach ($statuses as $data) {
            // firstOrCreate on slug to be idempotent; update other fields if found
            $status = IssueStatus::firstOrCreate(
                ['slug' => $data['slug']],
                $data,
            );

            // If already exists, sync name/color/sort_order/is_default
            if (! $status->wasRecentlyCreated) {
                $status->fill(array_diff_key($data, ['slug' => '']))->save();
            }
        }
    }
}
