<?php

namespace App\Actions\Scrobble;

use App\Catalog\ExternalIdResolver;
use App\Catalog\TitleStore;
use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\Data\ScrobbleOutcome;
use App\Integrations\Scrobble\DeliveryNote;
use App\Library\MutationContext;
use App\Library\MutationSource;
use App\Models\WebhookDelivery;
use Throwable;

/**
 * One delivery taken to its end: the title placed, the event applied to the
 * library as coming from the endpoint's source, and the log row saying how
 * it went. A delivery that changes nothing is a normal outcome — the row says
 * which of the several nothings it was.
 */
final readonly class SettleDelivery
{
    /** What the endpoint card says when the last delivery could not be saved; the worker logs the exception. */
    public const SAVE_FAILED = 'integrations.the_last_delivery_could_not_be_saved';

    public function __construct(
        private ExternalIdResolver $resolver,
        private TitleStore $persist,
        private ApplyScrobbleEvent $apply,
        private MutationContext $mutations,
    ) {}

    /**
     * @param  WebhookDelivery  $delivery  with its endpoint and the endpoint's user loaded
     *
     * @throws Throwable a failure is logged on the row and rethrown, for the queue to try again
     */
    public function execute(WebhookDelivery $delivery, ScrobbleEvent $event): void
    {
        $endpoint = $delivery->webhookEndpoint;
        $user = $endpoint->user;

        try {
            $resolved = $this->resolver->resolve(
                $event->externalIds,
                $event->mediaKind,
                $user->preferredLocale(),
                $event->seasonNumber,
                $event->episodeNumber,
                $event->seriesExternalIds,
            );

            if ($resolved === null) {
                $this->settle($delivery, $event, WebhookDeliveryStatus::Unmatched, DeliveryNote::NoExternalIdMatch);

                return;
            }

            $item = $this->persist->persist($resolved->type, $resolved->ref, $user->preferredLocale());

            $outcome = $this->mutations->run(
                MutationSource::scrobble($endpoint->source),
                fn (): ScrobbleOutcome => $this->apply->execute($user, $item, $event),
            );

            $this->settle($delivery, $event, $outcome->status, $outcome->note);

            $endpoint->forceFill(['last_error' => null])->save();
        } catch (Throwable $e) {
            // Rethrown, so the worker reports it and tries again; the card
            // says only that saving failed — the message may carry a query.
            $this->settle($delivery, $event, WebhookDeliveryStatus::Error, DeliveryNote::ProcessingFailed);
            $endpoint->forceFill(['last_error' => self::SAVE_FAILED])->save();

            throw $e;
        }
    }

    private function settle(WebhookDelivery $delivery, ScrobbleEvent $event, WebhookDeliveryStatus $status, ?DeliveryNote $note): void
    {
        $delivery->forceFill([
            'status' => $status,
            'message' => $note?->value,
            'media_label' => $delivery->media_label ?? $event->label(),
        ])->save();
    }
}
