<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Library\SyncCreatorLists;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Http\Controllers\Controller;
use App\Library\CreatorStateQuery;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Which of the user's lists a person, a studio or a network sits on — the
 * route says which. Somebody who never got a row simply sits on none.
 */
class CreatorListsController extends Controller
{
    public function show(#[CurrentUser] User $viewer, CreatorStateQuery $states, string $provider, string $ref, string $kind): JsonResponse
    {
        return $this->membership($viewer, $states, new CreatorRef(CreatorKind::from($kind), $provider, $ref));
    }

    public function update(
        #[CurrentUser] User $viewer,
        Request $request,
        SyncCreatorLists $sync,
        CreatorStateQuery $states,
        string $provider,
        string $ref,
        string $kind,
    ): JsonResponse {
        /** @var array{list_ids: list<int>} $validated */
        $validated = $request->validate([
            'list_ids' => ['present', 'array'],
            'list_ids.*' => ['integer'],
        ]);

        $creator = new CreatorRef(CreatorKind::from($kind), $provider, $ref);
        $sync->execute($viewer, $creator, $validated['list_ids'], App::getLocale());

        return $this->membership($viewer, $states, $creator);
    }

    private function membership(User $viewer, CreatorStateQuery $states, CreatorRef $creator): JsonResponse
    {
        $state = $states->of($viewer, [$creator]);

        return response()->json([
            'data' => ['listIds' => $state[$creator->key()]['listIds'] ?? []],
            'creatorStates' => $state,
        ]);
    }
}
