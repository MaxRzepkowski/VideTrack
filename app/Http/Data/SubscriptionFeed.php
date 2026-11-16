<?php

namespace App\Http\Data;

use App\Tracking\Surfaces\FeedScope;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A calendar feed's address as the settings page builds variants on it: the
 * plain address, and the scope it carries when the address names none.
 */
#[TypeScript]
final readonly class SubscriptionFeed
{
    public function __construct(
        public string $url,
        public FeedScope $defaultScope,
    ) {}
}
