<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Database\Seeder;

class IssueShareSeeder extends Seeder
{
    /**
     * Seed 3 issue shares with varying permission levels.
     *
     * Rules:
     * - Shared user must NOT be the issue owner.
     * - Unique (issue_id, user_id) constraint respected via firstOrCreate.
     * - Covers all 3 Permission levels: View, Comment, Edit.
     */
    public function run(): void
    {
        $users = User::all();
        $issues = Issue::all();

        // Take 3 distinct issues for sharing.
        $selectedIssues = $issues->shuffle()->take(3);

        $permissions = [Permission::View, Permission::Comment, Permission::Edit];

        foreach ($selectedIssues->values() as $index => $issue) {
            // Pick a random user who is NOT the issue owner.
            $sharedWith = $users
                ->where('id', '!=', $issue->user_id)
                ->values()
                ->random();

            IssueShare::firstOrCreate(
                [
                    'issue_id' => $issue->id,
                    'user_id' => $sharedWith->id,
                ],
                [
                    'permission' => $permissions[$index],
                ]
            );
        }

        $this->command->info('IssueShareSeeder: seeded '.IssueShare::count().' issue shares.');
    }
}
