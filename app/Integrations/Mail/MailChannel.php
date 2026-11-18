<?php

namespace App\Integrations\Mail;

use App\Models\User;
use App\Notifications\Channels\ChannelText;
use App\Notifications\Channels\Contracts\ChannelDriver;
use App\Notifications\Channels\Data\ChannelDigest;
use App\Notifications\Channels\Data\ChannelKind;
use App\Notifications\Channels\Data\ChannelMessage;
use App\Support\Delivery\DeliveryAnswer;
use App\Support\Forms\CredentialField;
use App\Support\Forms\CredentialFieldType;
use App\Support\Payload;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Notifications to an e-mail address, sent by the instance's own mail
 * settings (the ones the password reset uses): the user names an address and
 * nothing else. The headline is the subject, the facts and the link the text.
 */
final readonly class MailChannel implements ChannelDriver
{
    public const CODE = 'mail';

    public const ADDRESS = 'address';

    public function __construct(private ChannelText $text) {}

    public function kinds(): array
    {
        return [new ChannelKind(
            code: self::CODE,
            label: __('settings.email_from_this_instance'),
            fields: [new CredentialField(self::ADDRESS, __('settings.email_address'), CredentialFieldType::Email, __('settings.sent_by_this_instance_no_password'))],
            popular: true,
        )];
    }

    public function rules(string $kind): array
    {
        return [self::ADDRESS => ['email']];
    }

    public function configure(string $kind, array $values, User $owner): array
    {
        return [self::ADDRESS => Payload::text($values, self::ADDRESS)];
    }

    public function deliver(ChannelMessage $message, array $config): DeliveryAnswer
    {
        return $this->send(Payload::text($config, self::ADDRESS), $message->title, $this->text->message($message));
    }

    public function deliverDigest(ChannelDigest $digest, array $config): array
    {
        return [$this->send(Payload::text($config, self::ADDRESS), $digest->heading, $this->text->digest($digest))];
    }

    private function send(string $address, string $subject, string $body): DeliveryAnswer
    {
        try {
            Mail::raw($body, fn (Message $mail) => $mail->to($address)->subject($subject));
        } catch (TransportExceptionInterface) {
            return DeliveryAnswer::Unavailable;
        }

        return DeliveryAnswer::Delivered;
    }
}
