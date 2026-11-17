<?php

namespace App\Integrations\Apprise;

use App\Integrations\Apprise\Data\AppriseService;
use App\Integrations\Apprise\Data\AppriseToken;
use App\Integrations\Connections\UnusableAddress;
use App\Integrations\Connections\UserAddresses;
use App\Models\User;
use App\Notifications\Channels\ChannelText;
use App\Notifications\Channels\Contracts\ChannelDriver;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelKind;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Notifications\Channels\DeliveryAnswer;
use App\Notifications\Channels\UnusableChannelSettings;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;
use App\Support\Payload;
use LogicException;

/**
 * Every service the instance's Apprise container delivers to — Telegram,
 * ntfy, e-mail, Matrix, SMS gateways and dozens more — each a kind with the
 * fields its own schema names. The user fills those in; the Apprise URL is
 * put together here, so nobody writes one by hand and no field reaches a
 * server the user may not. Every service reads plain text: a message is its
 * title, its facts and a link, and the digest one message Apprise splits
 * where a service caps the length.
 */
final readonly class AppriseChannel implements ChannelDriver
{
    public const CODE = 'apprise';

    /** Where the settings keep the Apprise URL made of the fields. */
    public const URLS = 'apprise_urls';

    /** The field of a service that also takes an unencrypted connection. */
    public const PLAIN_CONNECTION = 'plain_connection';

    /** Offered first: the services most people already use. */
    private const POPULAR = ['tgram', 'ntfys', 'mailtos', 'signals', 'whatsapp', 'matrixs', 'slack', 'gotifys', 'pover', 'hassios', 'mmosts', 'workflow'];

    /**
     * Services whose messages name a sender, by the URL argument that sets
     * it: an e-mail comes from the instance, not from "Apprise".
     */
    private const SENDER_ARGUMENT = ['mailtos' => 'from'];

    /** The token that names a server: it may not lead an ordinary account into a private network. */
    private const HOST = 'host';

    public function __construct(
        private AppriseServices $services,
        private AppriseClient $apprise,
        private UserAddresses $addresses,
        private ChannelText $text,
    ) {}

    public function kinds(): array
    {
        return array_map(fn (AppriseService $service): ChannelKind => new ChannelKind(
            code: $service->code,
            label: $service->name,
            fields: $this->fields($service),
            setupUrl: $service->setupUrl,
            popular: in_array($service->code, self::POPULAR, true),
        ), $this->services->all());
    }

    public function rules(string $kind): array
    {
        return [];
    }

    public function configure(string $kind, array $values, User $owner): array
    {
        $service = $this->services->find($kind) ?? throw new LogicException('A validated request names a service Apprise offers.');
        $parts = [];

        foreach ($service->tokens as $key => $token) {
            $value = Payload::text($values, $key);

            if ($value !== '') {
                $parts[$key] = $this->part($token, $value, $owner);
            }
        }

        $template = $service->templateFor(array_keys($parts)) ?? throw new UnusableChannelSettings('', 'settings.fill_in_what_the_service_needs');
        $plain = $service->plainScheme !== null && Payload::text($values, self::PLAIN_CONNECTION) === '1';

        $url = $template->fill($plain ? $service->plainScheme : $service->code, $parts);
        $sender = self::SENDER_ARGUMENT[$service->code] ?? null;

        // The fields are kept beside the URL made of them: the page shows the
        // plain ones again, and a secret left empty on a later change stays.
        return [
            ...array_filter($values, fn (string $value): bool => $value !== ''),
            self::URLS => $sender === null ? $url : $url.'?'.http_build_query([$sender => config()->string('app.name')]),
        ];
    }

    public function deliver(ChannelMessage $message, array $config): DeliveryAnswer
    {
        return $this->apprise->send(Payload::text($config, self::URLS), $message->title, $this->text->message($message));
    }

    public function deliverDigest(ChannelDigest $digest, array $config): array
    {
        return [$this->apprise->send(Payload::text($config, self::URLS), $digest->heading, $this->text->digest($digest))];
    }

    /**
     * @return list<CredentialField>
     */
    private function fields(AppriseService $service): array
    {
        $fields = [];

        foreach ($service->tokens as $key => $token) {
            $fields[] = new CredentialField(
                key: $key,
                label: $token->name,
                type: match (true) {
                    $token->secret => CredentialFieldType::Password,
                    $token->type === AppriseTokenType::Email => CredentialFieldType::Email,
                    default => CredentialFieldType::Text,
                },
                help: $this->help($token),
                optional: ! $service->needs($key),
                secret: $token->secret,
            );
        }

        if ($service->plainScheme !== null) {
            $fields[] = new CredentialField(self::PLAIN_CONNECTION, __('settings.connect_without_encryption'), CredentialFieldType::Checkbox, __('settings.only_for_a_server_in_your_own_network'));
        }

        return $fields;
    }

    /** What a field says under it: how a list is typed, the values a choice takes. */
    private function help(AppriseToken $token): ?string
    {
        if ($token->isList) {
            return __('settings.several_separated_by_spaces');
        }

        if ($token->choices === []) {
            return null;
        }

        return __('settings.one_of_values', ['values' => implode(', ', $token->choices)]);
    }

    /** One value as its place in the URL takes it, checked against what the schema says it is. */
    private function part(AppriseToken $token, string $value, User $owner): string
    {
        if ($token->isList) {
            $items = preg_split('/[\s,]+/', $value, flags: PREG_SPLIT_NO_EMPTY) ?: [];

            return implode($token->delimiter, array_map(rawurlencode(...), $items));
        }

        $refusal = match (true) {
            $token->type === AppriseTokenType::Number && ! ctype_digit($value) => 'settings.this_has_to_be_a_whole_number',
            $token->type === AppriseTokenType::Email && filter_var($value, FILTER_VALIDATE_EMAIL) === false => 'settings.this_has_to_be_an_email_address',
            $token->choices !== [] && ! in_array($value, $token->choices, true) => 'settings.this_has_to_be_one_of_the_values',
            $token->pattern !== null && preg_match($token->pattern, $value) !== 1 => 'settings.the_service_does_not_take_this_value',
            default => null,
        };

        if ($refusal !== null) {
            throw new UnusableChannelSettings($token->key, $refusal);
        }

        if ($token->key === self::HOST) {
            $this->checkHost($value, $owner);

            return $value;
        }

        return rawurlencode($value);
    }

    /** A server name or address the user may reach; no path, no credentials smuggled into the host part. */
    private function checkHost(string $host, User $owner): void
    {
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) === false && filter_var($bare, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new UnusableChannelSettings(self::HOST, 'settings.this_is_not_a_server_name');
        }

        try {
            $this->addresses->pin('https://'.$host, $owner);
        } catch (UnusableAddress $unusable) {
            throw new UnusableChannelSettings(self::HOST, $unusable->key);
        }
    }
}
