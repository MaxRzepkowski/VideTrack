<?php

namespace App\Http\Mcp\Tools;

use App\Accounts\TokenAbility;
use App\Enums\PositionStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * PUT /api/v1/media/{type}/{ref}/status.
 */
#[Name('set_status')]
#[Description('Mark a title as dropped (with when) or on hold, or clear either: clearing resumes where the user left off, or starts afresh with `discard_progress`.')]
#[IsIdempotent]
final class SetStatus extends LibraryTool
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
            'status' => $schema->string()->enum([PositionStatus::Dropped->value, PositionStatus::OnHold->value, self::CLEARED])->description('"none" clears the status.')->required(),
            'dropped_at' => $schema->string()->description('When it was dropped: "now", "release", a day or a time. Now when left out.'),
            'discard_progress' => $schema->boolean()->description('With "none": throw away what was logged of the paused attempt.'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        $status = $request->string('status')->toString();

        return $this->relay($this->api->call($http, 'PUT', 'media.status.update', self::at($request), [
            'status' => $status === self::CLEARED ? null : $status,
            ...$request->only(['dropped_at', 'discard_progress']),
        ]));
    }
}
