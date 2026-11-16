<?php

namespace App\Catalog\Providers\Mal;

use App\Catalog\Data\MediaSummary;
use App\Catalog\DatePrecision;
use App\Catalog\Matching;
use App\Catalog\ProviderException;
use App\Catalog\TitleStatus;
use App\Support\Payload;

/**
 * MAL's manga payload read into the catalog's shapes: a manga's card from a
 * listing or a details reply, its names, dates, authors, cover and status.
 * The provider asks; this reads what came back.
 */
final readonly class MalPayload
{
    /** MAL media_type values that are prose, not comics. */
    private const NOVEL_TYPES = ['novel', 'light_novel'];

    /**
     * Summaries out of the entries of a MAL list (`[].node`): novels drop out
     * (books module territory), adult titles too, and covers are required.
     *
     * @param  list<array<array-key, mixed>>  $entries
     * @return list<MediaSummary>
     */
    public function cards(array $entries): array
    {
        $summaries = [];

        foreach ($entries as $entry) {
            $node = Payload::map($entry['node'] ?? null);

            if (Payload::id($node['id'] ?? null) === null || $this->pictureUrl(Payload::map($node['main_picture'] ?? null)) === null) {
                continue;
            }

            if (in_array($node['media_type'] ?? '', self::NOVEL_TYPES, true) || $this->isAdult($node)) {
                continue;
            }

            $summaries[] = $this->card($node);
        }

        return $summaries;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function card(array $data): MediaSummary
    {
        $releaseDate = $this->paddedDate($data, 'start_date');
        $title = $this->shownTitle($data);

        return new MediaSummary(
            type: 'manga',
            ref: Payload::id($data['id'] ?? null) ?? throw ProviderException::missingId('MyAnimeList'),
            provider: MalProvider::CODE,
            title: $title,
            year: Payload::yearOf($releaseDate),
            posterPath: $this->pictureUrl(Payload::map($data['main_picture'] ?? null)),
            communityRating: (($mean = Payload::float($data, 'mean') ?? 0.0) > 0) ? round($mean, 1) : null,
            releaseDate: $releaseDate,
            releasePrecision: $this->datePrecision($data, 'start_date'),
            extra: array_filter(['chapters' => Payload::positiveInt($data, 'num_chapters')]),
            progressTotal: Payload::positiveInt($data, 'num_chapters'),
            authors: $this->authors($data),
            alternativeTitles: $this->otherTitles($data, $title),
        );
    }

    /**
     * The name readers know the manga by: MAL's English title where it has
     * one, else MAL's own, usually romanised Japanese ("Shingeki no Kyojin").
     * MAL names a manga in no other language, so a reader of any language
     * gets the English one, as a title without their own version does on
     * every type.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function shownTitle(array $data): string
    {
        return Payload::string(Payload::map($data['alternative_titles'] ?? null), 'en') ?? Payload::text($data, 'title');
    }

    /**
     * The title in the manga's own script. MAL keeps it under `ja` whatever
     * the language: a manhwa's Korean title sits there too.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function nativeTitle(array $data): ?string
    {
        return Payload::string(Payload::map($data['alternative_titles'] ?? null), 'ja');
    }

    /**
     * Every other name MAL knows the manga by, for placing it by name: its own
     * title, the native one and the synonyms, each once, none that is the
     * shown one written another way.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function otherTitles(array $data, string $shown): array
    {
        $candidates = [
            Payload::string($data, 'title'),
            $this->nativeTitle($data),
            ...Payload::texts(Payload::map($data['alternative_titles'] ?? null)['synonyms'] ?? null),
        ];
        $titles = [];

        foreach ($candidates as $name) {
            if ($name === null || Matching::sameTitle($name, $shown)) {
                continue;
            }

            if (! in_array($name, $titles, true) && array_filter($titles, fn (string $kept): bool => Matching::sameTitle($kept, $name)) === []) {
                $titles[] = $name;
            }
        }

        return $titles;
    }

    /**
     * Author display names ("First Last"), deduplicated.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function authors(array $data): array
    {
        $names = [];

        foreach (Payload::list($data['authors'] ?? null) as $author) {
            $name = $this->authorName($author);

            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Each author's parts by name, for an author MAL says something about.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, list<value-of<MangaAuthorRole>>>
     */
    public function authorRoles(array $data): array
    {
        $roles = [];

        foreach (Payload::list($data['authors'] ?? null) as $author) {
            $name = $this->authorName($author);
            $parts = array_map(fn (MangaAuthorRole $role): string => $role->value, MangaAuthorRole::fromMal(Payload::string($author, 'role')));

            if ($name !== '' && $parts !== []) {
                $roles[$name] = array_values(array_unique([...$roles[$name] ?? [], ...$parts]));
            }
        }

        return $roles;
    }

    /**
     * An author's display name ("First Last"), empty when MAL names nobody.
     *
     * @param  array<array-key, mixed>  $author
     */
    private function authorName(array $author): string
    {
        $node = Payload::map($author['node'] ?? null);

        return trim(Payload::text($node, 'first_name').' '.Payload::text($node, 'last_name'));
    }

    /**
     * MAL dates may be a full date, year-month or bare year — pad the
     * short forms so the app always sees Y-m-d.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function paddedDate(array $data, string $key): ?string
    {
        $date = Payload::string($data, $key);

        return match (true) {
            $date === null => null,
            strlen($date) === 4 => "{$date}-01-01",
            strlen($date) === 7 => "{$date}-01",
            default => $date,
        };
    }

    /**
     * How much of a date MAL knows: "1989" is a year, so the page writes the
     * year rather than the 1 January it is padded to.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function datePrecision(array $data, string $key): DatePrecision
    {
        return match (strlen(Payload::text($data, $key))) {
            4 => DatePrecision::Year,
            7 => DatePrecision::Month,
            default => DatePrecision::Day,
        };
    }

    /**
     * Whether MAL rates the manga adult material. A rating MAL did not send,
     * or one it adds later, is not taken for it.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function isAdult(array $data): bool
    {
        return Payload::enum($data, 'nsfw', MangaNsfw::class)?->isAdult() ?? false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function format(array $data): ?string
    {
        // A code the page names in the reader's language, not a label.
        return Payload::enum($data, 'media_type', MangaFormat::class)?->value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function publicationStatus(array $data): ?TitleStatus
    {
        return match ($data['status'] ?? null) {
            'finished' => TitleStatus::Finished,
            'currently_publishing' => TitleStatus::Publishing,
            'not_yet_published' => TitleStatus::NotYetPublished,
            'on_hiatus' => TitleStatus::OnHiatus,
            'discontinued' => TitleStatus::Discontinued,
            default => null,
        };
    }

    /**
     * Cover URL out of a main_picture / pictures object, largest size first.
     *
     * @param  array<array-key, mixed>  $picture
     */
    public function pictureUrl(array $picture): ?string
    {
        $url = $picture['large'] ?? $picture['medium'] ?? null;

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }
}
