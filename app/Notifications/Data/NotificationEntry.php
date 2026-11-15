<?php

namespace App\Notifications\Data;

use App\Notifications\MediaEventType;
use App\Notifications\NotificationCopy;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One notification as the bell, the notifications pages and the API show it,
 * worded in the reader's language ({@see NotificationCopy}),
 * beside the event data it was stored with.
 */
#[TypeScript]
final readonly class NotificationEntry implements JsonSerializable
{
    /**
     * @param  MediaEventType|null  $event  null for an event this version does not know (an old row)
     * @param  list<string>  $detail  the facts under the headline, each its own part: the views set them apart with the hairline rule
     * @param  string  $href  the title's page, or the notification's own page when it lists several titles
     * @param  string|null  $imagePath  a provider image path
     * @param  string  $mediaTitle  the title the notification is about: its picture's alt text
     * @param  array<array-key, mixed>  $data  the event as stored
     */
    public function __construct(
        public string $id,
        public ?string $readAt,
        public ?string $createdAt,
        public ?MediaEventType $event,
        public string $title,
        public array $detail,
        public string $href,
        public ?string $imagePath,
        public string $mediaTitle,
        public array $data,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     readAt: string|null,
     *     createdAt: string|null,
     *     event: value-of<MediaEventType>|null,
     *     title: string,
     *     detail: list<string>,
     *     href: string,
     *     imagePath: string|null,
     *     mediaTitle: string,
     *     data: array<array-key, mixed>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'readAt' => $this->readAt,
            'createdAt' => $this->createdAt,
            'event' => $this->event?->value,
            'title' => $this->title,
            'detail' => $this->detail,
            'href' => $this->href,
            'imagePath' => $this->imagePath,
            'mediaTitle' => $this->mediaTitle,
            'data' => $this->data,
        ];
    }
}
