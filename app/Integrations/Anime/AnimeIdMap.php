<?php

namespace App\Integrations\Anime;

use App\Integrations\Import\Rows\SkipRow;
use App\Support\Payload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * MyAnimeList id → TVDB series + season / IMDb id, from the community map
 * Kometa-Team/Anime-IDs (MIT). No anime tracker knows TMDB ids, and we track
 * anime as TMDB shows and films, so this is the bridge — both ways: an
 * export asks it the other way round, from the MAL ids an import would place.
 *
 * Pinned to one revision with its SHA-256: a new revision
 * is a deliberate change to this file, never a silent download. The verified
 * file is kept on the local disk; when the pinned one cannot be fetched, the
 * last verified copy of any revision still serves.
 *
 * Measured on this revision: 16 997 entries, 13 667 with a MAL id, 59 % of
 * those with a TVDB or IMDb id. The map carries no TMDB ids at all.
 */
final class AnimeIdMap
{
    public const REVISION = 'cd6ea753e447584459fed5c10c9fa9532152ed12';

    public const SHA256 = '4f17b84b38c563559355b9975ada101b65c3bcd760ebe30ea2b1ccd0a25e9ea8';

    private const DIRECTORY = 'anime-ids';

    private const FETCH_FAILED = 'anime-ids:fetch-failed';

    /** How long a failed fetch of the pinned revision is not tried again, in seconds. */
    private const FETCH_RETRY_AFTER = 60 * 60;

    /** @var array<int, AnimeMapping>|null MAL id => mapping, once loaded */
    private ?array $byMal = null;

    /** @var array{seasons: array<int, array<int, list<MalSeasonPart>>>, films: array<string, int>}|null */
    private ?array $reverse = null;

    public function __construct(private readonly AnimeIdMapClient $client) {}

    public function forMal(int $malId): ?AnimeMapping
    {
        return $this->index()[$malId] ?? null;
    }

    public function isAvailable(): bool
    {
        return $this->index() !== [];
    }

    /**
     * Where a MyAnimeList entry sits, or why it cannot be placed: the map
     * could not be loaded, or it holds no such entry. The skip is labelled
     * with what the source calls the entry.
     */
    public function place(int $malId, string $label): AnimeMapping|SkipRow
    {
        $mapping = $this->forMal($malId);

        return match (true) {
            $mapping !== null => $mapping,
            ! $this->isAvailable() => new SkipRow('import.skip_anime_map_unavailable', ['label' => $label]),
            default => new SkipRow('import.skip_anime_not_in_map', ['label' => $label]),
        };
    }

    /**
     * The MAL entries a TVDB series is split into, by season number, each
     * season's parts in episode order.
     *
     * @return array<int, list<MalSeasonPart>>
     */
    public function seasonPartsOf(int $tvdbId): array
    {
        return $this->reverse()['seasons'][$tvdbId] ?? [];
    }

    /** The MAL entry an IMDb id places as a film. */
    public function filmOf(string $imdbId): ?int
    {
        return $this->reverse()['films'][$imdbId] ?? null;
    }

    /**
     * The forward index turned round, so an export names exactly the entry an
     * import of it would place. A spot two MAL ids share (one AniDB entry
     * naming several, all at the same season and episode) belongs to neither:
     * which episodes are whose, the map does not say.
     *
     * @return array{seasons: array<int, array<int, list<MalSeasonPart>>>, films: array<string, int>}
     */
    private function reverse(): array
    {
        if ($this->reverse !== null) {
            return $this->reverse;
        }

        $seasons = [];
        $films = [];

        foreach ($this->index() as $malId => $mapping) {
            if ($mapping->isSeason()) {
                $seasons[$mapping->tvdbId][$mapping->tvdbSeason][$mapping->episodeOffset][] = $malId;
            } elseif (($imdb = $mapping->standaloneImdb()) !== null) {
                $films[$imdb][] = $malId;
            }
        }

        $parts = [];

        foreach ($seasons as $tvdbId => $bySeason) {
            foreach ($bySeason as $season => $byOffset) {
                ksort($byOffset);

                foreach ($byOffset as $offset => $malIds) {
                    if (count($malIds) === 1) {
                        $parts[$tvdbId][$season][] = new MalSeasonPart($malIds[0], $offset);
                    }
                }
            }
        }

        return $this->reverse = [
            'seasons' => $parts,
            'films' => array_map(fn (array $malIds): int => $malIds[0], array_filter($films, fn (array $malIds): bool => count($malIds) === 1)),
        ];
    }

    /**
     * @return array<int, AnimeMapping>
     */
    private function index(): array
    {
        if ($this->byMal !== null) {
            return $this->byMal;
        }

        $raw = $this->pinned() ?? $this->lastVerified();
        $data = $raw === null ? null : json_decode($raw, true);

        return $this->byMal = is_array($data) ? $this->build($data) : [];
    }

    /**
     * @param  array<mixed>  $data
     * @return array<int, AnimeMapping>
     */
    private function build(array $data): array
    {
        $index = [];

        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $mapping = new AnimeMapping(
                is_int($entry['tvdb_id'] ?? null) ? $entry['tvdb_id'] : null,
                is_int($entry['tvdb_season'] ?? null) ? $entry['tvdb_season'] : null,
                is_int($entry['tvdb_epoffset'] ?? null) ? $entry['tvdb_epoffset'] : 0,
                $this->ids($entry['imdb_id'] ?? null),
            );

            // Several MAL entries can share one AniDB entry; the first
            // AniDB entry naming a MAL id wins.
            foreach ($this->ids($entry['mal_id'] ?? null) as $malId) {
                if (ctype_digit($malId)) {
                    $index[(int) $malId] ??= $mapping;
                }
            }
        }

        return $index;
    }

    /**
     * Ids come as a number, a string, or a comma-separated string of several.
     *
     * @return list<string>
     */
    private function ids(mixed $value): array
    {
        if (is_int($value)) {
            return [(string) $value];
        }

        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $id) => $id !== ''));
    }

    /** The pinned revision, from disk or fetched once and verified. */
    private function pinned(): ?string
    {
        $disk = Storage::disk('local');
        $path = self::DIRECTORY.'/'.self::REVISION.'.json';

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        // A fetch that just failed is not tried again by every process for
        // the next while: each would wait out the timeouts, an import page
        // request among them, before the older copy stood in anyway.
        if (Cache::has(self::FETCH_FAILED)) {
            return null;
        }

        try {
            $body = $this->client->revision(self::REVISION);
        } catch (AnimeIdMapUnavailable $e) {
            // Not fatal: the last verified copy stands in, and without one
            // anime entries are reported as unplaceable, each by name.
            Log::warning('Anime id map could not be fetched.', ['reason' => $e->wording('en')]);
            Cache::put(self::FETCH_FAILED, true, self::FETCH_RETRY_AFTER);

            return null;
        }

        if (! hash_equals(self::SHA256, hash('sha256', $body))) {
            Log::warning('Anime id map rejected: not the pinned revision.');

            return null;
        }

        $disk->put($path, $body);

        return $body;
    }

    /** Any copy verified earlier — an older revision beats none at all. */
    private function lastVerified(): ?string
    {
        $disk = Storage::disk('local');
        $files = Payload::strings($disk->files(self::DIRECTORY));

        if ($files === []) {
            return null;
        }

        usort($files, fn (string $a, string $b) => $disk->lastModified($b) <=> $disk->lastModified($a));

        return (string) $disk->get($files[0]);
    }
}
