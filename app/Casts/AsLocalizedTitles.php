<?php

namespace App\Casts;

use App\Catalog\Data\LocalizedTitle;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * `media_items.localized_titles`: locale => {title, posterPath} in JSON,
 * locale => LocalizedTitle on the model. An empty set stores NULL.
 *
 * @implements CastsAttributes<array<string, LocalizedTitle>, array<string, LocalizedTitle>>
 */
final class AsLocalizedTitles implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, LocalizedTitle>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $titles = [];

        foreach (is_string($value) ? (array) json_decode($value, true) : [] as $locale => $row) {
            if (is_array($row) && is_string($row['title'] ?? null)) {
                $titles[(string) $locale] = new LocalizedTitle(
                    $row['title'],
                    is_string($row['posterPath'] ?? null) ? $row['posterPath'] : null,
                );
            }
        }

        return $titles;
    }

    /**
     * @param  array<string, LocalizedTitle>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        return json_encode(
            array_map(fn (LocalizedTitle $title): array => ['title' => $title->title, 'posterPath' => $title->posterPath], $value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
