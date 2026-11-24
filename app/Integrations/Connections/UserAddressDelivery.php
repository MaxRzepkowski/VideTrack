<?php

namespace App\Integrations\Connections;

use App\Models\User;
use App\Support\Delivery\DeliveryAnswer;
use App\Support\Delivery\DeliveryHttpClient;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * A message posted to an address a user gave (a webhook, a browser's push
 * service): checked for its owner before every message, as `UserAddresses`
 * rules, and sent to the IP that check approved with no redirect followed.
 * An address the owner may not reach is the instance's own refusal, with
 * nobody asked; a name that resolves to nothing may resolve on a later try.
 */
final readonly class UserAddressDelivery
{
    public function __construct(
        private DeliveryHttpClient $http,
        private UserAddresses $addresses,
    ) {}

    /**
     * @param  list<int>  $gone  the statuses by which the receiver says the address leads nowhere
     * @param  Closure(PendingRequest): Response  $send  the receiver's own request, held to the approved IP
     * @param  list<int>  $closed  the statuses by which it says the address is closed for good
     */
    public function deliver(string $url, User $owner, int $timeoutSeconds, array $gone, Closure $send, array $closed = []): DeliveryAnswer
    {
        try {
            $address = $this->addresses->pin($url, $owner);
        } catch (AddressRefused) {
            return DeliveryAnswer::NotAllowed;
        } catch (AddressNotFound) {
            return DeliveryAnswer::Unavailable;
        }

        return $this->http->deliver($timeoutSeconds, $gone, fn (PendingRequest $request): Response => $send($address->hold($request)), $closed);
    }
}
