<?php

namespace App\Notifications\Channels\Contracts;

use App\Models\User;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelKind;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Notifications\Channels\UnusableChannelSettings;
use App\Support\Delivery\DeliveryAnswer;

/**
 * A way notifications leave the instance, for one service or many (its
 * kinds). It lays out words NotificationCopy already wrote and hands them to
 * its service's client; it never writes copy of its own, and a failure comes
 * back as an answer, never as an exception, so one dead channel stops nothing
 * else. Every setting is a credential: once saved, it never comes back to
 * the page.
 */
interface ChannelDriver
{
    /**
     * The services this driver delivers to on this instance.
     *
     * @return list<ChannelKind>
     */
    public function kinds(): array;

    /**
     * What a value typed into a field of a kind must be beyond its presence
     * and length — an address of the service's own, say, so the instance
     * posts nowhere else.
     *
     * @return array<string, list<string>> field key => validation rules
     */
    public function rules(string $kind): array;

    /**
     * The settings a channel of the kind keeps, made of the values the user
     * typed (validated against fields() and rules()).
     *
     * @param  array<string, string>  $values  field key => value; empty when left empty
     * @return array<string, string>
     *
     * @throws UnusableChannelSettings
     */
    public function configure(string $kind, array $values, User $owner): array;

    /**
     * @param  array<string, mixed>  $config  what configure() returned
     */
    public function deliver(ChannelMessage $message, array $config): DeliveryAnswer;

    /**
     * Sends a day's digest — as several messages when the service caps their
     * length — and stops at the first one not delivered.
     *
     * @param  array<string, mixed>  $config  what configure() returned
     * @return non-empty-list<DeliveryAnswer> one per message sent
     */
    public function deliverDigest(ChannelDigest $digest, array $config): array;
}
