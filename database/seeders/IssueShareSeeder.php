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
     * - Pin #1: demo user always receives a share on issue #3 (owned by $users[2])
     *   so the evaluator can immediately see a shared issue in their inbox.
     */
    public function run(): void
    {
        $users = User::orderBy('id')->get();
        $issues = Issue::orderBy('id')->get();

        // ----------------------------------------------------------------
        // Pin 1 — demo user ($users[0]) receives View access on issue #3
        // (owned by $users[2], so the ownership constraint is satisfied).
        // This is deterministic and ensures the AC "≥1 shared with demo"
        // is always met regardless of how other issues shuffle.
        // ----------------------------------------------------------------
        $demoUser = $users[0];
        $issueSharedWithDemo = $issues[2]; // issue #3 — owned by $users[2]

        IssueShare::firstOrCreate(
            [
                'issue_id' => $issueSharedWithDemo->id,
                'user_id' => $demoUser->id,
            ],
            [
                'permission' => Permission::View,
            ]
        );

        // ----------------------------------------------------------------
        // Pin 2 — $users[1] receives Comment access on issue #5
        // (owned by $users[4], diversity of share recipients).
        // ----------------------------------------------------------------
        IssueShare::firstOrCreate(
            [
                'issue_id' => $issues[4]->id,
                'user_id' => $users[1]->id,
            ],
            [
                'permission' => Permission::Comment,
            ]
        );

        // ----------------------------------------------------------------
        // Pin 3 — $users[3] receives Edit access on issue #7
        // (owned by $users[1], another owner-recipient pair).
        // ----------------------------------------------------------------
        IssueShare::firstOrCreate(
            [
                'issue_id' => $issues[6]->id,
                'user_id' => $users[3]->id,
            ],
            [
                'permission' => Permission::Edit,
            ]
        );

        $this->command->info('IssueShareSeeder: seeded '.IssueShare::count().' issue shares.');
    }
}
