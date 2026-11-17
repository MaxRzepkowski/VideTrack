<?php

namespace App\Integrations\Anime;

use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportManifest;
use App\Integrations\Import\Rows\SkipRow;
use App\Support\ExternalServiceException;
use App\Support\Forms\CredentialField;

/**
 * A public anime/manga list read by username — MyAnimeList, AniList, Kitsu.
 * They differ only in how the list is fetched. The list is fetched once per
 * run.
 */
abstract class AnimeProfileSource extends AnimeListSource implements ReadsCredentials
{
    /** @var array{username: string, entries: list<AnimeListEntry|SkipRow>}|null */
    private ?array $fetched = null;

    /**
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

        return $this->manifest($entries);
    }

    protected function entries(ImportInput $input): array
    {
        $username = $input->credential('username');

        if ($this->fetched === null || $this->fetched['username'] !== $username) {
            $this->fetched = ['username' => $username, 'entries' => $this->fetch($username)];
        }

        return $this->fetched['entries'];
    }
}
