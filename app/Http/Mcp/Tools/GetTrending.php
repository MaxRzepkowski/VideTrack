<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Catalog\Discovery;
use App\Catalog\MediaTypeRegistry;
use App\Http\Mcp\ApiV1;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * GET /api/v1/discover/{type}.
 */
#[Name('get_trending')]
#[Description('What is trending, popular and coming up in one media type right now, as the catalogue lists it, each title with the user\'s `state` (so what they have seen shows). Asks the catalogue, so call it once per type rather than per question.')]
#[IsReadOnly]
final class GetTrending extends LibraryTool
{
    protected function ability(): TokenAbility
    {
        return TokenAbility::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum($this->discovery->sectionCodes())->description('The media type.')->required(),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($this->api->call($http, 'GET', 'discover', ['type' => $request->string('type')->toString()]));
    }

    public function __construct(ApiV1 $api, MediaTypeRegistry $types, private readonly Discovery $discovery)
    {
        parent::__construct($api, $types);
    }
}
