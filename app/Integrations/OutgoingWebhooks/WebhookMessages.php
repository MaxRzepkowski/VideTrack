<?php

namespace App\Integrations\OutgoingWebhooks;

use App\Catalog\MediaItemSummaries;
use App\Catalog\PublicShapes;
use App\Library\Data\ListTarget;
use App\Library\Data\PlayEntry;
use App\Library\Data\RecordedPlay;
use App\Library\Events\FavoriteSet;
use App\Library\Events\ListItemAdded;
use App\Library\Events\ListItemRemoved;
use App\Library\Events\MediaStatusChanged;
use App\Library\Events\PlayRecorded;
use App\Library\Events\PlayRemoved;
use App\Library\Events\ProgressLogged;
use App\Library\Events\RatingChanged;
use App\Library\Events\RatingRemoved;
use App\Library\Events\RewatchStarted;
use App\Models\Company;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\Person;
use App\Models\Play;
use App\Models\User;
use App\Notifications\NotificationCopy;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;

/**
 * What a webhook is told, and in which words: a change to the library as the
 * API would show what changed (a title's card, a play, a list), or a
 * notification as the API lists it. Only the changes named here leave; the
 * rest of the library's events (reordering, a creator's bell, a cleared
 * account) stay inside.
 *
 * @phpstan-type LibraryChange PlayRecorded|PlayRemoved|ProgressLogged|RewatchStarted|RatingChanged|RatingRemoved|FavoriteSet|MediaStatusChanged|ListItemAdded|ListItemRemoved
 */
final readonly class WebhookMessages
{
    public function __construct(
        private MediaItemSummaries $summaries,
        private NotificationCopy $copy,
    ) {}

    /** The library's changes a webhook is told of, each under its code. */
    public const TYPES = [
        PlayRecorded::class => WebhookEventType::PlayRecorded,
        PlayRemoved::class => WebhookEventType::PlayRemoved,
        ProgressLogged::class => WebhookEventType::ProgressLogged,
        RewatchStarted::class => WebhookEventType::RewatchStarted,
        RatingChanged::class => WebhookEventType::RatingChanged,
        RatingRemoved::class => WebhookEventType::RatingRemoved,
        FavoriteSet::class => WebhookEventType::FavoriteChanged,
        MediaStatusChanged::class => WebhookEventType::StatusChanged,
        ListItemAdded::class => WebhookEventType::ListItemAdded,
        ListItemRemoved::class => WebhookEventType::ListItemRemoved,
    ];

    /**
     * @param  LibraryChange  $event
     */
    public static function typeOf(object $event): WebhookEventType
    {
        return self::TYPES[$event::class];
    }

    /**
     * @param  LibraryChange  $event
     */
    public function change(object $event): WebhookMessage
    {
        $data = match (true) {
            $event instanceof PlayRecorded => $this->recorded($event),
            $event instanceof PlayRemoved => ['media' => $this->card($event->itemId), 'playIds' => $event->playIds],
            $event instanceof ProgressLogged => ['media' => $this->card($event->itemId), 'added' => $event->added, 'set' => $event->set, 'progress' => $event->progress],
            $event instanceof RewatchStarted => ['media' => $this->card($event->rootId), 'cycle' => $event->cycle],
            $event instanceof RatingChanged => ['media' => $this->card($event->itemId), 'rating' => $event->rating, 'previous' => $event->previous],
            $event instanceof RatingRemoved => ['media' => $this->card($event->itemId)],
            $event instanceof FavoriteSet => ['media' => $this->card($event->itemId), 'favorite' => $event->favorite],
            $event instanceof MediaStatusChanged => ['media' => $this->card($event->itemId), 'status' => $event->status?->value, 'previous' => $event->previous?->value],
            $event instanceof ListItemAdded, $event instanceof ListItemRemoved => $this->listed($event->listId, $event->target),
        };

        return new WebhookMessage((string) Str::uuid7(), self::typeOf($event), now()->toIso8601String(), $data, $event->source->value());
    }

    /** A notification the bell just got, under the id the API gives it: a repeat of it is recognisable. */
    public function notification(DatabaseNotification $notification, User $user): WebhookMessage
    {
        return new WebhookMessage(
            $notification->id,
            WebhookEventType::NotificationCreated,
            ($notification->created_at ?? throw new LogicException('A stored notification has its time.'))->toIso8601String(),
            ['notification' => $this->copy->entry($notification, $user->preferredLocale())->jsonSerialize()],
        );
    }

    /**
     * One message for the whole batch, each play with the card of what it closed.
     *
     * @return array<string, mixed>
     */
    private function recorded(PlayRecorded $event): array
    {
        $plays = Play::query()->with('watchCycle')->whereIn('id', array_map(fn (RecordedPlay $play): int => $play->playId, $event->plays))->orderBy('id')->get();
        $items = $this->items([$event->itemId, ...array_map(fn (RecordedPlay $play): int => $play->itemId, $event->plays)]);

        return [
            'media' => $this->cardOf($items, $event->itemId),
            'plays' => $plays->map(fn (Play $play): array => [
                ...PlayEntry::of($play)->jsonSerialize(),
                'media' => $this->cardOf($items, $play->media_item_id),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listed(int $listId, ListTarget $target): array
    {
        $list = MediaList::query()->find($listId);
        $person = $target->personId === null ? null : Person::query()->find($target->personId);
        $company = $target->companyId === null ? null : Company::query()->find($target->companyId);

        return [
            // The list by what names it; its entries are the API's to list.
            'list' => $list === null ? null : ['id' => $list->id, 'title' => $list->displayTitle()],
            'media' => $target->itemId === null ? null : $this->card($target->itemId),
            'person' => $person === null ? null : PublicShapes::person($person),
            'company' => $company === null ? null : PublicShapes::company($company),
        ];
    }

    /**
     * A title's card as the API shows it; null for one deleted since.
     *
     * @return array<string, mixed>|null
     */
    private function card(int $itemId): ?array
    {
        return $this->cardOf($this->items([$itemId]), $itemId);
    }

    /**
     * @param  Collection<int, MediaItem>  $items
     * @return array<string, mixed>|null
     */
    private function cardOf(Collection $items, int $itemId): ?array
    {
        $item = $items->get($itemId);

        return $item === null ? null : PublicShapes::card($this->summaries->inContext($item));
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, MediaItem> by id
     */
    private function items(array $ids): Collection
    {
        return MediaItem::query()->with('parent.parent')->whereIn('id', array_unique($ids))->get()->keyBy(fn (MediaItem $item): int => $item->id);
    }
}
