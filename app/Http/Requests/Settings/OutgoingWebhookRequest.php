<?php

namespace App\Http\Requests\Settings;

use App\Integrations\Connections\UserAddresses;
use App\Integrations\OutgoingWebhooks\Data\OutgoingWebhookSettings;
use App\Integrations\OutgoingWebhooks\WebhookEventGroup;
use App\Integrations\OutgoingWebhooks\WebhookSignature;
use App\Models\OutgoingWebhook;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A webhook added, or one the user changed. Changing one sends only what
 * changed: the switch alone, or the form, where a secret left empty keeps the
 * saved one. An http(s) address the owner may reach, by the rule every
 * address a user gives follows (`UserAddresses`).
 */
class OutgoingWebhookRequest extends FormRequest
{
    /** Somebody else's webhook is a 404 before its fields are looked at. */
    public function authorize(): Response
    {
        $webhook = $this->webhook();

        return $webhook === null ? Response::allow() : Gate::inspect('update', $webhook);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(#[CurrentUser] User $viewer): array
    {
        $adding = $this->webhook() === null;

        return [
            'label' => ['nullable', 'string', 'max:'.OutgoingWebhook::LABEL_MAX],
            'url' => ['bail', $adding ? 'required' : 'sometimes', 'string', 'max:'.OutgoingWebhook::URL_MAX, 'url:http,https', app(UserAddresses::class)->rule($viewer)],
            'secret' => [$adding ? 'required' : 'nullable', 'string', $this->validSecret(...)],
            'events' => [$adding ? 'present' : 'sometimes', 'array'],
            'events.*' => ['distinct', Rule::enum(WebhookEventGroup::class)],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $owner = $this->user();

            if ($this->webhook() === null && $owner instanceof User && $owner->outgoingWebhooks()->count() >= OutgoingWebhook::PER_USER) {
                $validator->errors()->add('url', trans_choice('settings.you_can_keep_at_most_webhooks', OutgoingWebhook::PER_USER, ['count' => OutgoingWebhook::PER_USER]));
            }
        }];
    }

    /** What the user sent; whatever was not sent is null. */
    public function settings(): OutgoingWebhookSettings
    {
        return new OutgoingWebhookSettings(
            label: $this->has('label') ? trim($this->string('label')->value()) : null,
            url: $this->has('url') ? trim($this->string('url')->value()) : null,
            secret: $this->filled('secret') ? trim($this->string('secret')->value()) : null,
            events: $this->has('events') ? array_values($this->enums('events', WebhookEventGroup::class)) : null,
            enabled: $this->has('enabled') ? $this->boolean('enabled') : null,
        );
    }

    private function validSecret(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! WebhookSignature::isValidSecret(trim($value))) {
            $fail(__('settings.signing_secret_is_not_valid'));
        }
    }

    private function webhook(): ?OutgoingWebhook
    {
        $webhook = $this->route('webhook');

        return $webhook instanceof OutgoingWebhook ? $webhook : null;
    }
}
