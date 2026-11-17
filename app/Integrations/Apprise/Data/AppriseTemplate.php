<?php

namespace App\Integrations\Apprise\Data;

/** One shape of a service's Apprise URL, `{schema}://{token}/…`, and the tokens it names. */
final readonly class AppriseTemplate
{
    private const SCHEME = 'schema';

    /**
     * @param  list<string>  $tokens  the tokens it names, the scheme left out
     */
    public function __construct(
        public string $pattern,
        public array $tokens,
    ) {}

    public static function of(string $pattern): self
    {
        preg_match_all('/\{(\w+)\}/', $pattern, $matches);

        return new self($pattern, array_values(array_diff($matches[1], [self::SCHEME])));
    }

    /**
     * The URL with every token in its place.
     *
     * @param  array<string, string>  $parts  token => its value, ready for the URL
     */
    public function fill(string $scheme, array $parts): string
    {
        $replace = ['{'.self::SCHEME.'}' => $scheme];

        foreach ($parts as $token => $part) {
            $replace['{'.$token.'}'] = $part;
        }

        return strtr($this->pattern, $replace);
    }
}
