<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\BuildDiscoverPage;
use App\Catalog\Discovery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\DiscoverRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The /discover page: one type at a time, browsed, filtered or (for people)
 * listed — BuildDiscoverPage has the modes. An empty type means the first
 * browsable one (shows, where TMDB is on); there
 * is no mixed "all" view.
 */
class DiscoverController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        DiscoverRequest $request,
        Discovery $discovery,
        BuildDiscoverPage $build,
    ): Response|RedirectResponse {
        $query = $request->discoverQuery();

        abort_unless(in_array($query->type, $discovery->pageTypes(), true), 404);

        $page = $build->execute($viewer, $query, App::getLocale());

        abort_if($page->notFound, 404);

        if ($page->redirectTo !== null) {
            return to_route('discover', $page->redirectTo);
        }

        return Inertia::render('media/Discover', $page->props);
    }
}
