<?php

namespace App\Integrations\Discord;

use App\Models\User;
use App\Notifications\Channels\Contracts\ChannelDriver;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelKind;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Notifications\Channels\DeliveryAnswer;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;
use App\Support\Payload;

/**
 * A Discord webhook the user made in their own channel: no bot, no OAuth.
 * A notification is one embed; the digest is plain text messages.
 */
final readonly class DiscordChannel implements ChannelDriver
{
    public const CODE = 'discord';

    public const WEBHOOK = 'webhook_url';

    /** Discord's own guide to making a webhook. */
    private const SETUP_URL = 'https://support.discord.com/hc/articles/228383668';

    public function __construct(
        private DiscordClient $discord,
        private DiscordDigestComposer $composer,
    ) {}

    public function kinds(): array
    {
        return [new ChannelKind(
            code: self::CODE,
            label: 'Discord',
            fields: [new CredentialField(self::WEBHOOK, __('settings.webhook_address'), CredentialFieldType::Url, __('settings.discord_webhook_where'), secret: true)],
            setupUrl: self::SETUP_URL,
            popular: true,
        )];
    }

    public function rules(string $kind): array
    {
        return [self::WEBHOOK => ['url', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/']];
    }

    public function configure(string $kind, array $values, User $owner): array
    {
        return [self::WEBHOOK => Payload::text($values, self::WEBHOOK)];
    }

    public function deliver(ChannelMessage $message, array $config): DeliveryAnswer
    {
        $embed = [
            'title' => $message->title,
            'url' => $message->url,
            'color' => $message->color,
            'timestamp' => $message->writtenAt,
        ];

        if ($message->detail !== null) {
            $embed['description'] = $message->detail;
        }

        if ($message->imageUrl !== null) {
            $embed['thumbnail'] = ['url' => $message->imageUrl];
        }

        return $this->discord->send(Payload::text($config, self::WEBHOOK), ['embeds' => [$embed]]);
    }

    public function deliverDigest(ChannelDigest $digest, array $config): array
    {
        $answers = [];

        foreach ($this->composer->messages($digest) as $content) {
            $answers[] = $answer = $this->discord->send(Payload::text($config, self::WEBHOOK), ['content' => $content]);

            if ($answer !== DeliveryAnswer::Delivered) {
                break;
            }
        }

        return $answers;
    }
}
