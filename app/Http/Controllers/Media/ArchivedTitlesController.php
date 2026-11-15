<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\BuildArchivedTitles;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Archive entries of the viewer's library. Deliberately not linked from the
 * navigation: the "no longer available" notification and Discord link here,
 * otherwise it is reached by its address.
 */
class ArchivedTitlesController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, BuildArchivedTitles $archived, LibraryStates $states): Response
    {
        $titles = $archived->execute($viewer);
        $summaries = array_column($titles, 'media');

        return Inertia::render('media/ArchivedTitles', [
            'titles' => array_map(fn (array $title): array => [
                'media' => $title['media'],
                'goneAt' => $title['goneAt']?->toIso8601String(),
            ], $titles),
            'userStates' => $states->resolve($viewer, $summaries),
        ]);
    }
}
