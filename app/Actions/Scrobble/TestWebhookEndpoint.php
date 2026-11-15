<?php

namespace App\Actions\Scrobble;

use App\Catalog\ExternalIdResolver;
use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Scrobble\Contracts\ProvidesSamplePayload;
use App\Integrations\Scrobble\Contracts\ScrobbleSource;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\DeliveryNote;
use App\Integrations\Scrobble\SampleDelivery;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\ExternalServiceException;
use Throwable;

/**
 * The source's own example payload, run through parsing and placing and
 * stopped there, its verdict written to the endpoint's log. It answers the
 * question the user actually has before touching their server's settings —
 * "would you understand it?" — and leaves the library alone.
 */
final readonly class TestWebhookEndpoint
{
    public function __construct(private ExternalIdResolver $resolver) {}

    public function execute(User $viewer, WebhookEndpoint $endpoint, ProvidesSamplePayload&ScrobbleSource $source): void
    {
        [$message, $event] = $this->dryRun($source, $viewer);

        $endpoint->deliveries()->create([
            'status' => WebhookDeliveryStatus::Test,
            'kind' => $event?->kind->value,
            'media_label' => $event?->label(),
            'message' => $message->value,
        ]);
    }

    /**
     * @return array{0: DeliveryNote, 1: ScrobbleEvent|null}
     */
    private function dryRun(
        ProvidesSamplePayload&ScrobbleSource $source,
        User $viewer,
    ): array {
        try {
            $event = SampleDelivery::parse($source);
        } catch (Throwable $e) {
            // The sample is ours, so a source that cannot read it is a bug
            // worth a report; the user only learns the test failed.
            report($e);

            return [DeliveryNote::TestParseFailed, null];
        }

        if ($event === null) {
            return [DeliveryNote::TestParseFailed, null];
        }

        try {
            $resolved = $this->resolver->resolve(
                $event->externalIds,
                $event->mediaKind,
                $viewer->preferredLocale(),
                $event->seasonNumber,
                $event->episodeNumber,
                $event->seriesExternalIds,
            );
        } catch (ExternalServiceException) {
            // The metadata provider being down says nothing about this
            // endpoint, and the log should not pretend otherwise.
            return [DeliveryNote::TestProviderUnreachable, $event];
        }

        return [$resolved === null ? DeliveryNote::NoExternalIdMatch : DeliveryNote::TestPassed, $event];
    }
}
