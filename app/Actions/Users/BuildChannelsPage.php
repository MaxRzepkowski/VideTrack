<?php

namespace App\Actions\Users;

use App\Http\Data\ChannelsPage;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\DailyDigests;
use App\Notifications\Channels\Data\ChannelData;
use App\Notifications\Channels\Data\ChannelKindData;
use App\Notifications\Channels\DeliveryAnswer;
use App\Support\Payload;

/**
 * The notification channels page, drawn from the channel registry: a service
 * added later gets its tile, its form and its row without a line of UI.
 */
final readonly class BuildChannelsPage
{
    public function __construct(private ChannelRegistry $drivers) {}

    public function execute(User $viewer): ChannelsPage
    {
        $kinds = [];

        foreach ($this->drivers->codes() as $driver) {
            foreach ($this->drivers->get($driver)->kinds() as $kind) {
                $kinds[] = ChannelKindData::of($driver, $kind);
            }
        }

        // One alphabet across drivers: the picker lists services, not how they are reached.
        usort($kinds, fn (ChannelKindData $a, ChannelKindData $b): int => strnatcasecmp($a->label, $b->label));

        $channels = [];

        foreach ($viewer->notificationChannels()->orderBy('id')->get() as $channel) {
            // A driver the instance no longer has stays saved, out of sight.
            if ($this->drivers->has($channel->driver)) {
                $channels[] = $this->row($channel, $kinds);
            }
        }

        return new ChannelsPage($channels, $kinds, NotificationChannel::LABEL_MAX, ChannelMode::default(), DailyDigests::HOUR);
    }

    /**
     * @param  list<ChannelKindData>  $kinds
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
            lastFailure: $this->failure($channel->last_failure),
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

    /** How the last message failed, in the reader's language; null when it arrived. */
    private function failure(?DeliveryAnswer $answer): ?string
    {
        if ($answer === null || $answer === DeliveryAnswer::Delivered) {
            return null;
        }

        return __(match ($answer) {
            DeliveryAnswer::Gone => 'settings.channel_failure_gone',
            DeliveryAnswer::Refused => 'settings.channel_failure_refused',
            DeliveryAnswer::Throttled => 'settings.channel_failure_throttled',
            DeliveryAnswer::Unavailable => 'settings.channel_failure_unavailable',
        });
    }
}
