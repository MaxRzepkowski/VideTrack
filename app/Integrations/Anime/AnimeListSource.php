<?php

namespace App\Integrations\Anime;

use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ImportSource;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Rows\ImportRow;
use App\Integrations\Import\Rows\SkipRow;
use Generator;

/**
 * An anime/manga list, however it reaches us. The sources differ only in
 * how they get the entries; what an entry means is AnimeListRows' business.
 */
abstract class AnimeListSource implements ExplainsSetup, ImportSource
{
    public function __construct(private readonly AnimeListRows $translate) {}

    /**
     * Every entry of the input; an entry the tracker itself cannot tie to a
     * MyAnimeList id comes back as the reason why.
     *
     * @return list<AnimeListEntry|SkipRow>
     */
    abstract protected function entries(ImportInput $input): array;

    /**
     * @return Generator<int, ImportRow>
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        foreach ($this->entries($input) as $entry) {
            if ($entry instanceof SkipRow) {
                yield $entry;
            } else {
                yield from $this->translate->for($entry);
            }
        }
    }

    /**
     * @param  list<AnimeListEntry|SkipRow>  $entries
     */
    protected function manifest(array $entries): ImportManifest
    {
        // Counted in rows, not entries: a watched season is a run of
        // episodes and a rating on the show — two ticks of the progress bar.
        $counts = ['anime' => 0, 'manga' => 0, 'unplaced' => 0];

        foreach ($entries as $entry) {
            if ($entry instanceof SkipRow) {
                $counts['unplaced']++;
            } else {
                $counts[$entry->medium->value] += count($this->translate->for($entry));
            }
        }

        return ImportManifest::whole([
            new ManifestCategory('anime', __('import.anime'), $counts['anime']),
            new ManifestCategory('manga', __('common.manga'), $counts['manga']),
            new ManifestCategory('unplaced', __('common.skipped'), $counts['unplaced']),
        ]);
    }
}
