<?php

namespace App\Http\Controllers;

use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Scrobble\Data\ScrobbleEvent;
use App\Integrations\Scrobble\DeliveryNote;
use App\Integrations\Scrobble\ScrobbleIntake;
use App\Integrations\Scrobble\ScrobbleSourceRegistry;
use App\Integrations\Scrobble\UnrecognizedPayload;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Where media servers post what they just played.
 *
 * Two rules shape every answer here. **Fast**: senders have short timeouts and
 * treat a slow reply as a failure, so parsing happens now and everything that
 * might talk to a provider happens in a job — 202 goes back immediately.
 * **Never 5xx**: some servers disable a webhook after a run of errors, so a
 * payload we cannot use is logged and answered 204. The only 404s are the
 * ones the sender must act on: a wrong or disabled address — and the only
 * 422s go to the generic webhook, whose sender is a person debugging a script.
 */
class WebhookIngestController extends Controller
{
    /** Bigger than any real playback payload; past this we log and drop. */
    public const MAX_PAYLOAD_BYTES = 256 * 1024;

    public function __invoke(
        Request $request,
        ScrobbleSourceRegistry $registry,
        ScrobbleIntake $intake,
        string $source,
        string $token,
    ): Response {
        if (! $registry->has($source)) {
            abort(404);
        }

        $endpoint = $intake->endpoint($source, $token) ?? abort(404);

        $intake->heardFrom($endpoint);

        $body = $this->received($request);

        if (strlen($body) > self::MAX_PAYLOAD_BYTES) {
            $intake->setAside($endpoint, WebhookDeliveryStatus::Error, DeliveryNote::PayloadTooLarge);

            return response()->noContent();
        }

        try {
            $event = $registry->get($source)->parse($request);
        } catch (ValidationException $e) {
            // Only a source whose sender writes code against us refuses a
            // payload out loud (the generic webhook). The script's author is
            // at a terminal and needs the reason; a media server never gets
            // here, because no server driver validates this way.
            $intake->setAside($endpoint, WebhookDeliveryStatus::Error, DeliveryNote::InvalidPayload, $request->all());

            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UnrecognizedPayload) {
            // Kept with its payload, unlike the other quiet outcomes: "the
            // server posted something we did not recognize" is the one case
            // where the way to fix it is to look at what it sent.
            $intake->setAside($endpoint, WebhookDeliveryStatus::Ignored, DeliveryNote::NotAnEvent, $request->all());

            return response()->noContent();
        } catch (Throwable $e) {
            // The sender's boundary: whatever a driver throws on a payload it
            // cannot read is reported, logged against the endpoint and never
            // answered with a 5xx. The message can carry payload internals,
            // so the sender is told nothing.
            report($e);
            $intake->setAside($endpoint, WebhookDeliveryStatus::Error, DeliveryNote::ParseFailed, $request->all());

            return response()->noContent();
        }

        if (! $event instanceof ScrobbleEvent) {
            // The server's routine traffic — progress pings, library scans,
            // other accounts' music — names whoever it is about, so only the
            // fact that it came is kept.
            $intake->setAside($endpoint, WebhookDeliveryStatus::Ignored, DeliveryNote::NotAnEvent);

            return response()->noContent();
        }

        return $intake->take($endpoint, $event, $body)
            ? response()->noContent(Response::HTTP_ACCEPTED)
            : response()->noContent();
    }

    /**
     * What the sender sent, as one string: the raw body, or — for a form post
     * like Plex's multipart, where PHP keeps no raw body — its fields. Two
     * different events must never read the same here, or the second is taken
     * for a retransmission of the first.
     */
    private function received(Request $request): string
    {
        $body = $request->getContent();

        return $body !== '' ? $body : (string) json_encode($request->request->all());
    }
}
