<?php

namespace App\Integrations\OutgoingWebhooks;

use App\Events\Media\MediaEventDetected;
use App\Library\MutationSource;
use App\Models\OutgoingWebhook;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Traits\Localizable;

/**
 * Tells the user's webhooks what just happened. The change is queued only
 * when one of the owner's webhooks ticked its group, so a write by anybody
 * else costs one indexed look; the message is made in the queue, once, in the
 * owner's language, and goes out as one delivery per webhook. An import
 * never does: a file of five thousand rows is not five thousand messages.
 *
 * @phpstan-import-type LibraryChange from WebhookMessages
 */
final class AnnounceToWebhooks implements ShouldQueue
{
    use Localizable;

    public function __construct(private readonly WebhookMessages $messages) {}

    /**
     * @param  LibraryChange|MediaEventDetected  $event
     */
    public function shouldQueue(object $event): bool
    {
        if ($event instanceof MediaEventDetected) {
            return $this->anyListening($event->userId, WebhookEventType::NotificationCreated);
        }

        return ! $event->source->is(MutationSource::IMPORT)
            && $this->anyListening($event->userId, WebhookMessages::typeOf($event));
    }

    /**
     * @param  LibraryChange  $event
     */
    public function handleChange(object $event): void
    {
        $this->tell($event->userId, WebhookMessages::typeOf($event), fn (): WebhookMessage => $this->messages->change($event));
    }

    /** The bell got a notification: the same one, under the same id. */
    public function handleNotification(MediaEventDetected $detected): void
    {
        $notification = DatabaseNotification::query()->find($detected->notificationId);

        // Deleted from the bell before the queue got here.
        if ($notification === null) {
            return;
        }

        $this->tell($detected->userId, WebhookEventType::NotificationCreated, fn (User $owner): WebhookMessage => $this->messages->notification($notification, $owner));
    }

    /**
     * @param  callable(User): WebhookMessage  $make
     */
    private function tell(int $userId, WebhookEventType $type, callable $make): void
    {
        $webhooks = $this->listening($userId, $type);
        $owner = $webhooks->first()?->user;

        if ($owner === null) {
            return;
        }

        // A card names its title in the app's language: the owner's.
        $message = $this->withLocale($owner->preferredLocale(), fn (): WebhookMessage => $make($owner));

        foreach ($webhooks as $webhook) {
            DeliverOutgoingWebhook::dispatch($webhook, $message);
        }
    }

    /** Whether one of the user's enabled webhooks ticked the event's group: one light look, made on every write. */
    private function anyListening(int $userId, WebhookEventType $type): bool
    {
        return $this->listeningQuery($userId, $type)?->exists() ?? false;
    }

    /**
     * The user's enabled webhooks that ticked the event's group.
     *
     * @return Collection<int, OutgoingWebhook>
     */
    private function listening(int $userId, WebhookEventType $type): Collection
    {
        return $this->listeningQuery($userId, $type)?->with('user')->get() ?? new Collection;
    }

    /**
     * @return Builder<OutgoingWebhook>|null null for an event no group stands for
     */
    private function listeningQuery(int $userId, WebhookEventType $type): ?Builder
    {
        $group = $type->group();

        return $group === null ? null : OutgoingWebhook::query()
            ->where('user_id', $userId)
            ->where('enabled', true)
            ->whereJsonContains('events', $group->value);
    }
}
