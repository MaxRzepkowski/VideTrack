<?php

namespace App\Tasks\Data;

/**
 * One sentence a background run said, kept as its translation key and
 * placeholders so it reads in the language of whoever opens the page.
 */
final readonly class TaskLine
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(
        public string $key,
        public array $params = [],
    ) {}

    /**
     * @return array{key: string, params: array<string, string|int>}
     */
    public function stored(): array
    {
        return ['key' => $this->key, 'params' => $this->params];
    }

    public function wording(?string $locale = null): string
    {
        return __($this->key, $this->params, $locale);
    }
}
