<?php

namespace App\Catalog\Wikidata;

use App\Catalog\Isbn;
use App\Catalog\MediaKind;
use App\Catalog\Relations\CatalogWork;
use App\Catalog\Relations\RelatedWork;
use App\Catalog\Relations\SourceAnswer;
use App\Catalog\Relations\TitleRelation;
use App\Support\ExternalServiceException;

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
 *
 * Across media (any title, not only films and shows): what a title is based
 * on (P144, or the source's P4969), what is based on it, and what shares its
 * source, each a work any of our catalogues has, or a book known by none of
 * their ids, to be looked up by its title.
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

    /** The same stop across media, where one source may have been adapted dozens of times. */
    private const MAX_ACROSS_MEDIA_ROWS = 5000;

    /**
     * What a work shares its source with only when the source is a work
     * itself: a fictional character, a fictional universe or a media
     * franchise would make every Batman game a sibling of every Batman film,
     * which the franchise already says.
     */
    private const NOT_A_SOURCE = 'wd:Q95074 wd:Q559618 wd:Q196600';

    /**
     * Classes a book is filed under: literary work, written work, book,
     * novel, poem, short story. A comic chains up to written work too and is
     * told apart by its own class (Q1004).
     */
    private const LITERARY = 'wd:Q7725634 wd:Q47461344 wd:Q571 wd:Q8261 wd:Q49084 wd:Q1667921';

    /** The same stop for a batch's books, a novel having dozens of editions. */
    private const MAX_EDITION_ROWS = 5000;

    /** What a variable bound once per value is told apart by from the one value selected of it. */
    private const EACH = '_any';

    /** How a franchise row is told from a tie in the one answer. */
    private const FRANCHISE = 'franchise';

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
        $qids = array_values(array_filter($qids, WikidataClient::isItem(...)));

        if ($qids === []) {
            return new SourceAnswer;
        }

        $subjects = implode(' ', array_map(fn (string $qid): string => "wd:{$qid}", $qids));
        $unwanted = self::UNWANTED;
        [$screenVariables, $screenIds, $onScreen] = [CatalogueId::variables(CatalogueId::SCREEN), CatalogueId::patterns('o', CatalogueId::SCREEN), CatalogueId::anyBound(CatalogueId::SCREEN)];
        $franchise = self::FRANCHISE;
        $tieRows = self::MAX_TIE_ROWS;
        [$spinOff, $spinOffOf, $next, $previous] = [TitleRelation::SpinOff->value, TitleRelation::SpinOffOf->value, TitleRelation::Next->value, TitleRelation::Previous->value];

        $sparql = <<<SPARQL
            SELECT ?s ?rel ?o {$screenVariables} WHERE {
              VALUES ?s { {$subjects} }
              { ?s wdt:P2512 ?o . BIND("{$spinOff}" AS ?rel) }
              UNION { ?o wdt:P2512 ?s . BIND("{$spinOffOf}" AS ?rel) }
              UNION { ?s wdt:P156 ?o . BIND("{$next}" AS ?rel) }
              UNION { ?o wdt:P155 ?s . BIND("{$next}" AS ?rel) }
              UNION { ?s wdt:P155 ?o . BIND("{$previous}" AS ?rel) }
              UNION { ?o wdt:P156 ?s . BIND("{$previous}" AS ?rel) }
              UNION { ?s wdt:P8345|wdt:P1434 ?o . BIND("{$franchise}" AS ?rel) }
              {$screenIds}
              FILTER(?rel = "{$franchise}" || {$onScreen})
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
            $subject = SparqlRow::entity($row, 's');
            $kind = SparqlRow::value($row, 'rel');

            if ($subject === null || $kind === null) {
                continue;
            }

            if ($kind === self::FRANCHISE) {
                $hub = SparqlRow::entity($row, 'o');

                if ($hub !== null && WikidataClient::isItem($hub)) {
                    $franchises[$subject][$hub] = $hub;
                }

                continue;
            }

            $relation = TitleRelation::tryFrom($kind);

            if ($relation === null || $relation->acrossMedia()) {
                continue;
            }

            foreach (CatalogueId::works($row, CatalogueId::SCREEN) as $key => $work) {
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
        $franchises = array_values(array_filter($franchises, WikidataClient::isItem(...)));

        if ($franchises === []) {
            return [];
        }

        $hubs = implode(' ', array_map(fn (string $qid): string => "wd:{$qid}", $franchises));
        $unwanted = self::UNWANTED;
        [$screenVariables, $screenIds, $onScreen] = [CatalogueId::variables(CatalogueId::SCREEN), CatalogueId::patterns('o', CatalogueId::SCREEN), CatalogueId::anyBound(CatalogueId::SCREEN)];
        $memberRows = self::MAX_MEMBER_ROWS;

        $sparql = <<<SPARQL
            SELECT ?s ?o {$screenVariables} WHERE {
              VALUES ?s { {$hubs} }
              ?o wdt:P8345|wdt:P1434 ?s .
              {$screenIds}
              FILTER({$onScreen})
              FILTER NOT EXISTS {
                VALUES ?unwanted { {$unwanted} }
                { ?o wdt:P136 ?unwanted } UNION { ?o wdt:P31 ?unwanted }
              }
            }
            LIMIT {$memberRows}
            SPARQL;

        $members = [];

        foreach ($this->wikidata->select($sparql, self::TIMEOUT_SECONDS) as $row) {
            $hub = SparqlRow::entity($row, 's');

            if ($hub === null) {
                continue;
            }

            foreach (CatalogueId::works($row, CatalogueId::SCREEN) as $key => $work) {
                $members[$hub][$key] = $work;
            }
        }

        return array_map(array_values(...), $members);
    }

    /**
     * The works in other media tied to each title: what it is based on, what
     * is based on it and what is based on the same work. A book is known by
     * its class, title and authors in the one question, and its editions'
     * ids are asked for apart, of the books found only: a novel has dozens
     * of editions, each with its numbers, and asked together with the rest
     * they multiply into a question too heavy to answer.
     *
     * @param  list<string>  $qids
     * @return array<string, list<RelatedWork>> by the title's QID; one with none has no entry
     *
     * @throws ExternalServiceException
     */
    public function acrossMedia(array $qids): array
    {
        $qids = array_values(array_filter($qids, WikidataClient::isItem(...)));

        if ($qids === []) {
            return [];
        }

        $ids = array_values(array_filter(CatalogueId::cases(), fn (CatalogueId $id): bool => $id->kind() !== MediaKind::Book));
        $subjects = implode(' ', array_map(fn (string $qid): string => "wd:{$qid}", $qids));
        $each = self::EACH;
        [$sampled, $patterns, $anyId] = [CatalogueId::sampled($ids, $each), CatalogueId::patterns('o', $ids, $each), CatalogueId::anyBound($ids, $each)];
        [$unwanted, $notASource, $literary, $rows] = [self::UNWANTED, self::NOT_A_SOURCE, self::LITERARY, self::MAX_ACROSS_MEDIA_ROWS];
        [$basedOn, $adaptation, $sameSource] = [TitleRelation::BasedOn->value, TitleRelation::Adaptation->value, TitleRelation::SameSource->value];

        // A book is named by its English title and its authors: book
        // catalogues are English-first, and the authors tell the right book
        // from others of its title when no edition of it is found.
        $sparql = <<<SPARQL
            SELECT ?s ?rel ?o {$sampled} (SAMPLE(?literary{$each}) AS ?literary) (SAMPLE(?label{$each}) AS ?label)
              (GROUP_CONCAT(DISTINCT ?author{$each}; separator="|") AS ?authors) WHERE {
              VALUES ?s { {$subjects} }
              { ?s wdt:P144 ?o . BIND("{$basedOn}" AS ?rel) }
              UNION { ?o wdt:P4969 ?s . BIND("{$basedOn}" AS ?rel) }
              UNION { ?s wdt:P4969 ?o . BIND("{$adaptation}" AS ?rel) }
              UNION { ?o wdt:P144 ?s . BIND("{$adaptation}" AS ?rel) }
              UNION {
                ?s wdt:P144|^wdt:P4969 ?source . ?o wdt:P144|^wdt:P4969 ?source .
                FILTER(?o != ?s)
                FILTER NOT EXISTS { VALUES ?notASource { {$notASource} } ?source wdt:P31/wdt:P279* ?notASource }
                BIND("{$sameSource}" AS ?rel)
              }
              FILTER NOT EXISTS {
                VALUES ?unwanted { {$unwanted} }
                { ?o wdt:P136 ?unwanted } UNION { ?o wdt:P31 ?unwanted }
              }
              {$patterns}
              BIND(IF({$anyId}, false, EXISTS { VALUES ?class { {$literary} } ?o wdt:P31/wdt:P279* ?class }
                && NOT EXISTS { ?o wdt:P31/wdt:P279* wd:Q1004 }) AS ?literary{$each})
              OPTIONAL { FILTER(?literary{$each}) ?o rdfs:label ?label{$each} . FILTER(LANG(?label{$each}) = "en") }
              OPTIONAL { FILTER(?literary{$each}) ?o wdt:P50 ?author . ?author rdfs:label ?author{$each} . FILTER(LANG(?author{$each}) = "en") }
            }
            GROUP BY ?s ?rel ?o
            LIMIT {$rows}
            SPARQL;

        $answer = $this->wikidata->select($sparql, self::TIMEOUT_SECONDS);
        $books = [];

        foreach ($answer as $row) {
            $work = SparqlRow::entity($row, 'o');

            if ($work !== null && SparqlRow::value($row, 'literary') === 'true') {
                $books[$work] = $work;
            }
        }

        $editions = $this->editionIds(array_values($books));
        $ties = [];

        foreach ($answer as $row) {
            $subject = SparqlRow::entity($row, 's');
            $rel = SparqlRow::value($row, 'rel');
            $relation = $rel === null ? null : TitleRelation::tryFrom($rel);

            if ($subject === null || $relation === null || ! $relation->acrossMedia()) {
                continue;
            }

            foreach ($this->worksOf($row, $ids, $editions) as $key => $work) {
                $ties[$subject]["{$relation->value}:{$key}"] = new RelatedWork($relation, $work);
            }
        }

        return array_map(array_values(...), $ties);
    }

    /**
     * The ids a book catalogue may know each book by, from the work and its
     * editions (P747): one Goodreads edition and one ISBN-13, an English
     * edition's where there is one, since book catalogues are English-first.
     *
     * @param  list<string>  $books  the books' QIDs
     * @return array<string, array<string, string>> by QID: catalogue => id
     *
     * @throws ExternalServiceException
     */
    private function editionIds(array $books): array
    {
        if ($books === []) {
            return [];
        }

        $works = implode(' ', array_map(fn (string $qid): string => "wd:{$qid}", $books));
        $rows = self::MAX_EDITION_ROWS;
        [$goodreads, $isbn] = [CatalogueId::Goodreads->value, CatalogueId::Isbn->value];

        // A union, not two optionals: the ids add up instead of multiplying.
        $sparql = <<<SPARQL
            SELECT ?o ?{$goodreads} ?{$isbn} WHERE {
              VALUES ?o { {$works} }
              { ?o wdt:P2969|wdt:P747/wdt:P2969 ?{$goodreads} }
              UNION { ?o wdt:P212|wdt:P747/wdt:P212 ?{$isbn} }
            }
            LIMIT {$rows}
            SPARQL;

        $ids = [];

        foreach ($this->wikidata->select($sparql, self::TIMEOUT_SECONDS) as $row) {
            $work = SparqlRow::entity($row, 'o');

            if ($work === null) {
                continue;
            }

            foreach ([CatalogueId::Goodreads, CatalogueId::Isbn] as $id) {
                $value = SparqlRow::value($row, $id->value);
                $ours = $value === null ? null : $id->ourValue($value);

                if ($ours !== null && (! isset($ids[$work][$id->catalogue()]) || ($id === CatalogueId::Isbn && Isbn::isEnglish($ours) && ! Isbn::isEnglish($ids[$work][$id->catalogue()])))) {
                    $ids[$work][$id->catalogue()] = $ours;
                }
            }
        }

        return $ids;
    }

    /**
     * What a row's other work is in our catalogues: a film, a show or both,
     * else the first other kind its ids name, else a book by its editions'
     * ids, title and authors.
     *
     * @param  array<array-key, mixed>  $row
     * @param  list<CatalogueId>  $ids
     * @param  array<string, array<string, string>>  $editions  each book's ids, by QID
     * @return array<string, CatalogWork> by kind and id
     */
    private function worksOf(array $row, array $ids, array $editions): array
    {
        $works = CatalogueId::works($row, $ids);
        $screen = array_filter($works, fn (CatalogWork $work): bool => CatalogueId::isScreen($work->kind));

        if ($screen !== []) {
            return $screen;
        }

        if ($works !== []) {
            return array_slice($works, 0, 1, preserve_keys: true);
        }

        $title = SparqlRow::value($row, 'label');
        $work = SparqlRow::entity($row, 'o');

        if (SparqlRow::value($row, 'literary') !== 'true' || $title === null || $work === null) {
            return [];
        }

        $names = SparqlRow::value($row, 'authors');
        $authors = $names === null ? [] : array_values(array_filter(explode('|', $names)));

        return ["book:{$work}" => new CatalogWork(MediaKind::Book, $editions[$work] ?? [], $title, $authors)];
    }
}
