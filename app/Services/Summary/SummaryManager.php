<?php

namespace App\Services\Summary;

use App\Contracts\SummaryGeneratorInterface;
use App\Models\AiSetting;
use App\Services\Summary\Drivers\LlmDriver;
use App\Services\Summary\Drivers\RulesDriver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Manager;

/**
 * Manages summary generation drivers.
 *
 * Extends Illuminate\Support\Manager for standard driver resolution.
 *
 * Driver resolution order (ADR-002):
 *  1. Read AiSetting::current() from DB.
 *  2. Populate config values dynamically so LlmDriver reads the right values.
 *  3. Auto-fallback: if effective driver is 'llm' but api_key is absent, use 'rules'.
 *
 * Config-time auto-fallback is preserved: if the DB row has no api_key and the
 * provider is not 'rules', getDefaultDriver() still returns 'rules' transparently.
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
     * Reads AiSetting::current() from DB and overwrites config so all drivers
     * pick up the correct provider/key/model without knowing about AiSetting.
     *
     * SRS §7.2 line 353: if effective driver is 'llm' but api_key is absent or
     * empty, silently return 'rules' so the job never needs to handle this case.
     */
    public function getDefaultDriver(): string
    {
        $settings = AiSetting::current();

        // Push DB values into config so LlmDriver reads correct values.
        config([
            'summary.default' => $settings->effective_driver,
            'summary.drivers.llm.base_url' => $settings->effective_base_url,
            'summary.drivers.llm.api_key' => $settings->api_key,
            'summary.drivers.llm.model' => $settings->model,
        ]);

        $configured = $settings->effective_driver;

        if ($configured === 'llm' && empty($settings->api_key)) {
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
