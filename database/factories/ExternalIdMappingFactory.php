<?php

namespace Database\Factories;

use App\Catalog\ExternalIdSource;
use App\Catalog\MediaKind;
use App\Models\ExternalIdMapping;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalIdMapping>
 */
class ExternalIdMappingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => ExternalIdSource::Imdb,
            'external_ref' => 'tt'.fake()->unique()->numberBetween(1000000, 9999999),
            'media_kind' => MediaKind::Movie,
            'type' => 'movie',
            'provider' => 'tmdb',
            'provider_ref' => (string) fake()->numberBetween(1, 999999),
        ];
    }

    /** Another service's id for this row, asked about as the row's own kind. */
    public function forItem(MediaItem $item, ExternalIdSource $source, string $externalRef): static
    {
        return $this->state([
            'source' => $source,
            'external_ref' => $externalRef,
            'media_kind' => MediaKind::from($item->type),
            'type' => $item->type,
            'provider' => $item->provider,
            'provider_ref' => $item->provider_ref,
        ]);
    }
}
