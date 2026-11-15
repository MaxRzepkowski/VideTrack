<?php

use Carbon\CarbonInterface;

/**
 * A value as plain data, every property of every object included: what a
 * characterization test compares, so a refactor that changes any field shows.
 */
function goldenShape(mixed $value): mixed
{
    return match (true) {
        $value instanceof BackedEnum => $value->value,
        $value instanceof UnitEnum => $value->name,
        $value instanceof CarbonInterface => $value->toIso8601String(),
        is_object($value) => ['@class' => class_basename($value), ...array_map(goldenShape(...), get_object_vars($value))],
        is_array($value) => array_map(goldenShape(...), $value),
        default => $value,
    };
}

/**
 * Compares a value with its recorded shape in `tests/Fixtures/golden/{name}.json`;
 * `GOLDEN_UPDATE=1` records it instead, for a change the diff shows to be meant.
 */
function expectGolden(string $name, mixed $value): void
{
    $path = dirname(__DIR__)."/Fixtures/golden/{$name}.json";
    $shape = json_encode(goldenShape($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";

    if (getenv('GOLDEN_UPDATE') === '1') {
        file_put_contents($path, $shape);
    }

    expect($path)->toBeFile()
        ->and($shape)->toBe(file_get_contents($path));
}
