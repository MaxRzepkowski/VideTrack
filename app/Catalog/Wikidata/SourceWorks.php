<?php

namespace App\Catalog\Wikidata;

use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use Illuminate\Support\Facades\Log;

/**
 * The works a film or show is based on (P144), as Wikidata knows them — the
 * bridge from a screen title to the book or manga behind it. It speaks
 * Wikidata's vocabulary (properties, classes, SPARQL) so the actions that
 * turn the works into our own cards never have to. A lookup that fails is an
 * empty answer: the section it feeds simply does not appear.
 */
final readonly class SourceWorks
{
    /**
     * Wikidata classes that count as "a book" for this bridge — literary /
     * written work and its common subtypes. The subclass walk (P279*) keeps
     * e.g. a specific novel that is only an instance of "novel".
     */
    private const WORK_CLASSES = 'wd:Q7725634 wd:Q47461344 wd:Q571 wd:Q8261 wd:Q49084 wd:Q1667921';

    public function __construct(private WikidataClient $wikidata) {}

    /** Whether the id names a Wikidata item (and is safe inside a query). */
    public static function isItem(string $qid): bool
    {
        return preg_match('/^Q\d+$/', $qid) === 1;
    }

    /**
     * The literary works the item is based on, comics left out: each with
     * the label to search a book catalogue by, its authors and its ISBN-13s.
     *
     * @return array<string, array{label: string, authors: list<string>, isbns: list<string>}> work QID → work
     */
    public function literaryWorksBasedOn(string $qid, string $locale): array
    {
        // The id is pasted into the query: anything but an item names nothing.
        if (! self::isItem($qid)) {
            return [];
        }

        $lang = preg_match('/^[a-z]{2}$/', $locale) === 1 ? "{$locale},en" : 'en';
        $classes = self::WORK_CLASSES;

        // The author (P50) is pulled so the book match can be verified by
        // author, not just title. The English label is fetched separately and
        // used as the search query: book identity is language-independent and
        // the catalogue is English-centric, so a translated title (the Polish one)
        // would miss where "The Shining" hits. A work fans out into several
        // rows (authors × labels), regrouped by QID below.
        $sparql = <<<SPARQL
            SELECT ?work ?workLabel ?enLabel ?authorLabel WHERE {
              wd:{$qid} wdt:P144 ?work .
              ?work wdt:P31/wdt:P279* ?class .
              VALUES ?class { {$classes} }
              # Comics chain up to "written work" too, but this is the book
              # bridge — drop anything that is a comic (The Boys, Invincible).
              FILTER NOT EXISTS { ?work wdt:P31/wdt:P279* wd:Q1004 }
              OPTIONAL { ?work rdfs:label ?enLabel . FILTER(LANG(?enLabel) = "en") }
              OPTIONAL { ?work wdt:P50 ?author . }
              SERVICE wikibase:label { bd:serviceParam wikibase:language "{$lang}". }
            }
            LIMIT 30
            SPARQL;

        try {
            $bindings = $this->wikidata->select($sparql);
        } catch (ProviderException|ProviderRejected $e) {
            Log::warning('Wikidata source-book lookup failed.', ['exception' => $e::class]);

            return [];
        }

        if ($bindings === []) {
            return [];
        }

        /** @var array<string, array{label: string, authors: list<string>, isbns: list<string>}> $works */
        $works = [];

        foreach ($bindings as $row) {
            $entity = SparqlRow::entity($row, 'work');
            // Query by the English label when present, else the localized one.
            $label = SparqlRow::value($row, 'enLabel') ?? SparqlRow::value($row, 'workLabel');

            if ($entity === null || $label === null || $label === $entity) {
                continue;
            }

            $work = $works[$entity] ?? ['label' => $label, 'authors' => [], 'isbns' => []];

            $author = SparqlRow::value($row, 'authorLabel');

            if ($author !== null && ! in_array($author, $work['authors'], true)) {
                $work['authors'][] = $author;
            }

            $works[$entity] = $work;
        }

        foreach ($this->isbnsBasedOn($qid) as $entity => $isbns) {
            if (isset($works[$entity])) {
                $works[$entity]['isbns'] = $isbns;
            }
        }

        return $works;
    }

    /**
     * The manga the item is based on, by their MyAnimeList ids (P4087), as
     * external ids the id resolver reads. Comics never carry P4087, so western
     * comics drop out on their own.
     *
     * @return list<array<string, string>> per work: catalogue => id
     */
    public function mangaBasedOn(string $qid): array
    {
        if (! self::isItem($qid)) {
            return [];
        }

        $sparql = <<<SPARQL
            SELECT DISTINCT ?mal WHERE {
              wd:{$qid} wdt:P144 ?work .
              ?work wdt:P4087 ?mal .
            }
            LIMIT 10
            SPARQL;

        try {
            $bindings = $this->wikidata->select($sparql);
        } catch (ProviderException|ProviderRejected $e) {
            Log::warning('Wikidata source-manga lookup failed.', ['exception' => $e::class]);

            return [];
        }

        $ids = [];

        foreach ($bindings as $row) {
            $id = SparqlRow::value($row, 'mal');

            if ($id !== null && ctype_digit($id) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return array_map(fn (string $id): array => ['mal' => $id], $ids);
    }

    /**
     * ISBN-13s (digits only) per based-on work — from the work itself (P212)
     * and from its editions (P747 → P212). Kept as its own lean query so the
     * edition fan-out never bloats the labelled work query above.
     *
     * @return array<string, list<string>> work QID → ISBN-13s
     */
    private function isbnsBasedOn(string $qid): array
    {
        $sparql = <<<SPARQL
            SELECT ?work ?isbn WHERE {
              wd:{$qid} wdt:P144 ?work .
              { ?work wdt:P212 ?isbn } UNION { ?work wdt:P747 ?edition . ?edition wdt:P212 ?isbn }
            }
            LIMIT 100
            SPARQL;

        try {
            $bindings = $this->wikidata->select($sparql);
        } catch (ProviderException|ProviderRejected $e) {
            Log::warning('Wikidata source-book editions lookup failed.', ['exception' => $e::class]);

            return [];
        }

        $isbns = [];

        foreach ($bindings as $row) {
            $entity = SparqlRow::entity($row, 'work');
            // Wikidata prints ISBNs hyphenated ("978-1-…"); Hardcover stores
            // them bare — compare on digits only.
            $printed = SparqlRow::value($row, 'isbn');
            $isbn = $printed === null ? null : preg_replace('/\D/', '', $printed);

            if ($entity !== null && $isbn !== null && strlen($isbn) === 13 && ! in_array($isbn, $isbns[$entity] ?? [], true)) {
                $isbns[$entity][] = $isbn;
            }
        }

        return $isbns;
    }
}
