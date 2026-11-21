<?php

namespace App\Catalog;

/**
 * How far one viewer lets each provider's listings go, by provider code. A
 * provider the viewer made no choice for keeps to Suggestive: everything
 * short of adult material.
 */
final readonly class MatureChoices
{
    /**
     * @param  array<string, MatureContent>  $levels
     */
    public function __construct(private array $levels = []) {}

    /** What a listing every viewer shares keeps to: no viewer's own choice. */
    public static function shared(): self
    {
        return new self;
    }

    public function for(string $provider): MatureContent
    {
        return $this->levels[$provider] ?? MatureContent::Suggestive;
    }
}
