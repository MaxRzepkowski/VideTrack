<?php

namespace App\Integrations\Import;

use App\Integrations\Import\Data\ImportListRef;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;

/**
 * The list a whole library from elsewhere comes over as — a launcher's
 * games, a catalog's books — named after the service it was kept in, and
 * the category of the selection that brings it. A source that puts only
 * part of its library there (games nobody played) names it the same way.
 */
final readonly class LibraryList
{
    public const KEY = 'library';

    public function __construct(private string $service) {}

    public function category(int $count): ManifestCategory
    {
        return new ManifestCategory(self::KEY, __('import.library_on_a_list', ['source' => $this->service]), $count);
    }

    public function isPicked(ImportSelection $selection): bool
    {
        return $selection->has(self::KEY);
    }

    public function ref(): ImportListRef
    {
        return new ImportListRef($this->service);
    }
}
