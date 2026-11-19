<?php

use App\Library\MutationSource;
use App\Models\MediaItem;
use App\Models\Play;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * The door itself, over HTTP like a real client: the token, its abilities,
 * the operator's switch and the server's name.
 */
function mcpRequest(string $method, array $params = [], ?string $token = null): TestResponse
{
    $test = test();

    if ($token !== null) {
        $test->withToken($token);
    }

    return $test->postJson(route('api.mcp'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

const MCP_READING_TOOLS = [
    'search', 'check_titles', 'get_title', 'get_history', 'get_library', 'get_up_next', 'get_calendar', 'get_countdowns', 'get_statistics',
    'get_lists', 'get_list', 'get_suggestions', 'get_notifications',
];

const MCP_WRITING_TOOLS = [
    'mark_watched', 'unmark_watched', 'delete_history_entry', 'rate', 'remove_rating', 'set_status', 'log_progress', 'set_favorite',
    'follow_releases', 'follow_creator', 'start_rewatch', 'cancel_rewatch', 'create_list', 'update_list', 'delete_list',
    'add_to_list', 'remove_from_list', 'mark_notifications_read',
];

/** @return list<string> */
function mcpToolNames(TestResponse $response): array
{
    return array_column($response->assertOk()->json('result.tools'), 'name');
}

test('the server answers no one without a token', function () {
    mcpRequest('tools/list')->assertUnauthorized();
});

test('a token that is not ours is turned away', function () {
    mcpRequest('tools/list', token: 'not-a-real-token')->assertUnauthorized();
});

test('a read token sees the reading tools and no write', function () {
    $token = User::factory()->create()->createToken('assistant', ['read'])->plainTextToken;

    expect(mcpToolNames(mcpRequest('tools/list', token: $token)))
        ->toEqualCanonicalizing(MCP_READING_TOOLS);
});

test('a write token sees every tool', function () {
    $token = User::factory()->create()->createToken('assistant', ['read', 'write'])->plainTextToken;

    expect(mcpToolNames(mcpRequest('tools/list', token: $token)))
        ->toEqualCanonicalizing([...MCP_READING_TOOLS, ...MCP_WRITING_TOOLS]);
});

test('a read token cannot write by naming a write tool', function () {
    $user = User::factory()->create();
    $token = $user->createToken('assistant', ['read'])->plainTextToken;

    mcpRequest('tools/call', ['name' => 'mark_watched', 'arguments' => ['type' => 'movie', 'ref' => '603']], $token)
        ->assertJsonPath('error.message', 'Tool [mark_watched] not found.');

    expect(Play::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('a tool writes with the token of the call, through the api', function () {
    $user = User::factory()->create();
    $token = $user->createToken('assistant', ['read', 'write'])->plainTextToken;
    $movie = MediaItem::factory()->movie()->create();

    mcpRequest('tools/call', ['name' => 'mark_watched', 'arguments' => ['type' => 'movie', 'ref' => $movie->provider_ref]], $token)
        ->assertJsonPath('result.structuredContent.data.state.watched', true);

    expect(Play::query()->where('user_id', $user->id)->sole()->source)->toBe(MutationSource::api()->value());
});

test('a blocked account cannot use its tokens here either', function () {
    $user = User::factory()->blocked()->create();
    $token = $user->createToken('assistant', ['read'])->plainTextToken;

    mcpRequest('tools/list', token: $token)->assertForbidden();
});

test('the operator can close the server', function () {
    config(['tracker.mcp_enabled' => false]);
    $token = User::factory()->create()->createToken('assistant', ['read'])->plainTextToken;

    mcpRequest('tools/list', token: $token)->assertNotFound();
});

test('a closed server does not say it exists, even to a caller without a token', function () {
    config(['tracker.mcp_enabled' => false]);

    mcpRequest('tools/list')->assertNotFound();
});

test('the server names itself after the instance', function () {
    config(['app.name' => 'Home tracker']);
    $token = User::factory()->create()->createToken('assistant', ['read'])->plainTextToken;

    mcpRequest('initialize', [
        'protocolVersion' => '2025-11-25',
        'capabilities' => [],
        'clientInfo' => ['name' => 'test', 'version' => '1'],
    ], $token)
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Home tracker')
        ->assertJsonPath('result.serverInfo.version', config('tracker.version'));
});
