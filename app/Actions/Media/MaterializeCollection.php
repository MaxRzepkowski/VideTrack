<?php

namespace App\Actions\Media;

use App\Catalog\Contracts\SupportsCollections;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Creators\UnknownCreator;
use App\Catalog\ProviderRegistry;
use App\Models\MediaList;

/**
 * Resolves a provider collection to its local system list, creating it on
 * the first visit (positions persist on first interaction). An
 * existing list is served as-is — the scheduled task keeps it fresh.
 */
final readonly class MaterializeCollection
{
    public function __construct(
        private ProviderRegistry $providers,
        private SyncCollectionList $sync,
    ) {}

    public function execute(string $providerCode, string $ref, string $locale): MediaList
    {
        $existing = MediaList::query()
            ->whereNull('user_id')
            ->where('system_key', MediaList::collectionKey($providerCode, $ref))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $provider = $this->providers->find($providerCode);

        if (! $provider instanceof SupportsCollections) {
            throw UnknownCreator::of(new CreatorRef(CreatorKind::Collection, $providerCode, $ref));
        }

        return $this->sync->execute($providerCode, $provider->collection($ref, $locale), $locale)->list;
    }
}
