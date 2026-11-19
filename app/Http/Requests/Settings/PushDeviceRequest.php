<?php

namespace App\Http\Requests\Settings;

use App\Integrations\Connections\UnusableAddress;
use App\Integrations\Connections\UserAddresses;
use App\Integrations\WebPush\Data\BrowserSubscription;
use App\Integrations\WebPush\WebPushChannel;
use App\Models\User;
use App\Notifications\Channels\ChannelRegistry;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Minishlink\WebPush\ContentEncoding;

/**
 * The push subscription a browser made, as `PushSubscription.toJSON()` gives
 * it. The instance posts to its address, so the address is checked as any
 * other the user hands over: https, and outside the server's own network.
 */
class PushDeviceRequest extends FormRequest
{
    /** The longest push service addresses run to a few hundred characters. */
    protected const ENDPOINT_MAX = 2048;

    /** The browser's keys are a few dozen characters of base64url. */
    private const KEY_MAX = 255;

    /** What the device list shows of a browser's description. */
    private const USER_AGENT_MAX = 255;

    /** An instance without push has no devices to add. */
    public function authorize(): Response
    {
        return app(ChannelRegistry::class)->has(WebPushChannel::CODE) ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'url:https', 'max:'.self::ENDPOINT_MAX],
            'public_key' => ['required', 'string', 'max:'.self::KEY_MAX],
            'auth_token' => ['required', 'string', 'max:'.self::KEY_MAX],
            'content_encoding' => ['required', Rule::enum(ContentEncoding::class)],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $owner = $this->user();

            if (! $owner instanceof User || $validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                app(UserAddresses::class)->pin($this->string('endpoint')->value(), $owner);
            } catch (UnusableAddress $unusable) {
                $validator->errors()->add('endpoint', $unusable->wording());
            }
        }];
    }

    public function subscription(): BrowserSubscription
    {
        $userAgent = $this->userAgent();

        return new BrowserSubscription(
            endpoint: $this->string('endpoint')->value(),
            publicKey: $this->string('public_key')->value(),
            authToken: $this->string('auth_token')->value(),
            contentEncoding: $this->enum('content_encoding', ContentEncoding::class) ?? throw new LogicException('A validated request names its encoding.'),
            userAgent: $userAgent === null || $userAgent === '' ? null : Str::limit($userAgent, self::USER_AGENT_MAX, ''),
        );
    }
}
