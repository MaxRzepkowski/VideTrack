<?php

namespace App\Http\Controllers\Media;

use App\Catalog\CatalogSearch;
use App\Catalog\Data\MediaSummary;
use App\Catalog\Data\SearchScopes;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\SearchRequest;
use App\Library\CreatorStateQuery;
use App\Library\LibraryStates;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __invoke(
        #[CurrentUser] User $viewer,
        SearchRequest $request,
        CatalogSearch $search,
        LibraryStates $states,
        CreatorStateQuery $creatorStates,
    ): Response {
        $query = $request->term();
        $scopes = $search->scopes();
        $scope = $scopes->resolve($request->scope());

        // Closure props: re-evaluated on the partial reloads the live search
        // input drives; an empty query never reaches a provider. Several props
        // need the same results (the state maps have to know the visible
        // cards), so the search runs ONCE per request — leaning on the
        // provider cache instead would ask a dead provider again and log its
        // failure twice. A provider that cannot answer leaves no results and
        // says so, rather than a "nothing found" that is not true.
        $answer = fn (): array => once(function () use ($search, $scope, $query, $viewer): array {
            if ($query === '' || $scope === null) {
                return ['results' => null, 'unavailable' => false];
            }

            try {
                return ['results' => $search->search($scope, $query, App::getLocale(), $viewer->preferences->matureContent()), 'unavailable' => false];
            } catch (ProviderException|ProviderRejected $e) {
                report($e);

                return ['results' => null, 'unavailable' => true];
            }
        });
        $results = fn (): ?array => $answer()['results'];

        return Inertia::render('media/Search', [
            'query' => $query,
            'scope' => $scope,
            'scopes' => array_map(
                fn (string $key): array => ['key' => $key, 'types' => $scopes->types($key)],
                $scopes->keys(),
            ),
            'results' => $results,
            'unavailable' => fn (): bool => $answer()['unavailable'],
            'userStates' => $this->ofResults($results, ! SearchScopes::isEntity($scope), fn (array $cards): array => $states->resolve($viewer, $cards)),
            // The person / company cards' counterpart of userStates.
            'creatorStates' => $this->ofResults($results, SearchScopes::isEntity($scope), fn (array $cards): array => $creatorStates->ofSummaries($viewer, $cards)),
        ]);
    }

    /**
     * A state prop of the result cards, empty when the scope's cards carry
     * no such state or there are no results.
     *
     * @param  Closure(): (list<MediaSummary>|null)  $results
     * @param  Closure(list<MediaSummary>): array<array-key, mixed>  $resolve
     * @return Closure(): array<array-key, mixed>
     */
    private function ofResults(Closure $results, bool $applies, Closure $resolve): Closure
    {
        return function () use ($results, $applies, $resolve): array {
            $cards = $applies ? $results() : null;

            return $cards === null ? [] : $resolve($cards);
        };
    }
}
