<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every resource of API v1: its lists page in the API's own spelling.
 */
abstract class ApiResource extends JsonResource
{
    /**
     * @param  mixed  $resource
     */
    protected static function newCollection($resource): AnonymousResourceCollection
    {
        return new ApiCollection($resource, static::class);
    }
}
