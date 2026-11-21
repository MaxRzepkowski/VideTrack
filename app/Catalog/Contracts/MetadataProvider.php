<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\MatureContent;

/**
 * Base contract every metadata provider must implement. The core depends on
 * this interface only — never on a concrete provider.
 * Optional capabilities are separate interfaces (ISP).
 */
interface MetadataProvider
{
    /** Provider code stored in `media_items.provider` (e.g. "tmdb"). */
    public function code(): string;

    /**
     * Free-text search across the provider's searchable types. `$mature` is
     * how far the results may go (SupportsMatureFilter); a provider that files
     * no adult material ignores it.
     *
     * @return list<MediaSummary>
     */
    public function search(string $query, string $locale, MatureContent $mature): array;

    /**
     * Full details of a single position; `$ref` is an opaque reference
     * previously returned by this provider.
     *
     * `$region` is the viewer's country (ISO 3166-1), for the picks that
     * depend on it, like the age rating on the title's chip. The core resolves
     * it, so the provider never sees a user; null means the locale's default
     * country (Locales::region()). Providers with nothing regional ignore it.
     */
    public function details(string $type, string $ref, string $locale, ?string $region = null): MediaDetails;
}
