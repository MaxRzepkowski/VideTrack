<?php

namespace App\Actions\Media;

use App\Catalog\Data\TitleDetails;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderRejected;
use App\Catalog\ViewerRegion;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The details behind a title page, for every type: the provider first, the
 * stored copy when the provider fails.
 *
 * Only a title that IS in the library falls back — one the user never touched
 * has no row to serve, so its failure stays a failure (the original exception
 * is re-thrown untouched).
 */
final readonly class ResolveTitleDetails
{
    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
        private DetailsFromLibrary $library,
    ) {}

    public function execute(User $viewer, string $type, string $ref, string $locale): TitleDetails
    {
        $provider = $this->providers->forType($this->types->get($type));

        try {
            return new TitleDetails($provider->details($type, $ref, $locale, ViewerRegion::resolve($viewer, $locale)));
        } catch (ItemNotFoundException|ProviderException|ProviderRejected $e) {
            $item = MediaItem::query()
                ->where('provider', $provider->code())
                ->where('type', $type)
                ->where('provider_ref', $ref)
                ->with('children')
                ->first();

            if ($item === null) {
                throw $e;
            }

            Log::warning("Provider unavailable; [{$type}:{$ref}] served from the library.", ['exception' => $e::class]);

            return new TitleDetails(
                $this->library->execute($item),
                fromLibrary: true,
                removedAtSource: $e instanceof ItemNotFoundException,
            );
        }
    }
}
