<?php

namespace App\Tracking\Releases;

use App\Models\MediaItem;
use Illuminate\Support\Collection;

/**
 * Where one kind of title keeps its releases: a series in its episodes, a
 * film in its premiere and the day it leaves cinemas, a game or a book in its
 * per-platform premieres.
 */
interface ReleaseSource
{
    /**
     * The releases of the given tracked titles that fall in the window.
     *
     * @param  Collection<int, MediaItem>  $roots
     * @return Collection<int, ReleaseEntry>
     */
    public function releases(Collection $roots, ReleaseWindow $window): Collection;
}
