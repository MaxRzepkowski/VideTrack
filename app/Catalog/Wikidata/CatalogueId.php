<?php

namespace App\Catalog\Wikidata;

use App\Catalog\ExternalIdSource;
use App\Catalog\Isbn;
use App\Catalog\MediaKind;
use App\Catalog\Relations\CatalogWork;

/**
 * An id Wikidata keeps of a work in one of the catalogues our providers read:
 * how a work Wikidata names is found in our catalogue, and how a title of
 * ours is found on Wikidata. Each case is also the query variable the id is
 * bound to.
 */
enum CatalogueId: string
{
    case Movie = 'movie';
    case Show = 'show';
    case Manga = 'manga';
    case Game = 'game';
    case Comic = 'comic';
    case Goodreads = 'goodreads';
    case Isbn = 'isbn';

    /** The kinds of screen work. */
    public const SCREEN = [self::Movie, self::Show];

    /** Comic Vine's prefix of a volume: its issues, characters and people carry others. */
    private const COMIC_VOLUME = '4050-';

    /** Whether a work of the kind is a film or a show. */
    public static function isScreen(MediaKind $kind): bool
    {
        return in_array($kind, array_map(fn (self $id): MediaKind => $id->kind(), self::SCREEN), true);
    }

    public function kind(): MediaKind
    {
        return match ($this) {
            self::Movie => MediaKind::Movie,
            self::Show => MediaKind::Show,
            self::Manga => MediaKind::Manga,
            self::Game => MediaKind::Game,
            self::Comic => MediaKind::Comic,
            self::Goodreads, self::Isbn => MediaKind::Book,
        };
    }

    /** The catalogue the id belongs to, as the id resolver reads it. */
    public function catalogue(): string
    {
        return match ($this) {
            self::Movie, self::Show => 'tmdb',
            self::Manga => 'mal',
            self::Game => 'igdb',
            self::Comic => 'comicvine',
            self::Goodreads => ExternalIdSource::Goodreads->value,
            self::Isbn => ExternalIdSource::Isbn->value,
        };
    }

    /**
     * The id that finds a title of this kind in this catalogue on Wikidata;
     * none for an ISBN, which Wikidata spells with hyphens a bare one cannot
     * be matched against.
     */
    public static function finding(MediaKind $kind, string $catalogue): ?self
    {
        foreach (self::cases() as $id) {
            if ($id !== self::Isbn && $id->kind() === $kind && $id->catalogue() === $catalogue) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The path from a work to the id. A book's ids are kept as often on its
     * editions as on the work itself.
     */
    private function path(): string
    {
        return match ($this) {
            self::Movie => 'wdt:P4947',
            self::Show => 'wdt:P4983',
            self::Manga => 'wdt:P4087',
            // The numeric id rides as a qualifier on the slug.
            self::Game => 'p:P5794/pq:P9043',
            self::Comic => 'wdt:P5905',
            self::Goodreads => 'wdt:P2969|wdt:P747/wdt:P2969',
            self::Isbn => 'wdt:P212|wdt:P747/wdt:P212',
        };
    }

    /**
     * The pattern binding `?{$item}` to the item that has `?{$value}` as
     * this id. A Goodreads id names an edition; the item is its work.
     */
    public function itemPattern(string $item, string $value): string
    {
        return match ($this) {
            self::Goodreads => "?edition wdt:P2969 ?{$value} . OPTIONAL { ?edition wdt:P629 ?work . } BIND(COALESCE(?work, ?edition) AS ?{$item})",
            default => "?{$item} {$this->path()} ?{$value} .",
        };
    }

    /**
     * The filter keeping only the values of the variable that are ours: an
     * item may carry Comic Vine's number of a character beside its volume's,
     * and the one sampled must be the volume.
     */
    private function only(string $variable): string
    {
        return $this === self::Comic ? 'FILTER(STRSTARTS('.$variable.', "'.self::COMIC_VOLUME.'")) ' : '';
    }

    /** How Wikidata writes our id. */
    public function wikidataValue(string $id): string
    {
        return $this === self::Comic ? self::COMIC_VOLUME.$id : $id;
    }

    /** Our id out of how Wikidata writes it; none for anything else it keeps under the property. */
    public function ourValue(string $value): ?string
    {
        if ($this === self::Isbn) {
            return Isbn::thirteen($value);
        }

        if ($this === self::Comic) {
            if (! str_starts_with($value, self::COMIC_VOLUME)) {
                return null;
            }

            $value = substr($value, strlen(self::COMIC_VOLUME));
        }

        return ctype_digit($value) ? $value : null;
    }

    /**
     * The variables to select, each one of the values bound to it with the
     * suffix, for a work with several: `(SAMPLE(?movie_any) AS ?movie) …`.
     *
     * @param  list<self>  $ids
     */
    public static function sampled(array $ids, string $suffix): string
    {
        return implode(' ', array_map(fn (self $id): string => "(SAMPLE(?{$id->value}{$suffix}) AS ?{$id->value})", $ids));
    }

    /**
     * The variables to select: `?movie ?show`.
     *
     * @param  list<self>  $ids
     */
    public static function variables(array $ids): string
    {
        return implode(' ', array_map(fn (self $id): string => "?{$id->value}", $ids));
    }

    /**
     * The optional patterns binding each id of the work `?{$work}` to the
     * case's own variable, with the suffix.
     *
     * @param  list<self>  $ids
     */
    public static function patterns(string $work, array $ids, string $suffix = ''): string
    {
        return implode("\n", array_map(fn (self $id): string => "OPTIONAL { ?{$work} {$id->path()} ?{$id->value}{$suffix} . {$id->only("?{$id->value}{$suffix}")}}", $ids));
    }

    /**
     * The condition that the work has one of the ids at all.
     *
     * @param  list<self>  $ids
     */
    public static function anyBound(array $ids, string $suffix = ''): string
    {
        return implode(' || ', array_map(fn (self $id): string => "BOUND(?{$id->value}{$suffix})", $ids));
    }

    /**
     * The works a result row's ids name, one per kind, with every id of that
     * kind it carries.
     *
     * @param  array<array-key, mixed>  $row
     * @param  list<self>  $ids
     * @return array<string, CatalogWork> by kind and its first id
     */
    public static function works(array $row, array $ids): array
    {
        $byKind = [];

        foreach ($ids as $id) {
            $value = SparqlRow::value($row, $id->value);
            $ours = $value === null ? null : $id->ourValue($value);

            if ($ours !== null) {
                $byKind[$id->kind()->value][$id->catalogue()] = $ours;
            }
        }

        $works = [];

        foreach ($byKind as $kind => $externalIds) {
            $works["{$kind}:".reset($externalIds)] = new CatalogWork(MediaKind::from($kind), $externalIds);
        }

        return $works;
    }
}
