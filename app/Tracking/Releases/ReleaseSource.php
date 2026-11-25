<?php

namespace App\Tracking\Releases;

use App\Tracking\Settings\DeclaresTypeOptions;
use Illuminate\Support\Collection;

/**
 * Where one kind of title keeps its releases: a series in its episodes, a
 * film in its premiere and the day it leaves cinemas, a game or a book in its
 * per-platform premieres. Which of them a surface shows is the source's own
 * setting, so it declares it for the kinds of title it serves.
 */
interface ReleaseSource extends DeclaresTypeOptions
{
    /**
     * The releases of the given tracked titles that fall in the window: ids
     * of titles of a kind this source serves, of a module switched on. A
     * source reads only the titles that can have one there, where their
     * columns tell: a library holds far more titles than any window has
     * releases.
     *
     * @param  list<int>  $rootIds
     * @return Collection<int, ReleaseEntry>
     */
    public function releases(array $rootIds, ReleaseWindow $window): Collection;
}
