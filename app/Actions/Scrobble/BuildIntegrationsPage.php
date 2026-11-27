<?php

namespace App\Actions\Scrobble;

use App\Integrations\Scrobble\Contracts\ProvidesSamplePayload;
use App\Integrations\Scrobble\Data\DeliveryRow;
use App\Integrations\Scrobble\Data\ScrobbleSourceData;
use App\Integrations\Scrobble\Data\WebhookEndpointData;
use App\Integrations\Scrobble\DeliveryNote;
use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\LoopbackAddress;
use App\Support\StoredReason;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The integrations page: one card per registered scrobble source, so a new
 * source is a class and a registration — the card, the address, the
 * instructions and the log come for free.
 */
final readonly class BuildIntegrationsPage
{
    /** How much of an endpoint's history the card shows. */
    private const LOG_ROWS = 20;

    public function __construct(private ScrobbleSourceRegistry $registry) {}

    /**
     * @return list<ScrobbleSourceData>
     */
    public function execute(User $viewer): array
    {
        $endpoints = WebhookEndpoint::query()
            ->where('user_id', $viewer->id)
            // Each endpoint's latest deliveries, all in one query.
            ->with(['deliveries' => fn (Relation $deliveries) => $deliveries->latest('id')->limit(self::LOG_ROWS)])
            ->orderBy('id')
            ->get()
            ->groupBy('source');

        $sources = [];

        foreach ($this->registry->codes() as $code) {
            $source = $this->registry->get($code);

            $sources[] = new ScrobbleSourceData(
                code: $code,
                name: $source->name(),
                description: $source->description(),
                notice: $source->notice(),
                instructions: $source->instructions(),
                canTest: $source instanceof ProvidesSamplePayload,
                endpointLimit: config()->integer('tracker.scrobble.endpoints_per_source'),
                endpoints: array_values($endpoints->get($code)
                    ?->map(fn (WebhookEndpoint $endpoint): WebhookEndpointData => $this->endpoint($endpoint))
                    ->all() ?? []),
            );
        }

        return $sources;
    }

    private function endpoint(WebhookEndpoint $endpoint): WebhookEndpointData
    {
        $url = route('webhooks.ingest', ['source' => $endpoint->source, 'token' => $endpoint->token]);

        return new WebhookEndpointData(
            id: $endpoint->id,
            label: $endpoint->label,
            enabled: $endpoint->enabled,
            serverUsername: $endpoint->serverUsername(),
            url: $url,
            // Said out loud only when it is true: the default APP_URL is
            // localhost, and a media server in its own container hears that
            // as itself. The delivery then never leaves the sender, so
            // there is no log row to explain the silence.
            loopback: LoopbackAddress::is($url),
            lastReceivedAt: $endpoint->last_received_at?->toIso8601String(),
            // The job stores a translation key, never exception text.
            lastError: StoredReason::worded($endpoint->last_error),
            deliveries: array_values($endpoint->deliveries
                ->map(fn (WebhookDelivery $delivery): DeliveryRow => new DeliveryRow(
                    id: $delivery->id,
                    status: $delivery->status,
                    kind: $delivery->kind,
                    label: $delivery->media_label,
                    // A note under a name this version does not know reads as none.
                    message: DeliveryNote::tryFrom((string) $delivery->message),
                    at: $delivery->created_at?->toIso8601String(),
                    // What the server actually sent, kept for the payloads
                    // we could not read — that is the only way to find out
                    // which field this build of it renamed.
                    payload: $delivery->payload === null
                        ? null
                        // A payload that is not valid UTF-8 still shows, marked where it broke.
                        : json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                ))
                ->all()),
        );
    }
}
