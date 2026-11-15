<?php

namespace App\Http\Resources\Api\V1;

use App\Notifications\Data\NotificationEntry;
use App\Notifications\NotificationCopy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One notification: what it says, in the language of the request, where it
 * leads and its picture, beside the stored event data ({@see NotificationEntry}).
 * VideTrack only ever notifies about external facts — a premiere, a moved
 * date, a new season, a title gone from its provider — so it names the fact,
 * not a prompt.
 *
 * @mixin NotificationEntry
 */
class NotificationResource extends ApiResource
{
    public function __construct(private readonly NotificationEntry $entry)
    {
        parent::__construct($entry);
    }

    /**
     * Each notification with its copy.
     *
     * @param  iterable<DatabaseNotification>  $notifications
     * @return AnonymousResourceCollection<int, self>
     */
    public static function rendered(iterable $notifications, NotificationCopy $copy, string $locale): AnonymousResourceCollection
    {
        $resources = [];

        foreach ($notifications as $notification) {
            $resources[] = new self($copy->entry($notification, $locale));
        }

        return self::collection($resources);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->entry->jsonSerialize();
    }
}
