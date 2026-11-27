<?php

namespace App\Catalog;

/**
 * Within one run that asks a provider item by item, the providers turning
 * the instance's key away: they are asked no more, and the run says so once
 * per provider instead of once per item. Only a refused key stops the
 * asking; an outage may pass mid-run.
 */
final class KeyRefusals
{
    /** @var array<string, int> provider code => items left unasked */
    private array $skipped = [];

    public function __construct(private readonly ProviderHealth $health) {}

    /**
     * Whether to leave the item to $provider unasked; counted when so. A
     * provider seen refusing stays refused for the run, without asking the
     * cache again; one still answering is looked up each time, since its key
     * may be refused mid-run.
     */
    public function skips(string $provider): bool
    {
        if (! isset($this->skipped[$provider]) && $this->health->of($provider)->state !== ProviderState::KeyRejected) {
            return false;
        }

        $this->skipped[$provider] = ($this->skipped[$provider] ?? 0) + 1;

        return true;
    }

    /**
     * One line per provider left unasked, for the run's errors.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->skipped as $provider => $count) {
            $lines[] = "{$provider}: {$count} left unasked, it refuses the instance's key.";
        }

        return $lines;
    }
}
