<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Library\RatingScale;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * PUT /api/v1/media/{type}/{ref}/rating.
 */
#[Name('rate')]
#[Description('Rate a title, season or episode from '.RatingScale::MIN.' to '.RatingScale::MAX.', replacing the user\'s earlier rating. To take a rating away, use `remove_rating`.')]
#[IsIdempotent]
final class Rate extends LibraryTool
{
    protected function ability(): TokenAbility
    {
        return TokenAbility::Write;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->position($schema),
            'rating' => $schema->integer()->min(RatingScale::MIN)->max(RatingScale::MAX)->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'PUT', 'media.rating.update', self::at($request), ['rating' => $request->integer('rating')]));
    }
}
