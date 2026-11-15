<?php

namespace App\Integrations\Scrobble;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why a delivery ended as it did, as the log names it; the settings page says
 * each in words. A delivery saved to the library needs no note.
 */
#[TypeScript]
enum DeliveryNote: string
{
    case AlreadyPlayed = 'already_played';
    case BelowThreshold = 'below_threshold';
    case DuplicateDelivery = 'duplicate_delivery';
    case InvalidPayload = 'invalid_payload';
    case KindNotActionable = 'kind_not_actionable';
    case NoExternalIdMatch = 'no_external_id_match';
    case NoProgressUnit = 'no_progress_unit';
    case NotAnEvent = 'not_an_event';
    case ParseFailed = 'parse_failed';
    case PayloadTooLarge = 'payload_too_large';
    case ProcessingFailed = 'processing_failed';
    case RatingNotImported = 'rating_not_imported';
    case RatingOutOfRange = 'rating_out_of_range';
    case TestParseFailed = 'test_parse_failed';
    case TestPassed = 'test_passed';
    case TestProviderUnreachable = 'test_provider_unreachable';
    case UserMismatch = 'user_mismatch';
}
