<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * DELETE /api/v1/media/{type}/{ref}/rating.
 */
#[Name('remove_rating')]
#[Description('Take away the user\'s rating of a title, season or episode.')]
#[IsIdempotent]
final class RemoveRating extends LibraryTool
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
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'DELETE', 'media.rating.destroy', self::at($request)));
    }
}
