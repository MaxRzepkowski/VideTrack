<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Catalog\MediaRef;
use App\Catalog\MediaTypeRegistry;
use App\Http\Mcp\ApiAnswer;
use App\Http\Mcp\ApiV1;
use App\Http\Mcp\Compact;
use App\Models\User;
use App\Support\Payload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\JsonSchema\JsonSchema as Schema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\JsonSchema\ValidationRules;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * A tool of the library server: a door to API v1 for an assistant. The
 * token's ability decides whether the tool exists for the caller at all,
 * the input is checked against the very schema the client was shown, and
 * the route's answer comes back compact, or, when the route refused, as a
 * sentence the assistant can act on.
 */
abstract class LibraryTool extends Tool
{
    /** "Clear it": the spelling of the API's null among a field's values (a status, a bell). */
    protected const CLEARED = 'none';

    public function __construct(
        protected readonly ApiV1 $api,
        protected readonly MediaTypeRegistry $types,
    ) {}

    /** The token ability the tool needs (API v1's `read` / `write`). */
    abstract protected function ability(): TokenAbility;

    /** The tool's work, on input already validated against its schema. */
    abstract protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response;

    /**
     * A token without the ability does not see the tool, so an assistant is
     * never offered a write it would be refused.
     */
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->tokenCan($this->ability()->value);
    }

    public function handle(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        $request->validate(ValidationRules::for(Schema::object($this->schema(...))));

        return $this->answer($request, $http);
    }

    /**
     * The route's answer as the tool's result: compact, less the top-level
     * keys that mean nothing outside HTTP (a page's links); a refusal as a
     * sentence.
     */
    protected function relay(ApiAnswer $answer): ResponseFactory|Response
    {
        if (! $answer->succeeded()) {
            return $this->refusal($answer);
        }

        // An empty `data` stays: it says "none", which a missing one would not.
        $body = array_filter(
            array_map(Compact::of(...), array_diff_key($answer->object(), ['links' => true])),
            fn (mixed $value, string $key): bool => $key === 'data' || ($value !== null && $value !== []),
            ARRAY_FILTER_USE_BOTH,
        );

        return Response::structured($body === [] ? ['done' => true] : $body);
    }

    /** What the route refused, worded for the assistant. */
    protected function refusal(ApiAnswer $answer): Response
    {
        $body = $answer->object();
        $fields = array_merge(...array_values(array_map(Payload::texts(...), Payload::named($body['errors'] ?? null))));

        return Response::error(match ($answer->status) {
            422 => $fields === [] ? (Payload::string($body, 'message') ?? __('mcp.the_library_could_not_answer')) : implode(' ', $fields),
            404 => __('mcp.nothing_at_this_address'),
            403 => __('mcp.this_token_may_not_do_that'),
            429 => __('mcp.too_many_requests'),
            503 => __('mcp.the_catalogue_could_not_answer'),
            default => __('mcp.the_library_could_not_answer'),
        });
    }

    /**
     * The `type` + `ref` pair that addresses a position, as API v1 takes it in
     * its path.
     *
     * @return array<string, Type>
     */
    protected function position(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_keys($this->types->all()))->description('The media type, from a title in an earlier answer.')->required(),
            'ref' => $schema->string()->pattern('^'.MediaRef::PATTERN.'$')->max(MediaRef::MAX_LENGTH)
                ->description('The ref that goes with the type, from the same answer.')->required(),
        ];
    }

    /**
     * The route parameters of the position the request names.
     *
     * @return array{type: string, ref: string}
     */
    protected static function at(Request $request): array
    {
        return ['type' => $request->string('type')->toString(), 'ref' => $request->string('ref')->toString()];
    }
}
