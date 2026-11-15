<?php

namespace App\Suggestions\Data;

use App\Catalog\Data\MediaSummary;
use App\Models\MediaItem;
use Carbon\CarbonInterface;

/**
 * Where a user stands with a batch of titles: which they finished, and which
 * they have settled some other way (started, dropped).
 */
final readonly class TitleStandings
{
    /**
     * @param  array<string, true>  $finished  by {@see MediaSummary::key()}
     * @param  array<string, true>  $touched  started or dropped, not finished
     */
    public function __construct(
        private array $finished,
        private array $touched,
        private CarbonInterface $today,
    ) {}

    public function hasFinished(MediaSummary $title): bool
    {
        return isset($this->finished[$title->key()]);
    }

    /** Out already and nothing the user has begun, finished or dropped. */
    public function isOpen(MediaSummary $title): bool
    {
        return $this->isOut($title) && ! isset($this->finished[$title->key()]) && ! isset($this->touched[$title->key()]);
    }

    /**
     * Open, and fit to put forward on a shelf: a shelf is curated, so a title
     * without a poster is not, even where the provider's whole list has it.
     */
    public function isSuggestible(MediaSummary $title): bool
    {
        return $this->isOpen($title) && $title->posterPath !== null;
    }

    public function isOut(MediaSummary $title): bool
    {
        return MediaItem::airedBy($title->releaseDate, $this->today);
    }
}
