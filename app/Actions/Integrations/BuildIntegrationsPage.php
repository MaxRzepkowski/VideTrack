<?php

namespace App\Actions\Integrations;

use App\Http\Data\IntegrationRef;
use App\Http\Data\IntegrationsPage;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Data\ConnectDef;
use App\Integrations\Import\Data\ConnectionSourceData;
use App\Integrations\Import\ImportSourceRegistry;
use App\Integrations\IntegrationKind;
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
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The integrations page: a card per registered media server and per import
 * source that reads through an account, so a new one of either is a class
 * and a registration. What is set up leads, in the order it was set up;
 * the rest waits to be added, by name.
 */
final readonly class BuildIntegrationsPage
{
    /** How much of an endpoint's history the card shows. */
    private const LOG_ROWS = 20;

    public function __construct(
        private ScrobbleSourceRegistry $scrobbleSources,
        private ImportSourceRegistry $importSources,
        private ConnectionRegistry $connections,
    ) {}

    public function execute(User $viewer, ?string $selected): IntegrationsPage
    {
        $endpoints = WebhookEndpoint::query()
            ->where('user_id', $viewer->id)
            // Each endpoint's latest deliveries, all in one query.
            ->with(['deliveries' => fn (Relation $deliveries) => $deliveries->latest('id')->limit(self::LOG_ROWS)])
            ->orderBy('id')
            ->get()
            ->groupBy('source');
        $held = $viewer->serviceConnections()->get()->keyBy('service');

        $webhooks = [];
        $accounts = [];
        /** @var list<array{ref: IntegrationRef, name: string, since: CarbonInterface|null}> $entries */
        $entries = [];

        foreach ($this->scrobbleSources->codes() as $code) {
            $source = $this->scrobbleSources->get($code);
            $addresses = $endpoints->get($code);

            $webhooks[] = new ScrobbleSourceData(
                code: $code,
                name: $source->name(),
                description: $source->description(),
                notice: $source->notice(),
                instructions: $source->instructions(),
                canTest: $source instanceof ProvidesSamplePayload,
                endpointLimit: config()->integer('tracker.scrobble.endpoints_per_source'),
                endpoints: array_values($addresses
                    ?->map(fn (WebhookEndpoint $endpoint): WebhookEndpointData => $this->endpoint($endpoint))
                    ->all() ?? []),
            );
            // Set up once its first address was made.
            $entries[] = ['ref' => new IntegrationRef(IntegrationKind::Webhook, $code), 'name' => $source->name(), 'since' => $addresses?->first()?->created_at];
        }

        foreach ($this->importSources->readingFromConnection() as $source) {
            $connection = $held->get($source->connectionService());

            $accounts[] = new ConnectionSourceData(
                code: $source->code(),
                label: $source->label(),
                description: $source->description(),
                notes: $source instanceof ExplainsSetup ? $source->notes() : [],
                connect: ConnectDef::of($source, $this->connections, $connection),
            );
            $entries[] = ['ref' => new IntegrationRef(IntegrationKind::Account, $source->code()), 'name' => $source->label(), 'since' => $connection?->created_at];
        }

        $connected = array_values(array_filter($entries, fn (array $entry): bool => $entry['since'] !== null));
        usort($connected, fn (array $a, array $b): int => $a['since'] <=> $b['since']);
        $available = array_values(array_filter($entries, fn (array $entry): bool => $entry['since'] === null));
        usort($available, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return new IntegrationsPage(
            webhooks: $webhooks,
            accounts: $accounts,
            connected: array_column($connected, 'ref'),
            available: array_column($available, 'ref'),
            selected: collect($accounts)->first(fn (ConnectionSourceData $account): bool => $account->code === $selected)?->code,
        );
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
