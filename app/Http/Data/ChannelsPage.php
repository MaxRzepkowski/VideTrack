<?php

namespace App\Http\Data;

use App\Integrations\WebPush\Data\PushDevicesData;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\Data\ChannelData;
use App\Notifications\Channels\Data\ChannelKindData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The notification channels page: the user's channels, the kinds they can add and the browsers push reaches. */
#[TypeScript]
final readonly class ChannelsPage
{
    /**
     * @param  list<ChannelData>  $channels
     * @param  list<ChannelKindData>  $kinds
     */
    public function __construct(
        public array $channels,
        public array $kinds,
        public int $labelMax,
        public ChannelMode $defaultMode,
        /** The local hour a digest goes out at. */
        public int $digestHour,
        /** Null on an instance without push. */
        public ?PushDevicesData $push,
    ) {}
}
