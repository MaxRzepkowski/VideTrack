<?php

namespace App\Tasks\Data;

use App\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * One sentence a background run said, kept as its translation key and
 * placeholders so it reads in the language of whoever opens the page. A
 * placeholder that is a moment is kept as one and left in the wording for the
 * page to write in the reader's timezone and time format.
 */
final readonly class TaskLine
{
    /**
     * @param  array<string, string|int>  $params
     * @param  array<string, CarbonImmutable>  $moments
     */
    public function __construct(
        public string $key,
        public array $params = [],
        public array $moments = [],
    ) {}

    /** The line as it was kept; one that is not a line reads as nothing. */
    public static function fromStored(mixed $stored): ?self
    {
        $line = Payload::map($stored);
        $key = Payload::string($line, 'key');

        if ($key === null) {
            return null;
        }

        $params = [];

        foreach (Payload::map($line['params'] ?? null) as $name => $value) {
            if (is_string($value) || is_int($value)) {
                $params[(string) $name] = $value;
            }
        }

        $moments = [];

        foreach (Payload::map($line['moments'] ?? null) as $name => $value) {
            $moment = Payload::instant($value);

            if ($moment !== null) {
                $moments[(string) $name] = $moment;
            }
        }

        return new self($key, $params, $moments);
    }

    /**
     * @return array{key: string, params: array<string, string|int>, moments: array<string, string>}
     */
    public function stored(): array
    {
        return [
            'key' => $this->key,
            'params' => $this->params,
            'moments' => $this->momentsOnTheWire(),
        ];
    }

    /** In the reader's language (the app's when none is given), a moment's placeholder left as it stands. */
    public function wording(?string $locale = null): string
    {
        return __($this->key, $this->params, $locale);
    }

    /**
     * The moments as the page reads them, by their placeholder.
     *
     * @return array<string, string>
     */
    public function momentsOnTheWire(): array
    {
        return array_map(fn (CarbonImmutable $moment): string => $moment->toIso8601String(), $this->moments);
    }
}
