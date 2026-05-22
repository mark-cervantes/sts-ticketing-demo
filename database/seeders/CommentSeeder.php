<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommentSeeder extends Seeder
{
    /**
     * Seed 2-4 comments per issue.
     *
     * Author is always a user different from the issue owner for realistic demo data.
     * Target: ≥30 total comments across all issues.
     */
    public function run(): void
    {
        $users = User::all();
        $issues = Issue::all();

        foreach ($issues as $issue) {
            // Pick 2-4 comments per issue.
            $commentCount = fake()->numberBetween(2, 4);

            // Users who can author comments (exclude issue owner).
            $potentialAuthors = $users->where('id', '!=', $issue->user_id)->values();

            for ($i = 0; $i < $commentCount; $i++) {
                $author = $potentialAuthors->random();

                Comment::factory()->create([
                    'issue_id' => $issue->id,
                    'user_id' => $author->id,
                    'body' => fake()->paragraph(fake()->numberBetween(1, 3)),
                ]);
            }
        }

        $this->command->info('CommentSeeder: seeded '.Comment::count().' comments across '.Issue::count().' issues.');
    }
}
