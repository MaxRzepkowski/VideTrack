<?php

namespace App\Integrations\Apprise\Data;

use App\Integrations\Apprise\AppriseTokenType;

/** One part of an Apprise URL a service asks for, as its schema describes it. */
final readonly class AppriseToken
{
    /**
     * @param  string|null  $pattern  a PCRE the value must match, delimiters and flags included; null when none or unreadable
     * @param  list<string>  $choices  the values a choice takes
     */
    public function __construct(
        public string $key,
        public string $name,
        public AppriseTokenType $type,
        /** Several values, joined by the delimiter. */
        public bool $isList,
        public bool $secret,
        public ?string $pattern,
        public array $choices,
        public string $delimiter,
    ) {}
}
