<?php

namespace App\Actions\Media;

use App\Catalog\Contracts\SupportsEvents;
use App\Catalog\Events\EventStore;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderRejected;

/**
 * Asks every provider that lists events for those ahead and those just past —
 * a provider names an event's titles only after it aired — and keeps them.
 */
final readonly class RefreshEvents
{
    /** Days back an event is asked about again, for the titles it showed. */
    public const RECENT_DAYS = 14;

    public function __construct(
        private ProviderRegistry $providers,
        private EventStore $store,
    ) {}

    /** @return array{events: int, failed: list<string>} how many events were kept, and the providers that did not answer */
    public function execute(): array
    {
        $kept = 0;
        $failed = [];

        foreach ($this->providers->allSupporting(SupportsEvents::class) as $code => $provider) {
            if (! $provider instanceof SupportsEvents) {
                continue;
            }

            try {
                $events = $provider->events(today()->subDays(self::RECENT_DAYS)->toImmutable());
            } catch (ProviderException|ProviderRejected) {
                $failed[] = $code;

                continue;
            }

            $this->store->store($code, $events);
            $kept += count($events);
        }

        return ['events' => $kept, 'failed' => $failed];
    }
}
