<?php

namespace App\Integrations\Anime;

use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Data\CredentialField;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\ManifestCategory;
use App\Integrations\Import\Rows\ImportRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Support\ExternalServiceException;
use Generator;

/**
 * A public anime/manga list read by username — MyAnimeList, AniList, Kitsu.
 * They differ only in how the list is fetched; what an entry means is
 * AnimeListRows' business. The list is fetched once per run.
 */
abstract class AnimeListSource implements ExplainsSetup, ReadsCredentials
{
    /** @var array{username: string, entries: list<AnimeListEntry|SkipRow>}|null */
    private ?array $fetched = null;

    public function __construct(private readonly AnimeListRows $translate) {}

    /**
     * Every entry of the user's anime and manga lists; an entry the tracker
     * itself cannot tie to a MyAnimeList id comes back as the reason why.
     *
     * @return list<AnimeListEntry|SkipRow>
     *
     * @throws ExternalServiceException when the list cannot be had: out of reach, refused, or not there
     */
    abstract protected function fetch(string $username): array;

    public function credentialsSchema(): array
    {
        return [new CredentialField('username', __('common.username'))];
    }

    public function analyze(ImportInput $input): ImportManifest
    {
        try {
            $entries = $this->entries($input);
        } catch (ExternalServiceException $e) {
            // Whichever reason, the list cannot be had now; the reason says which.
            return ImportManifest::invalid($e->wording());
        }

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
     * @return list<AnimeListEntry|SkipRow>
     */
    private function entries(ImportInput $input): array
    {
        $username = $input->credential('username');

        if ($this->fetched === null || $this->fetched['username'] !== $username) {
            $this->fetched = ['username' => $username, 'entries' => $this->fetch($username)];
        }

        return $this->fetched['entries'];
    }
}
