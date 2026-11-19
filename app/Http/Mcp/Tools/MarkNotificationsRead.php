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
 * PATCH /api/v1/notifications/{id} and POST /api/v1/notifications/read-all.
 */
#[Name('mark_notifications_read')]
#[Description('Mark one notification as read, or all of them when no id is given.')]
#[IsIdempotent]
final class MarkNotificationsRead extends LibraryTool
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
            'notification_id' => $schema->string()->format('uuid')->description('The notification, from `get_notifications`.'),
        ];
    }

    protected function answer(Request $request, HttpRequest $http): ResponseFactory|Response
    {
        return $this->relay($request->filled('notification_id')
            ? $this->api->call($http, 'PATCH', 'notifications.read', ['notification' => $request->string('notification_id')->toString()])
            : $this->api->call($http, 'POST', 'notifications.read-all'));
    }
}
