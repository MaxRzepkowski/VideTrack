<?php

namespace App\Http\Controllers\Media;

use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorPages;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Data\CompanyCredit;
use App\Catalog\ItemNotFoundException;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Http\Controllers\Controller;
use App\Library\LibraryStates;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The chunks a company or network page asks for after its first one — the
 * route says which.
 *
 * Plain JSON on purpose, not an Inertia partial: the page appends to what it
 * already shows, and an Inertia visit would repaint it — an open <select>
 * would lose its list under the pointer.
 */
class CreatorCreditsController extends Controller
{
    /** Chunk 1 ships with the page itself, so the first one worth asking for is 2. */
    private const FIRST_CHUNK_TO_ASK = 2;

    public function __invoke(
        #[CurrentUser] User $viewer,
        Request $request,
        CreatorPages $pages,
        LibraryStates $states,
        string $provider,
        string $ref,
        string $kind,
    ): JsonResponse {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = max(self::FIRST_CHUNK_TO_ASK, $request->integer('page'));

        $creator = new CreatorRef(CreatorKind::from($kind), $provider, $ref);

        abort_unless($pages->serves($creator), 404);

        try {
            $chunk = $pages->credits($creator, App::getLocale(), $viewer->preferences->matureContent(), $page);
        } catch (ItemNotFoundException|ProviderException|ProviderRejected) {
            // The catalogue simply stops growing — the page keeps what it has
            // and says so once, rather than retrying into a dead provider.
            return response()->json(['credits' => [], 'hasMore' => false, 'userStates' => []], 503);
        }

        return response()->json([
            'credits' => $chunk->credits,
            'hasMore' => $chunk->hasMore,
            // The cards carry the shared action bar, which reads its state from
            // the page's userStates map; the chunk brings its own share of it.
            'userStates' => $states->resolve(
                $viewer,
                array_map(fn (CompanyCredit $credit) => $credit->media, $chunk->credits),
            ),
        ]);
    }
}
