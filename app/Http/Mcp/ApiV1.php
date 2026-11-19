<?php

namespace App\Http\Mcp;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Facade;
use JsonException;

/**
 * API v1 as the MCP tools reach it: a route called inside this process,
 * with the token of the MCP call, through the whole HTTP stack. The tools
 * are one more client of the API, like scripts and VideTrack's own pages:
 * its validation, policies, token abilities, actions and mutation source
 * are the only ones, and a tool only says which route and what to send.
 */
final readonly class ApiV1
{
    public function __construct(
        private Kernel $kernel,
        private Container $container,
        private UrlGenerator $urls,
    ) {}

    /**
     * The route is named, so one that moves fails here, loudly, instead of
     * reaching the assistant as "nothing there".
     *
     * @param  string  $route  the name of an API v1 route, less its `api.v1.` prefix
     * @param  array<string, string|int>  $parameters  the route's parameters
     * @param  array<array-key, mixed>  $input  the query of a GET, the JSON body of any other method
     *
     * @throws JsonException
     */
    public function call(HttpRequest $caller, string $method, string $route, array $parameters = [], array $input = []): ApiAnswer
    {
        $reads = $method === 'GET';

        $request = HttpRequest::create(
            uri: $this->urls->route('api.v1.'.$route, $parameters, absolute: false),
            method: $method,
            parameters: $reads ? $input : [],
            server: [
                'HTTP_AUTHORIZATION' => (string) $caller->header('Authorization'),
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => (string) $caller->ip(),
            ],
            content: $reads ? null : json_encode($input, JSON_THROW_ON_ERROR),
        );

        try {
            $response = $this->kernel->handle($request);
        } finally {
            // The kernel leaves the inner request bound; the rest of the MCP
            // call (URLs, the next tool call) belongs to the outer one.
            $this->container->instance('request', $caller);
            Facade::clearResolvedInstance('request');
        }

        $content = (string) $response->getContent();

        return new ApiAnswer(
            $response->getStatusCode(),
            $content === '' ? [] : json_decode($content, true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
