<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PageRequest;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\User;
use App\Notifications\NotificationCopy;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    /**
     * The user's notification feed, newest first: each one worded in the
     * language of the request, the raw event data beside it for a client that
     * words its own.
     *
     * @return AnonymousResourceCollection<int, NotificationResource>
     */
    public function index(#[CurrentUser] User $viewer, PageRequest $request, NotificationCopy $copy): AnonymousResourceCollection
    {
        $locale = App::getLocale();

        return NotificationResource::collection(
            $viewer->notifications()->paginate($request->perPage())->withQueryString()
                ->through(fn (DatabaseNotification $notification): NotificationResource => new NotificationResource($copy->entry($notification, $locale))),
        );
    }

    public function read(#[CurrentUser] User $viewer, string $notification): Response
    {
        // Scoped to the caller's own feed — a foreign id just 404s.
        /** @var DatabaseNotification $found */
        $found = $viewer->notifications()->findOrFail($notification);

        $found->markAsRead();

        return response()->noContent();
    }

    public function readAll(#[CurrentUser] User $viewer): Response
    {
        $viewer->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
