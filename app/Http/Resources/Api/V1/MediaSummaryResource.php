<?php

namespace App\Http\Resources\Api\V1;

use App\Catalog\Data\MediaSummary;
use App\Catalog\PublicShapes;
use App\Library\Data\UserMediaState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A media position as a card: enough to show it and to address it again
 * (`type` + `ref` are the coordinates every other endpoint takes). Where the
 * caller knows what the user has done with it, `state` comes along — the
 * same state the pages render.
 *
 * @mixin MediaSummary
 */
class MediaSummaryResource extends ApiResource
{
    public function __construct(MediaSummary $summary, private readonly ?UserMediaState $state = null)
    {
        parent::__construct($summary);
    }

    /**
     * Summaries without a state. The inherited collection() would build each
     * resource with its list key as the second argument, which here is the
     * state, so bare summaries are wrapped first.
     *
     * @param  mixed  $resource
     * @return AnonymousResourceCollection<int, self>
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        if (is_array($resource)) {
            $resource = array_map(fn (mixed $item): mixed => $item instanceof MediaSummary ? new self($item) : $item, $resource);
        }

        return parent::collection($resource);
    }

    /**
     * Each summary with its state from the library's "type:ref" map.
     *
     * @param  iterable<MediaSummary>  $summaries
     * @param  array<string, UserMediaState>  $states
     * @return AnonymousResourceCollection<int, self>
     */
    public static function withStates(iterable $summaries, array $states): AnonymousResourceCollection
    {
        $resources = [];

        foreach ($summaries as $summary) {
            $resources[] = new self($summary, $states[$summary->key()] ?? null);
        }

        return self::collection($resources);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MediaSummary $summary */
        $summary = $this->resource;

        return [
            ...PublicShapes::card($summary),
            'state' => $this->when($this->state !== null, $this->state),
        ];
    }
}
