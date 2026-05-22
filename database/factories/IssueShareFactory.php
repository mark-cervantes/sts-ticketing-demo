<?php

namespace Database\Factories;

use App\Enums\Permission;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueShare>
 */
class IssueShareFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'issue_id' => Issue::factory(),
            'user_id' => User::factory(),
            'permission' => Permission::View,
        ];
    }

    // -------------------------------------------------------------------------
    // Permission states
    // -------------------------------------------------------------------------

    /** Generic permission setter. */
    public function permission(Permission $permission): static
    {
        return $this->state(fn (array $attributes) => [
            'permission' => $permission,
        ]);
    }

    /** View-only permission. */
    public function view(): static
    {
        return $this->permission(Permission::View);
    }

    /** Comment permission. */
    public function comment(): static
    {
        return $this->permission(Permission::Comment);
    }

    /** Edit permission. */
    public function edit(): static
    {
        return $this->permission(Permission::Edit);
    }
}
