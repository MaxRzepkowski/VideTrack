<?php

namespace App\Catalog\Relations;

use App\Catalog\Data\MediaSummary;
use App\Catalog\ItemNotFoundException;
use App\Catalog\Matching;
use App\Catalog\MatureContent;
use App\Catalog\MediaKind;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;
use App\Support\ExternalServiceException;

/**
 * Finds a book an outside source names by its title and authors alone, for a
 * work it knows by no id our book catalogue reads. The authors are the
 * check: a book of the same title by someone else is no match, a work whose
 * authors are not known needs the very title, and a title too common to
 * confirm is dropped rather than shown wrong.
 */
final readonly class BookByTitle
{
    public function __construct(
        private MediaTypeRegistry $types,
        private ProviderRegistry $providers,
    ) {}

    /**
     * @param  list<string>  $authors  as the source names them; may be none
     *
     * @throws ExternalServiceException the book catalogue did not answer
     */
    public function find(string $title, array $authors, string $locale): ?MediaSummary
    {
        if (! $this->types->has(MediaKind::Book->value)) {
            return null;
        }

        $wanted = array_values(array_filter($authors, fn (string $author): bool => trim($author) !== ''));
        $match = $this->bestMatch($this->search($title, $locale), $title, $wanted);

        // Author unknown: only the very title vouches for the book.
        if ($wanted === []) {
            return $match !== null && Matching::sameTitle($match->title, $title) ? $match : null;
        }

        if ($match !== null && Matching::sharesAuthor($wanted, $match)) {
            return $match;
        }

        // The plain title collided with a same-named book by someone else:
        // asked again with the author, the right one surfaces.
        $refined = $this->bestMatch($this->search("{$title} {$wanted[0]}", $locale), $title, $wanted);

        return $refined !== null && Matching::sharesAuthor($wanted, $refined) ? $refined : null;
    }

    /**
     * @return list<MediaSummary>
     *
     * @throws ExternalServiceException
     */
    private function search(string $query, string $locale): array
    {
        try {
            return $this->providers->forType($this->types->get(MediaKind::Book->value))->search($query, $locale, MatureContent::Suggestive);
        } catch (ItemNotFoundException) {
            return [];
        }
    }

    /**
     * Scores each hit against the work and takes the best (ties keep the
     * search's rank). A shared author is the strongest sign, so it outweighs
     * an exact title: otherwise an edition of the same title credited to a
     * translator would beat the book. A shared title word breaks ties, so the
     * author alone cannot jump to another book of theirs. With nothing
     * matching (a work without authors, a translated title) the top hit is
     * taken.
     *
     *   author +3   exact title +2   shared title word +1
     *
     * @param  list<MediaSummary>  $results
     * @param  list<string>  $wanted
     */
    private function bestMatch(array $results, string $title, array $wanted): ?MediaSummary
    {
        $words = $this->titleWords($title);
        $best = null;
        $bestScore = 0;

        foreach ($results as $result) {
            $score = 0;

            if ($wanted !== [] && Matching::sharesAuthor($wanted, $result)) {
                $score += 3;
            }

            if (Matching::sameTitle($result->title, $title)) {
                $score += 2;
            } elseif (array_intersect($words, $this->titleWords($result->title)) !== []) {
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
     * The words of a title that carry it (four letters or more, lowercased):
     * articles and connectors left out, so an overlap survives translation.
     *
     * @return list<string>
     */
    private function titleWords(string $title): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn (string $word): bool => mb_strlen($word) >= 4));
    }
}
