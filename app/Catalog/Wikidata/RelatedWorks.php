<?php

namespace App\Catalog\Wikidata;

use App\Catalog\MediaKind;
use App\Catalog\Relations\CatalogWork;
use App\Catalog\Relations\RelatedWork;
use App\Catalog\Relations\SourceAnswer;
use App\Catalog\Relations\TitleRelation;
use App\Support\ExternalServiceException;
use App\Support\Payload;

/**
 * The films and shows Wikidata ties to a title in ways its provider does not:
 * spin-offs (P2512) and what a title follows or is followed by (P155, P156),
 * read from both ends, since editors file each tie on one item or the other;
 * and the franchise a title belongs to, with every film and show of it.
 * It speaks Wikidata's vocabulary so nothing else has to; one query answers
 * for a whole batch of titles, because the endpoint is slow per request, not
 * per item.
 *
 * Only works with a film or show id at our screen provider are returned,
 * which drops soundtracks, episodes and unfiled fan works on its own. What is
 * filed as pornography, a parody or a fan film is left out by class.
 */
final readonly class RelatedWorks
{
    /** Seconds a batch may take: asked from a background job, never from a page. */
    public const TIMEOUT_SECONDS = 60;

    /**
     * Genres and classes never put beside a title: pornographic film,
     * pornographic parody film, parody film, parody, fan film.
     */
    private const UNWANTED = 'wd:Q185529 wd:Q16254232 wd:Q622548 wd:Q170539 wd:Q2301591';

    /** Rows one answer about a batch of titles may run to: far above any real batch, a stop for a runaway query. */
    private const MAX_TIE_ROWS = 3000;

    /** The same stop for a batch of franchises, the largest of which hold hundreds of works. */
    private const MAX_MEMBER_ROWS = 20000;

    /** How a franchise row is told from a tie in the one answer. */
    private const FRANCHISE = 'franchise';

    /** The catalogue Wikidata's P4947 (film) and P4983 (show) ids belong to, as the id resolver names it. */
    private const SCREEN_CATALOGUE = 'tmdb';

    public function __construct(private WikidataClient $wikidata) {}

    /**
     * The works tied to each title and the franchises (P8345) and fictional
     * universes (P1434) it belongs to, in one question.
     *
     * @param  list<string>  $qids
     *
     * @throws ExternalServiceException
     */
    public function of(array $qids): SourceAnswer
    {
        // The ids are pasted into the query: anything but an item names nothing.
        $qids = array_values(array_filter($qids, SourceWorks::isItem(...)));

        if ($qids === []) {
            return new SourceAnswer;
        }

        $subjects = implode(' ', array_map(fn (string $qid): string => "wd:{$qid}", $qids));
        $unwanted = self::UNWANTED;
        $franchise = self::FRANCHISE;
        $tieRows = self::MAX_TIE_ROWS;
        [$spinOff, $spinOffOf, $next, $previous] = [TitleRelation::SpinOff->value, TitleRelation::SpinOffOf->value, TitleRelation::Next->value, TitleRelation::Previous->value];

        $sparql = <<<SPARQL
            SELECT ?s ?rel ?o ?movie ?show WHERE {
              VALUES ?s { {$subjects} }
              { ?s wdt:P2512 ?o . BIND("{$spinOff}" AS ?rel) }
              UNION { ?o wdt:P2512 ?s . BIND("{$spinOffOf}" AS ?rel) }
              UNION { ?s wdt:P156 ?o . BIND("{$next}" AS ?rel) }
              UNION { ?o wdt:P155 ?s . BIND("{$next}" AS ?rel) }
              UNION { ?s wdt:P155 ?o . BIND("{$previous}" AS ?rel) }
              UNION { ?o wdt:P156 ?s . BIND("{$previous}" AS ?rel) }
              UNION { ?s wdt:P8345|wdt:P1434 ?o . BIND("{$franchise}" AS ?rel) }
              OPTIONAL { ?o wdt:P4947 ?movie . }
              OPTIONAL { ?o wdt:P4983 ?show . }
              FILTER(?rel = "{$franchise}" || BOUND(?movie) || BOUND(?show))
              FILTER NOT EXISTS {
                VALUES ?unwanted { {$unwanted} }
                { ?o wdt:P136 ?unwanted } UNION { ?o wdt:P31 ?unwanted }
              }
            }
            LIMIT {$tieRows}
            SPARQL;

        $ties = [];
        $franchises = [];

        foreach ($this->wikidata->select($sparql, self::TIMEOUT_SECONDS) as $row) {
            $subject = $this->entity($row, 's');
            $kind = (string) $this->value($row, 'rel');

            if ($subject === null) {
                continue;
            }

            if ($kind === self::FRANCHISE) {
                $hub = $this->entity($row, 'o');

                if ($hub !== null && SourceWorks::isItem($hub)) {
                    $franchises[$subject][$hub] = $hub;
                }

                continue;
            }

            $relation = TitleRelation::tryFrom($kind);

            if ($relation === null) {
                continue;
            }

            foreach ($this->screenWorks($row) as $key => $work) {
                $ties[$subject]["{$relation->value}:{$key}"] = new RelatedWork($relation, $work);
            }
        }

        return new SourceAnswer(array_map(array_values(...), $ties), array_map(array_values(...), $franchises));
    }

    /**
     * Every film and show of each franchise, the unwanted left out as above.
     *
     * @param  list<string>  $franchises  the source's ids
     * @return array<string, list<CatalogWork>> by franchise; one nothing is filed under has no entry
     *
     * @throws ExternalServiceException
     */
    public function membersOf(array $franchises): array
    {
        $franchises = array_values(array_filter($franchises, SourceWorks::isItem(...)));

        if ($franchises === []) {
            return [];
        }

        $hubs = implode(' ', array_map(fn (string $qid): string => "wd:{$qid}", $franchises));
        $unwanted = self::UNWANTED;
        $memberRows = self::MAX_MEMBER_ROWS;

        $sparql = <<<SPARQL
            SELECT ?s ?o ?movie ?show WHERE {
              VALUES ?s { {$hubs} }
              ?o wdt:P8345|wdt:P1434 ?s .
              OPTIONAL { ?o wdt:P4947 ?movie . }
              OPTIONAL { ?o wdt:P4983 ?show . }
              FILTER(BOUND(?movie) || BOUND(?show))
              FILTER NOT EXISTS {
                VALUES ?unwanted { {$unwanted} }
                { ?o wdt:P136 ?unwanted } UNION { ?o wdt:P31 ?unwanted }
              }
            }
            LIMIT {$memberRows}
            SPARQL;

        $members = [];

        foreach ($this->wikidata->select($sparql, self::TIMEOUT_SECONDS) as $row) {
            $hub = $this->entity($row, 's');

            if ($hub === null) {
                continue;
            }

            foreach ($this->screenWorks($row) as $key => $work) {
                $members[$hub][$key] = $work;
            }
        }

        return array_map(array_values(...), $members);
    }

    /**
     * The row's other work as a film, a show or both, by its ids at our screen provider.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<string, CatalogWork> by kind and id
     */
    private function screenWorks(array $row): array
    {
        $works = [];

        foreach ([MediaKind::Movie->value => 'movie', MediaKind::Show->value => 'show'] as $kind => $variable) {
            $id = $this->value($row, $variable);

            if ($id !== null && ctype_digit($id)) {
                $works["{$kind}:{$id}"] = new CatalogWork(MediaKind::from($kind), [self::SCREEN_CATALOGUE => $id]);
            }
        }

        return $works;
    }

    /**
     * One variable of a SPARQL result row: `{"type": …, "value": …}`.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function value(array $row, string $variable): ?string
    {
        $binding = $row[$variable] ?? null;

        return is_array($binding) ? Payload::string($binding, 'value') : null;
    }

    /**
     * The QID a variable names, read off the end of its entity URI.
     *
     * @param  array<array-key, mixed>  $row
     */
    private function entity(array $row, string $variable): ?string
    {
        $uri = $this->value($row, $variable);

        return $uri === null ? null : basename($uri);
    }
}
