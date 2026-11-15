<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Library\SetCreatorFollow;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\Creators\CreatorRef;
use App\Enums\FollowMode;
use App\Http\Controllers\Controller;
use App\Library\CreatorStateQuery;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

/**
 * The bell on whoever makes titles: a person, a company or network, a
 * provider collection. People and companies have no tracking matrix behind
 * them, so their bell is on or off (`mode`: `follow` or null); a collection
 * can also be muted, like a title.
 */
class CreatorFollowController extends Controller
{
    public function __construct(private readonly CreatorStateQuery $creatorStates) {}

    /** A person, a studio / publisher or a network: the route says which. */
    public function update(#[CurrentUser] User $viewer, Request $request, SetCreatorFollow $setFollow, string $provider, string $ref, string $kind): JsonResponse
    {
        /** @var array{mode: string|null} $validated */
        $validated = $request->validate(['mode' => ['present', 'nullable', Rule::in([FollowMode::Follow->value])]]);

        $mode = $validated['mode'] === null ? null : FollowMode::Follow;

        return $this->ring($viewer, new CreatorRef(CreatorKind::from($kind), $provider, $ref), $mode, $setFollow);
    }

    /** A collection can also be muted, like a title. */
    public function collection(#[CurrentUser] User $viewer, Request $request, SetCreatorFollow $setFollow, string $provider, string $ref): JsonResponse
    {
        /** @var array{mode: string|null} $validated */
        $validated = $request->validate(['mode' => ['present', 'nullable', 'string', Rule::enum(FollowMode::class)]]);

        $mode = $validated['mode'] === null ? null : FollowMode::from($validated['mode']);

        return $this->ring($viewer, new CreatorRef(CreatorKind::Collection, $provider, $ref), $mode, $setFollow);
    }

    private function ring(User $viewer, CreatorRef $creator, ?FollowMode $mode, SetCreatorFollow $setFollow): JsonResponse
    {
        $setFollow->execute($viewer, $creator, $mode, App::getLocale());

        return response()->json([
            'data' => ['provider' => $creator->provider, 'ref' => $creator->ref, 'mode' => $mode?->value],
            // What a client holding this creator shows now — like `states` of a title write.
            'creatorStates' => $this->creatorStates->of($viewer, [$creator]),
        ]);
    }
}
