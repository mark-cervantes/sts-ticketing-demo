<?php

namespace Database\Factories;

use App\Models\IssueStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueStatus>
 */
class StatusFactory extends Factory
{
    /** @var class-string<IssueStatus> */
    protected $model = IssueStatus::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'color' => fake()->safeHexColor(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_default' => false,
        ];
    }

    /** Mark this status as the default. */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
