<?php

namespace App\Actions\Media;

use App\Catalog\Creators\CreatorPages;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Creators\Creators;
use App\Catalog\Creators\CreatorsFromLibrary;
use App\Catalog\Data\CreatorPage;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MatureChoices;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Models\Company;
use App\Models\Person;
use Illuminate\Support\Facades\Log;

/**
 * The details behind a person, studio or network page: the provider first,
 * the stored copy when the provider fails. Only a creator that IS in the
 * library falls back — one nobody touched has no row, so its failure stays a
 * failure. Null when the provider serves no such page at all.
 */
final readonly class ResolveCreatorPage
{
    public function __construct(
        private CreatorPages $pages,
        private Creators $creators,
        private CreatorsFromLibrary $library,
    ) {}

    public function execute(CreatorRef $creator, string $locale, MatureChoices $mature): ?CreatorPage
    {
        if (! $this->pages->serves($creator)) {
            return null;
        }

        try {
            return new CreatorPage($this->pages->page($creator, $locale, $mature));
        } catch (ItemNotFoundException|ProviderException|ProviderRejected $e) {
            $row = $this->creators->find($creator);

            if (! $row instanceof Person && ! $row instanceof Company) {
                throw $e;
            }

            Log::warning("Provider unavailable; [{$creator->key()}] served from the library.", ['exception' => $e::class]);

            return new CreatorPage($row instanceof Person ? $this->library->person($row) : $this->library->company($row), fromLibrary: true);
        }
    }
}
