<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\SearchLibrary;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\SearchRequest;
use App\Library\RecentViews;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the command palette shows, as plain JSON. Not a partial reload of the
 * search page: the palette opens over *any* page, and a partial reload swaps
 * the props of the page underneath it. Fetched with `fetch`, never with
 * `router` — an Inertia visit would repaint whatever the user was reading.
 *
 * No provider is ever called from here: the palette reads the local library, so it answers in milliseconds and never depends on
 * a third party being up; searching for something new is the search page's job.
 */
class PaletteController extends Controller
{
    /** Both groups stay short enough to scan without scrolling the overlay. */
    public const LIMIT = 8;

    public function __invoke(#[CurrentUser] User $viewer, Request $request, SearchLibrary $library, RecentViews $recentViews): JsonResponse
    {
        // A pasted wall of text is cut like on the search page, not refused.
        $query = mb_substr(trim((string) $request->string('q')), 0, SearchRequest::MAX_TERM);

        // An empty palette is a dead end, so it opens on the history instead.
        if ($query === '') {
            return response()->json([
                'recent' => $recentViews->recent($viewer, limit: self::LIMIT),
                'results' => [],
            ]);
        }

        return response()->json([
            'recent' => [],
            'results' => $library->execute($viewer, $query, self::LIMIT),
        ]);
    }
}
