<?php

namespace App\Integrations\Scrobble;

use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Jobs\ProcessScrobbleEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;

/**
 * What happens to a play once a sender's payload has been read: it is
 * queued for the user, or set aside and logged with the reason. Every
 * outcome lands in the endpoint's delivery log, which is what the
 * integrations page shows when something does not arrive.
 */
final readonly class ScrobbleIntake
{
    /** How long a repeat of the identical body counts as the same delivery. */
    private const DEDUP_SECONDS = 900;

    /**
     * Queue the event for the endpoint's user, unless it is somebody else's
     * viewing or a retransmission. True when it was queued.
     */
    public function take(WebhookEndpoint $endpoint, ScrobbleEvent $event, string $body): bool
    {
        // A household shares one server: this endpoint belongs to one person,
        // and the rest of the house playing something is not this person
        // watching it. Filtered before the job — there is nothing to resolve
        // about somebody else's viewing.
        if ($this->belongsToSomebodyElse($endpoint, $event)) {
            $this->log($endpoint, WebhookDeliveryStatus::Ignored, $event, DeliveryNote::UserMismatch);

            return false;
        }

        // Retransmissions: the same body to the same endpoint within the
        // window is one delivery, not two.
        if (! Cache::add('scrobble:rx:'.hash('sha256', $endpoint->id.'|'.$body), true, self::DEDUP_SECONDS)) {
            $this->log($endpoint, WebhookDeliveryStatus::Ignored, $event, DeliveryNote::DuplicateDelivery);

            return false;
        }

        // The event is read already: its payload has nothing left to explain,
        // and kept it would only hold the household's account names and
        // addresses for the retention period.
        $delivery = $this->log($endpoint, WebhookDeliveryStatus::Received, $event);

        ProcessScrobbleEvent::dispatch($delivery->id, $event);

        return true;
    }

    /**
     * The enabled endpoint a delivery is addressed to, of an account that is
     * not blocked — a blocked account's endpoints are as good as gone.
     */
    public function endpoint(string $source, string $token): ?WebhookEndpoint
    {
        $endpoint = WebhookEndpoint::query()
            ->withToken($token)
            ->where('source', $source)
            ->where('enabled', true)
            ->with('user')
            ->first();

        return $endpoint === null || $endpoint->user->isBlocked() ? null : $endpoint;
    }

    /**
     * The card's "last heard from": a server posting every few seconds of
     * playback needs it no finer than the minute, so it is written at most
     * once a minute rather than on every request.
     */
    public function heardFrom(WebhookEndpoint $endpoint): void
    {
        if ($endpoint->last_received_at === null || $endpoint->last_received_at->lt(now()->subMinute())) {
            $endpoint->forceFill(['last_received_at' => now()])->save();
        }
    }

    /**
     * A payload that never became an event, logged with why — and with what
     * was sent, where looking at it is the way to fix the parser.
     *
     * @param  array<array-key, mixed>|null  $payload
     */
    public function setAside(WebhookEndpoint $endpoint, WebhookDeliveryStatus $status, DeliveryNote $reason, ?array $payload = null): void
    {
        $this->log($endpoint, $status, note: $reason, payload: $payload);
    }

    /**
     * Only when the user named an account: unfiltered is the right default
     * for the one-person server, and a source that cannot tell us who played
     * never makes the filter fire — it would silence the endpoint entirely.
     */
    private function belongsToSomebodyElse(WebhookEndpoint $endpoint, ScrobbleEvent $event): bool
    {
        $wanted = $endpoint->serverUsername();

        if ($wanted === null || $event->serverUsername === null) {
            return false;
        }

        // Media servers are inconsistent about the case they report.
        return mb_strtolower(trim($event->serverUsername)) !== mb_strtolower($wanted);
    }

    /**
     * @param  array<array-key, mixed>|null  $payload
     */
    private function log(
        WebhookEndpoint $endpoint,
        WebhookDeliveryStatus $status,
        ?ScrobbleEvent $event = null,
        ?DeliveryNote $note = null,
        ?array $payload = null,
    ): WebhookDelivery {
        /** @var WebhookDelivery */
        return $endpoint->deliveries()->create([
            'status' => $status,
            'kind' => $event?->kind->value,
            'media_label' => $event?->label(),
            'message' => $note?->value,
            'payload' => $payload,
        ]);
    }
}
