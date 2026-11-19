<?php

namespace App\Actions\Users;

use App\Http\Data\ChannelsPage;
use App\Integrations\WebPush\PushDevices;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\DailyDigests;
use App\Notifications\Channels\Data\ChannelData;
use App\Notifications\Channels\Data\ChannelKindData;
use App\Support\Payload;

/**
 * The notification channels page, drawn from the channel registry: a service
 * added later gets its tile, its form and its row without a line of UI.
 */
final readonly class BuildChannelsPage
{
    public function __construct(
        private ChannelRegistry $drivers,
        private PushDevices $pushDevices,
    ) {}

    public function execute(User $viewer): ChannelsPage
    {
        $kinds = [];
        $offered = [];

        foreach ($this->drivers->codes() as $driver) {
            foreach ($this->drivers->get($driver)->kinds() as $kind) {
                $kinds[] = $data = ChannelKindData::of($driver, $kind);

                if (! $kind->fromDevices) {
                    $offered[] = $data;
                }
            }
        }

        // One alphabet across drivers: the picker lists services, not how they are reached.
        usort($offered, fn (ChannelKindData $a, ChannelKindData $b): int => strnatcasecmp($a->label, $b->label));

        $channels = [];

        foreach ($viewer->notificationChannels()->orderBy('id')->get() as $channel) {
            // A driver the instance no longer has stays saved, out of sight.
            if ($this->drivers->has($channel->driver)) {
                $channels[] = $this->row($channel, $kinds);
            }
        }

        return new ChannelsPage(
            channels: $channels,
            kinds: $offered,
            labelMax: NotificationChannel::LABEL_MAX,
            defaultMode: ChannelMode::default(),
            digestHour: DailyDigests::HOUR,
            push: $this->pushDevices->of($viewer),
        );
    }

    /**
     * @param  list<ChannelKindData>  $kinds  every kind, the ones devices make included
     */
    private function row(NotificationChannel $channel, array $kinds): ChannelData
    {
        $kind = array_find($kinds, fn (ChannelKindData $kind): bool => $kind->driver === $channel->driver && $kind->code === $channel->kind);

        return new ChannelData(
            id: $channel->id,
            driver: $channel->driver,
            kind: $channel->kind,
            kindLabel: $kind?->label,
            label: $channel->label,
            mode: $channel->mode,
            enabled: $channel->enabled,
            values: $this->plainValues($channel, $kind),
            lastFailure: $channel->last_failure?->failureWording(),
        );
    }

    /**
     * The saved values the page may show again: every field but a secret.
     *
     * @return array<string, string>
     */
    private function plainValues(NotificationChannel $channel, ?ChannelKindData $kind): array
    {
        $values = [];

        foreach ($kind === null ? [] : $kind->fields as $field) {
            if (! $field->secret) {
                $values[$field->key] = Payload::text($channel->config, $field->key);
            }
        }

        return $values;
    }
}
