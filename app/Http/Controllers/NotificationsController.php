<?php

namespace App\Http\Controllers;

use App\Actions\BuildNotificationFeed;
use App\Actions\BuildNotificationPage;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\User;
use App\Notifications\NotificationCopy;
use App\Support\PageInfo;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

class NotificationsController extends Controller
{
    private const PER_PAGE = 20;

    public function index(#[CurrentUser] User $viewer, NotificationCopy $copy): Response
    {
        $page = $viewer->notifications()->paginate(self::PER_PAGE);

        // Not `notifications`: that name is the bell's shared prop, which a
        // page prop of the same name would hide from the header.
        return Inertia::render('Notifications', [
            'entries' => NotificationResource::rendered($page->items(), $copy, App::getLocale())->resolve(),
            'pagination' => PageInfo::of($page),
        ]);
    }

    /**
     * What the bell shows, as plain JSON. A queued job writes a notification
     * at a moment nobody navigates — Discord gets it instantly, the open tab
     * would learn about it only on the next page load. Polled with `fetch`,
     * never with `router`: an Inertia visit re-renders whatever the user is
     * reading, right under the pointer.
     */
    public function feed(#[CurrentUser] User $viewer, BuildNotificationFeed $feed): JsonResponse
    {
        return response()->json($feed->execute($viewer, App::getLocale()));
    }

    /**
     * A notification that lists several titles opens on its own page.
     * Opening it is reading it, as opening it from the bell would be.
     */
    public function show(#[CurrentUser] User $viewer, string $notification, BuildNotificationPage $page, NotificationCopy $copy): Response
    {
        /** @var DatabaseNotification $found */
        $found = $viewer->notifications()->findOrFail($notification);

        $found->markAsRead();

        return Inertia::render('NotificationDetails', [
            'notification' => (new NotificationResource($copy->entry($found, App::getLocale())))->resolve(),
            ...$page->execute($viewer, $found),
        ]);
    }

    public function destroyAll(#[CurrentUser] User $viewer): RedirectResponse
    {
        $viewer->notifications()->delete();

        return back();
    }

    public function destroy(#[CurrentUser] User $viewer, string $notification): RedirectResponse
    {
        // Scoped to the user's own feed — a foreign id just 404s.
        $viewer->notifications()->findOrFail($notification)->delete();

        return back();
    }
}
