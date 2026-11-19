<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Catalog\Creators\CreatorKind;
use App\Catalog\MediaRef;
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
 * PUT /api/v1/{people|companies|networks|collections}/{provider}/{ref}/follow.
 */
#[Name('follow_creator')]
#[Description('Ring the bell on a person, a company, a network or a collection (new titles reach the calendar and the notifications), or take it away. A collection can also be muted. Find them with `search`.')]
#[IsIdempotent]
final class FollowCreator extends LibraryTool
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
            'kind' => $schema->string()->enum(array_column(CreatorKind::cases(), 'value'))->required(),
            'provider' => $schema->string()->description('The `provider` from the search result.')->required(),
            'ref' => $schema->string()->max(MediaRef::MAX_LENGTH)->description('The `ref` from the search result.')->required(),
            'mode' => $schema->string()->enum([FollowMode::Follow->value, FollowMode::Mute->value, self::CLEARED])->description('"none" takes the bell away; only a collection can be muted.')->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        $mode = $request->string('mode')->toString();

        // Where API v1 keeps each kind's bell.
        $route = match (CreatorKind::from($request->string('kind')->toString())) {
            CreatorKind::Person => 'people.follow.update',
            CreatorKind::Company => 'companies.follow.update',
            CreatorKind::Network => 'networks.follow.update',
            CreatorKind::Collection => 'collections.follow.update',
        };

        return $this->relay($this->api->call($http, 'PUT', $route, [
            'provider' => $request->string('provider')->toString(),
            'ref' => $request->string('ref')->toString(),
        ], ['mode' => $mode === self::CLEARED ? null : $mode]));
    }
}
