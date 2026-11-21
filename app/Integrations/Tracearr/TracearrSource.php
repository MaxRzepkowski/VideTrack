<?php

namespace App\Integrations\Tracearr;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Integrations\Connections\ConnectionExpired;
use App\Integrations\Connections\Data\PinnedAddress;
use App\Integrations\Connections\OwnServerRejected;
use App\Integrations\Connections\OwnServerUnavailable;
use App\Integrations\EpisodeLabel;
use App\Integrations\Import\ConnectionSource;
use App\Integrations\Import\Data\ImportInput;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportSelection;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Integrations\TitleLabel;
use App\Models\ServiceConnection;
use App\Support\Payload;
use Carbon\CarbonImmutable;
use Generator;

/**
 * The viewings Tracearr saw one person finish, read from its history
 * (GET /history, watched=true, that person's user id) — films and episodes;
 * music, live TV and photos have nowhere to go here. A viewing is a play at
 * the moment it stopped. Partial viewings stay out: we do not guess.
 *
 * The ids on an episode row are the episode's own (a TMDB episode id cannot
 * even be looked up). The show's ids come from GET /media/{show_media_id},
 * once per show per run; the episode is then placed by the show plus its
 * season and number — never by title.
 *
 * A viewing may already be here through the media server's own webhook, at
 * a slightly different time; plays are marked as scrobbled so the import
 * leaves that one viewing alone.
 *
 * The read position is `since`: one day before the last run started, so a
 * viewing Tracearr files late is still picked up; re-reading it is harmless.
 */
class TracearrSource extends ConnectionSource
{
    public const CODE = 'tracearr';

    /** @var array<string, array<string, string>> show media id => the show's ids, per run */
    private array $shows = [];

    public function __construct(private readonly TracearrClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Tracearr';
    }

    public function description(): string
    {
        return __('import.movies_and_episodes_your_media_server');
    }

    public function taskLabel(): string
    {
        return __('import.import_from_tracearr');
    }

    public function connectionService(): string
    {
        return TracearrService::CODE;
    }

    /**
     * Hourly: the user's own server, and the
     * next best thing to a webhook.
     */
    public function autoImportMinutes(): int
    {
        return 60;
    }

    /**
     * @throws OwnServerUnavailable|OwnServerRejected|ConnectionExpired
     */
    public function rows(ImportInput $input, ImportSelection $selection): Generator
    {
        $connection = $this->connection($input);

        if ($connection === null) {
            return null;
        }

        $address = $this->client->address($connection);

        $startedAt = CarbonImmutable::now();
        $query = array_filter([
            'user_id' => (string) $connection->setting('user_id'),
            'watched' => 'true',
            'since' => $connection->stateValue('since'),
        ], fn (?string $value): bool => $value !== null && $value !== '');
        $cursor = null;

        do {
            $page = $this->client->page($connection, $address, '/history', [...$query, ...($cursor === null ? [] : ['cursor' => $cursor])]);

            foreach ($page['data'] as $viewing) {
                $row = $this->row($connection, $address, $viewing);

                if ($row !== null) {
                    yield $row;
                }
            }

            $cursor = $page['nextCursor'];
        } while ($cursor !== null);

        return ['since' => $startedAt->subDay()->toIso8601ZuluString()];
    }

    /**
     * @param  array<array-key, mixed>  $viewing
     */
    private function row(ServiceConnection $connection, PinnedAddress $address, array $viewing): MediaRow|SkipRow|null
    {
        if (($viewing['watched'] ?? null) !== true) {
            return null;
        }

        $at = Payload::instant($viewing['stopped_at'] ?? null) ?? Payload::instant($viewing['started_at'] ?? null);
        $play = [new ImportPlay($at, scrobbled: true)];

        return match ($viewing['media_type'] ?? null) {
            'movie' => $this->movie($viewing, $play),
            'episode' => $this->episode($connection, $address, $viewing, $play),
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $viewing
     * @param  list<ImportPlay>  $play
     */
    private function movie(array $viewing, array $play): MediaRow|SkipRow
    {
        $ids = $this->ids($viewing);
        $label = $this->titleLabel($viewing['media_title'] ?? null, $viewing['year'] ?? null);

        if (isset($ids['tmdb'])) {
            return new MediaRow(MediaSubject::direct('movie', 'tmdb', $ids['tmdb'], $label), plays: $play);
        }

        $foreign = ExternalIdSource::only($ids, ExternalIdSource::Imdb, ExternalIdSource::Tvdb);

        return $foreign === []
            ? new SkipRow('import.skip_tracearr_no_ids', ['label' => $label])
            : new MediaRow(MediaSubject::external(MediaKind::Movie, $foreign, $label), plays: $play);
    }

    /**
     * @param  array<array-key, mixed>  $viewing
     * @param  list<ImportPlay>  $play
     */
    private function episode(ServiceConnection $connection, PinnedAddress $address, array $viewing, array $play): MediaRow|SkipRow
    {
        $season = $viewing['season_number'] ?? null;
        $number = $viewing['episode_number'] ?? null;
        $show = $this->titleLabel($viewing['show_title'] ?? null, null);
        $label = EpisodeLabel::of($show, is_int($season) ? $season : null, is_int($number) ? $number : null);

        // TMDB cannot look an episode up by its own TMDB id; imdb and tvdb it can.
        $own = ExternalIdSource::only($this->ids($viewing), ExternalIdSource::Imdb, ExternalIdSource::Tvdb);
        $series = is_string($viewing['show_media_id'] ?? null) ? $this->showIds($connection, $address, $viewing['show_media_id']) : [];

        if ($series !== [] && is_int($season) && is_int($number)) {
            return new MediaRow(
                isset($series['tmdb'])
                    ? MediaSubject::below('show', 'tmdb', $series['tmdb'], [$season, $number], $label)
                    : MediaSubject::external(MediaKind::Episode, $own, $label, ExternalIdSource::only($series, ExternalIdSource::Imdb, ExternalIdSource::Tvdb), $season, $number),
                plays: $play,
            );
        }

        return $own === []
            ? new SkipRow('import.skip_tracearr_no_episode_ids', ['label' => $label])
            : new MediaRow(MediaSubject::external(MediaKind::Episode, $own, $label), plays: $play);
    }

    /**
     * @return array<string, string>
     */
    private function showIds(ServiceConnection $connection, PinnedAddress $address, string $mediaId): array
    {
        if (! isset($this->shows[$mediaId])) {
            $media = $this->client->find($connection, $address, '/media/'.rawurlencode($mediaId));
            $this->shows[$mediaId] = is_array($media) ? $this->ids($media) : [];
        }

        return $this->shows[$mediaId];
    }

    /**
     * @param  array<mixed>  $object
     * @return array<string, string>
     */
    private function ids(array $object): array
    {
        $ids = [];

        foreach ([ExternalIdSource::Imdb->value => 'imdb_id', 'tmdb' => 'tmdb_id', ExternalIdSource::Tvdb->value => 'tvdb_id'] as $source => $field) {
            $id = Payload::id($object[$field] ?? null);

            if ($id !== null) {
                $ids[$source] = $id;
            }
        }

        return $ids;
    }

    private function titleLabel(mixed $title, mixed $year): string
    {
        $title = is_string($title) && $title !== '' ? $title : 'Tracearr item';

        return TitleLabel::of($title, is_int($year) ? $year : null);
    }
}
