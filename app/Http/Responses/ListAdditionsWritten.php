<?php

namespace App\Http\Responses;

use App\Http\Resources\Api\V1\ListAdditionResource;
use App\Library\Data\ListAddition;
use App\Library\Data\UserMediaState;
use App\Library\ListAddOutcome;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How adding many titles to a list answers: what became of each, in the
 * order asked (`data`), how many of each outcome and how long the list is
 * now (`meta`), and the states of the titles from the call that are on it
 * (`states`, keyed "type:ref"). Partly done is still 200: each title says how it went.
 * Built by {@see ListAdditionWrites}.
 */
final readonly class ListAdditionsWritten implements Responsable
{
    /**
     * @param  list<ListAddition>  $additions
     * @param  array<string, UserMediaState>  $states
     */
    public function __construct(
        private array $additions,
        private int $itemsCount,
        private array $states,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return response()->json([
            /** @var list<ListAdditionResource> */
            'data' => array_map(fn (ListAddition $addition): array => (new ListAdditionResource($addition))->toArray($request), $this->additions),
            'meta' => [
                'added' => $this->count(ListAddOutcome::Added),
                'present' => $this->count(ListAddOutcome::Present),
                'notFound' => $this->count(ListAddOutcome::NotFound),
                'unavailable' => $this->count(ListAddOutcome::Unavailable),
                'itemsCount' => $this->itemsCount,
            ],
            'states' => $this->states,
        ]);
    }

    private function count(ListAddOutcome $outcome): int
    {
        return count(array_filter($this->additions, fn (ListAddition $addition): bool => $addition->outcome === $outcome));
    }
}
