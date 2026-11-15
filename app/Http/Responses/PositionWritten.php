<?php

namespace App\Http\Responses;

use App\Catalog\Data\MediaSummary;
use App\Http\Resources\Api\V1\MediaSummaryResource;
use App\Library\Data\UserMediaState;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How every write on a position answers: the position as it now stands
 * (`data`, with its state), every state the write touched (`states`, the
 * position and the containers above it, keyed "type:ref") and, where the
 * write counts something, `meta`. Built by {@see PositionWrites}.
 */
final readonly class PositionWritten implements Responsable
{
    /**
     * @param  array<string, UserMediaState>  $states  from AffectedStates
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        private MediaSummary $summary,
        private array $states,
        private array $meta = [],
        private int $status = 200,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return response()->json([
            /** @var MediaSummaryResource */
            'data' => (new MediaSummaryResource($this->summary, $this->states[$this->summary->key()]))->resolve($request),
            'states' => $this->states,
            ...($this->meta === [] ? [] : ['meta' => $this->meta]),
        ], $this->status);
    }
}
