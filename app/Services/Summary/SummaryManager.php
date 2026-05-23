<?php

namespace App\Services\Summary;

use App\Contracts\SummaryGeneratorInterface;
use App\Services\Summary\Drivers\LlmDriver;
use App\Services\Summary\Drivers\RulesDriver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;

/**
 * Manages summary generation drivers.
 *
 * Extends Illuminate\Support\Manager for standard driver resolution.
 * Implements config-time auto-fallback: if the configured driver is 'llm'
 * but no API key is set, getDefaultDriver() returns 'rules' transparently.
 *
 * @see SRS §7.2
 *
 * @method SummaryGeneratorInterface driver(?string $driver = null)
 */
class SummaryManager extends Manager
{
    /**
     * Get the default driver name, applying the no-key auto-fallback rule.
     *
     * SRS §7.2 line 353: if SUMMARY_DRIVER=llm but LLM_API_KEY is absent or
     * empty, silently return 'rules' so the job never needs to handle this case.
     */
    public function getDefaultDriver(): string
    {
        $configured = (string) config('summary.default', 'rules');

        if ($configured === 'llm' && empty(config('summary.drivers.llm.api_key'))) {
            return 'rules';
        }

        return $configured;
    }

    /**
     * Create the LLM driver instance.
     */
    public function createLlmDriver(): SummaryGeneratorInterface
    {
        return new LlmDriver($this->container->make(HttpFactory::class));
    }

    /**
     * Create the rules (deterministic fallback) driver instance.
     */
    public function createRulesDriver(): SummaryGeneratorInterface
    {
        return new RulesDriver;
    }
}
