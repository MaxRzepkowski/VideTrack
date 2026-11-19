<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Enums\FollowMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * PUT /api/v1/media/{type}/{ref}/follow.
 */
#[Name('follow_releases')]
#[Description('Ring the bell on a title (its releases reach the calendar and the notifications), mute it, or hand it back to the user\'s tracking settings.')]
#[IsIdempotent]
final class FollowReleases extends LibraryTool
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
            'mode' => $schema->string()->enum([FollowMode::Follow->value, FollowMode::Mute->value, self::CLEARED])->description('"none" hands the title back to the tracking settings.')->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        $mode = $request->string('mode')->toString();

        return $this->relay($this->api->call($http, 'PUT', 'media.follow.update', self::at($request), ['mode' => $mode === self::CLEARED ? null : $mode]));
    }
}
