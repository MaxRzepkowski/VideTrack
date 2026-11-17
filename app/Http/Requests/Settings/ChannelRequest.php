<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Settings\Concerns\ReadsCredentialFields;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelMode;
use App\Notifications\Channels\ChannelRegistry;
use App\Notifications\Channels\Data\ChannelKind;
use App\Notifications\Channels\Data\ChannelSettings;
use App\Notifications\Channels\UnusableChannelSettings;
use App\Support\Payload;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * A channel added, or one the user changed. The fields come from the kind of
 * channel (its driver describes them); the driver then turns the values into
 * the settings it keeps, and refuses what cannot make a channel under the
 * field at fault. Changing a channel sends only what changed: the switch
 * alone, the name and mode, or a new address with all of its fields.
 */
class ChannelRequest extends FormRequest
{
    use ReadsCredentialFields;

    /** Several targets with their tokens make a long value. */
    private const VALUE_MAX = 2048;

    /** @var array<string, string>|null what the driver made of the fields */
    private ?array $configured = null;

    /**
     * Somebody else's channel is a 404 before its fields are looked at, and
     * so is one whose driver this instance no longer has.
     */
    public function authorize(): Response
    {
        $channel = $this->channel();

        return match (true) {
            $channel === null => Response::allow(),
            ! $this->drivers()->has($channel->driver) => Response::denyAsNotFound(),
            default => Gate::inspect('update', $channel),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $adding = $this->channel() === null;
        $rules = [
            'driver' => $adding ? ['required', 'string', Rule::in($this->drivers()->codes())] : ['prohibited'],
            'kind' => $adding ? ['required', 'string'] : ['prohibited'],
            'label' => ['nullable', 'string', 'max:'.NotificationChannel::LABEL_MAX],
            'mode' => ['sometimes', Rule::enum(ChannelMode::class)],
            'enabled' => ['sometimes', 'boolean'],
            'config' => [$adding ? 'present' : 'sometimes', 'array'],
        ];

        $kind = $this->kind();

        if ($kind === null || ! $this->has('config')) {
            return $rules;
        }

        $fieldRules = $this->fieldRules($kind->fields, 'config', self::VALUE_MAX);

        // A saved secret stays when its field is left empty.
        foreach ($this->channel() === null ? [] : $kind->fields as $field) {
            if ($field->secret && $field->required()) {
                $fieldRules["config.{$field->key}"] = ['nullable', 'string', 'max:'.self::VALUE_MAX];
            }
        }

        foreach ($this->drivers()->get($this->driverCode())->rules($kind->code) as $key => $extra) {
            $fieldRules["config.{$key}"] = [...$fieldRules["config.{$key}"] ?? [], ...$extra];
        }

        return [...$rules, ...$fieldRules];
    }

    /**
     * A kind this instance does not offer is named as such, and the values
     * that pass the rules go to the driver, which may still refuse them.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $owner = $this->user();

            if ($validator->errors()->isNotEmpty() || ! $owner instanceof User) {
                return;
            }

            $kind = $this->kind();
            $adding = $this->channel() === null;

            // A saved channel whose service cannot be listed just now keeps
            // its settings; its name, mode and switch can still change.
            if ($kind === null && ($adding || $this->has('config'))) {
                $validator->errors()->add($adding ? 'kind' : 'config', $adding ? __('settings.this_service_is_not_offered') : __('settings.apprise_is_out_of_reach'));

                return;
            }

            if ($kind === null || ! $this->has('config')) {
                return;
            }

            try {
                $this->configured = $this->drivers()->get($this->driverCode())
                    ->configure($kind->code, $this->withSavedSecrets($kind), $owner);
            } catch (UnusableChannelSettings $unusable) {
                $validator->errors()->add($unusable->field === '' ? 'config' : "config.{$unusable->field}", $unusable->wording());
            }
        }];
    }

    /**
     * What the form sent, a secret left empty taken from what is saved: the
     * page never holds a saved secret to send back.
     *
     * @return array<string, string>
     */
    private function withSavedSecrets(ChannelKind $kind): array
    {
        $values = $this->fieldValues($kind->fields, 'config');
        $saved = $this->channel()->config ?? [];

        foreach ($kind->fields as $field) {
            if ($field->secret && $values[$field->key] === '') {
                $values[$field->key] = Payload::text($saved, $field->key);
            }
        }

        return $values;
    }

    /** The driver of the channel being added or changed. */
    public function driverCode(): string
    {
        return $this->channel()->driver ?? $this->string('driver')->value();
    }

    /** Its kind, among the driver's. */
    public function kindCode(): string
    {
        return $this->channel()->kind ?? $this->string('kind')->value();
    }

    /** What the user sent; whatever was not sent is null. */
    public function settings(): ChannelSettings
    {
        return new ChannelSettings(
            label: $this->has('label') ? trim($this->string('label')->value()) : null,
            mode: $this->enum('mode', ChannelMode::class),
            enabled: $this->has('enabled') ? $this->boolean('enabled') : null,
            config: $this->has('config')
                ? $this->configured ?? throw new LogicException('A validated request has its settings configured.')
                : null,
        );
    }

    /** The kind of channel, which validation found offered when adding. */
    public function channelKind(): ChannelKind
    {
        return $this->kind() ?? throw new LogicException('A validated request names a kind this instance offers.');
    }

    /** The kind of the channel, null while the instance cannot list it. */
    public function kind(): ?ChannelKind
    {
        return $this->drivers()->kindOf($this->driverCode(), $this->kindCode());
    }

    private function channel(): ?NotificationChannel
    {
        $channel = $this->route('channel');

        return $channel instanceof NotificationChannel ? $channel : null;
    }

    private function drivers(): ChannelRegistry
    {
        return app(ChannelRegistry::class);
    }
}
