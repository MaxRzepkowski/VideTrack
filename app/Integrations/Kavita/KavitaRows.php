<?php

namespace App\Integrations\Kavita;

use App\Catalog\MediaKind;
use App\Enums\MediaUserStatus;
use App\Integrations\Import\Data\ImportPlay;
use App\Integrations\Import\Data\ImportRating;
use App\Integrations\Import\Data\ImportStatus;
use App\Integrations\Import\Data\MediaSubject;
use App\Integrations\Import\Data\TitleQuery;
use App\Integrations\Import\Rows\MediaRow;
use App\Integrations\Import\Rows\SkipRow;
use App\Library\PlayDating;
use App\Library\RatingScale;
use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * One series of a Kavita library (`SeriesDto`) with the user's reading, as
 * an import row of the type its library is made for.
 *
 * Every page read is a series finished — for a manga or a comic only once
 * its publication is over, since reading an ongoing series to its last
 * volume is catching up, not finishing. A finished series is read once, as
 * of the last reading (which moves on when it is opened again, so a second
 * run makes no second read). Anything else begun is in progress, with no
 * counter: a file's pages are no edition's. A rating comes over on our
 * scale.
 *
 * A series is placed by the id Kavita keeps for its type's catalogue
 * (MyAnimeList, ComicVine, Hardcover), else by its exact title.
 */
final class KavitaRows
{
    /**
     * @param  array<array-key, mixed>  $series
     * @param  bool  $publicationOver  whether Kavita lists the series among those whose publication is over
     */
    public function row(array $series, MediaKind $kind, bool $publicationOver): MediaRow|SkipRow|null
    {
        if ((Payload::int($series, 'pagesRead') ?? 0) <= 0) {
            return null;
        }

        $title = Payload::string($series, 'localizedName') ?? Payload::string($series, 'name');

        if ($title === null) {
            return new SkipRow('import.skip_kavita_no_title');
        }

        $finished = $this->readThrough($series) && ($kind === MediaKind::Book || $publicationOver);
        $stars = Payload::bool($series, 'hasUserRated') === true ? RatingScale::stars(Payload::float($series, 'userRating')) : null;
        $readAt = $this->lastRead($series);

        return new MediaRow(
            $this->subject($series, $kind, $title),
            plays: $finished ? [new ImportPlay($readAt, dating: PlayDating::Activity)] : [],
            rating: $stars === null ? null : new ImportRating($stars, $readAt),
            status: $finished ? null : new ImportStatus(MediaUserStatus::InProgress),
        );
    }

    /**
     * When the user last read in a series. Kavita writes its server's local
     * clock with no zone, so it reads in this instance's zone, the best guess
     * for a server the same user runs.
     *
     * @param  array<array-key, mixed>  $series
     */
    public function lastRead(array $series): ?CarbonImmutable
    {
        return Payload::instant($series['latestReadDate'] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $series
     */
    private function readThrough(array $series): bool
    {
        $pages = Payload::positiveInt($series, 'pages');

        return $pages !== null && (Payload::int($series, 'pagesRead') ?? 0) >= $pages;
    }

    /**
     * Whether Kavita keeps the series' id at its type's catalogue: such a
     * series is placed without a search, so it costs nothing to read again.
     *
     * @param  array<array-key, mixed>  $series
     */
    public function knowsCatalogueId(array $series, MediaKind $kind): bool
    {
        return $this->catalogue($series, $kind)[1] !== null;
    }

    /**
     * @param  array<array-key, mixed>  $series
     */
    private function subject(array $series, MediaKind $kind, string $title): MediaSubject
    {
        [$provider, $id] = $this->catalogue($series, $kind);

        return $id === null
            ? MediaSubject::external($kind, [], $title, byTitle: new TitleQuery($title, exactTitle: true))
            : MediaSubject::direct($kind->value, $provider, (string) $id, $title);
    }

    /**
     * The catalogue a series of this kind is placed in, and its id there.
     *
     * @param  array<array-key, mixed>  $series
     * @return array{string, int|null}
     */
    private function catalogue(array $series, MediaKind $kind): array
    {
        return match ($kind) {
            MediaKind::Manga => ['mal', Payload::positiveInt($series, 'malId')],
            MediaKind::Comic => ['comicvine', $this->comicVineVolume($series)],
            default => ['hardcover', Payload::positiveInt($series, 'hardcoverId')],
        };
    }

    /**
     * ComicVine's volume id, written with or without its `4050-` prefix.
     *
     * @param  array<array-key, mixed>  $series
     */
    private function comicVineVolume(array $series): ?int
    {
        $id = Payload::string($series, 'comicVineId');

        return $id !== null && preg_match('/^(?:4050-)?(\d+)$/', $id, $match) === 1 ? (int) $match[1] : null;
    }
}
