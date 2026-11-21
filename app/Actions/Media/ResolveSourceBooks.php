<?php

namespace App\Actions\Media;

use App\Catalog\Contracts\MetadataProvider;
use App\Catalog\Contracts\SupportsIsbnLookup;
use App\Catalog\Data\MediaDetails;
use App\Catalog\Data\MediaSummary;
use App\Catalog\ItemNotFoundException;
use App\Catalog\Matching;
use App\Catalog\MatureContent;
use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRegistry;
use App\Catalog\ProviderRejected;
use App\Catalog\Wikidata\SourceWorks;

/**
 * The book(s) a film is based on, rendered through our own book provider —
 * Wikidata is only the bridge: "based on" (P144), narrowed to literary works,
 * gives the canonical title, which we then resolve against the book provider's
 * search so the section shows native book cards linking into our book pages.
 *
 * Experimental enrichment: a missing QID, a disabled book type, or any lookup
 * failure just drops the section — never breaks the page.
 */
final readonly class ResolveSourceBooks
{
    public function __construct(
        private SourceWorks $sources,
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    /**
     * @return list<MediaSummary>|null
     */
    public function execute(MediaDetails $film, string $locale): ?array
    {
        $qid = $film->wikidataId;

        if ($qid === null || ! SourceWorks::isItem($qid) || ! $this->types->has(MediaKind::Book->value)) {
            return null;
        }

        $works = $this->sources->literaryWorksBasedOn($qid, $locale);

        if ($works === []) {
            return null;
        }

        $books = $this->providers->forType($this->types->get(MediaKind::Book->value));

        $matches = [];
        $seen = [];

        foreach ($works as $work) {
            $match = $this->resolveBook($books, $work, $locale);

            if ($match === null || in_array($match->ref, $seen, true)) {
                continue;
            }

            $matches[] = $match;
            $seen[] = $match->ref;
        }

        return $matches === [] ? null : $matches;
    }

    /**
     * Resolve one Wikidata work to a book in our catalogue. ISBN is the
     * deterministic path: a work's ISBN identifies the exact edition, and the
     * catalogue collapses editions onto one book, so any ISBN lands on it — no
     * title/author guessing. Only works without a usable ISBN fall back to the
     * fuzzy title+author search, whose author gate still rejects same-title
     * impostors ("Odyssey" the MTG novel for Homer's epic).
     *
     * @param  array{label: string, authors: list<string>, isbns: list<string>}  $work
     */
    private function resolveBook(MetadataProvider $books, array $work, string $locale): ?MediaSummary
    {
        if ($work['isbns'] !== [] && $books instanceof SupportsIsbnLookup) {
            try {
                $byIsbn = $books->bookByIsbns($work['isbns'], $locale);
            } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
                $byIsbn = null;
            }

            if ($byIsbn !== null) {
                return $byIsbn;
            }
        }

        return $this->matchByTitle($books, $work['label'], $work['authors'], $locale);
    }

    /**
     * Fuzzy fallback: title search gated by author. A title-only hit is
     * rejected when the author is known, an author-qualified retry is tried,
     * and an unconfirmable generic title is dropped rather than shown wrong.
     * Authorless works keep the lenient title-based pick.
     *
     * @param  list<string>  $authors  Author labels from Wikidata (may be empty).
     */
    private function matchByTitle(MetadataProvider $books, string $label, array $authors, string $locale): ?MediaSummary
    {
        $wanted = array_values(array_filter($authors, fn (string $author): bool => trim($author) !== ''));

        $match = $this->bestMatch($this->search($books, $label, $locale), $label, $wanted);

        // Author unknown → nothing to verify against; take the title pick.
        if ($wanted === []) {
            return $match;
        }

        if ($match !== null && Matching::sharesAuthor($wanted, $match)) {
            return $match;
        }

        // The plain title collided with a same-named book by someone else —
        // retry with the author appended so the right edition surfaces.
        $refined = $this->bestMatch($this->search($books, "{$label} {$authors[0]}", $locale), $label, $wanted);

        return $refined !== null && Matching::sharesAuthor($wanted, $refined) ? $refined : null;
    }

    /**
     * @return list<MediaSummary>
     */
    private function search(MetadataProvider $books, string $query, string $locale): array
    {
        try {
            return $books->search($query, $locale, MatureContent::Suggestive);
        } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
            return [];
        }
    }

    /**
     * Score each hit against the Wikidata work and take the best (ties keep
     * search rank). A shared author is the strongest identity signal, so it
     * outweighs an exact title — otherwise a same-title edition credited to a
     * translator/adapter would beat the canonical book. A title-token overlap
     * breaks ties so the author signal can't jump to an unrelated same-author
     * book. When nothing matches (authorless work, translated title) the top
     * hit is taken.
     *
     *   author +3   exact title +2   shared title token +1
     *
     * @param  list<MediaSummary>  $results
     * @param  list<string>  $wanted  Author names from Wikidata.
     */
    private function bestMatch(array $results, string $label, array $wanted): ?MediaSummary
    {
        $tokens = $this->titleTokens($label);

        $best = null;
        $bestScore = 0;

        foreach ($results as $result) {
            $score = 0;

            if ($wanted !== [] && Matching::sharesAuthor($wanted, $result)) {
                $score += 3;
            }

            if (Matching::sameTitle($result->title, $label)) {
                $score += 2;
            } elseif (array_intersect($tokens, $this->titleTokens($result->title)) !== []) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $best = $result;
                $bestScore = $score;
            }
        }

        return $best ?? ($results[0] ?? null);
    }

    /**
     * Significant title words (≥4 chars, lowercased) — drops articles and
     * connectors so overlap survives translation ("Harry"/"Potter" carry, a
     * translated word that has no English twin simply doesn't count against it).
     *
     * @return list<string>
     */
    private function titleTokens(string $title): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn (string $word): bool => mb_strlen($word) >= 4));
    }
}
