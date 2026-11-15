<?php

use App\Support\TypeScript\AttributedEnumTransformer;
use App\Support\TypeScript\WireShapeTransformer;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\FlatModuleWriter;

/** The module the generator writes for one fixture directory, unformatted. */
function generatedFrom(string $directory): string
{
    $output = scratchDirectory('wire-shapes-');

    TypeScriptTransformer::create(
        TypeScriptTransformerConfigFactory::create()
            ->transformer(WireShapeTransformer::class)
            ->transformer(AttributedClassTransformer::class)
            ->transformer(AttributedEnumTransformer::class)
            ->transformDirectories(dirname(__DIR__)."/Fixtures/WireShapes/{$directory}")
            ->outputDirectory($output)
            ->writer(new FlatModuleWriter('generated.ts'))
            ->withoutManifest(),
    )->execute();

    return (string) file_get_contents("{$output}/generated.ts");
}

test('a class that writes its own wire form is typed as that form', function () {
    // Card sends `counts` and `nested` and keeps `internal` home; Point has
    // no wire form of its own and stays typed by its properties; an enum the
    // front knows is referenced, one it does not is spelled out; Shelf's
    // aliases come from Card, under their name and under another, and
    // name the enum Card imports, which Shelf does not.
    expect(generatedFrom('Described'))->toBe(<<<'TS'
        export type Badge = {
        readonly label: string,
        };
        export type Card = {
        readonly title: string,
        readonly year: number | null,
        readonly tags: string[],
        readonly tone: Tone,
        readonly grade: 'high' | 'low',
        readonly badge: Badge | null,
        readonly bag: {
        readonly note?: string,
        readonly mood?: Mood,
        readonly [key: string]: unknown,
        },
        readonly counts: Record<string, number>,
        readonly flagged?: boolean,
        readonly nested: {
        readonly anything: unknown,
        },
        };
        export type Mood = 'calm';
        export type Point = {
        readonly x: number,
        readonly y: number,
        };
        export type Shelf = {
        readonly cards: Card[],
        readonly bag: {
        readonly note?: string,
        readonly mood?: Mood,
        readonly [key: string]: unknown,
        },
        readonly pocket: {
        readonly note?: string,
        readonly mood?: Mood,
        readonly [key: string]: unknown,
        },
        };
        export type Tone = 'warm' | 'cold';

        TS);
});

test('a name the file imports wins over a global class of the same name', function () {
    expect(generatedFrom('ShadowedName'))->toBe(<<<'TS'
        export type Directory = 'up' | 'down';
        export type Listing = {
        readonly dir: Directory,
        };

        TS);
});

test('a marked class that does not describe what it sends stops the generation', function () {
    expect(fn () => generatedFrom('Undescribed'))->toThrow(
        LogicException::class,
        'Tests\Fixtures\WireShapes\Undescribed\Loose::jsonSerialize() must describe what it sends as `@return array{…}`.',
    );
});

test('an alias imported from a class that is not there stops the generation', function () {
    expect(fn () => generatedFrom('MissingClass'))->toThrow(
        LogicException::class,
        'Tests\Fixtures\WireShapes\MissingClass\Orphan imports Bag from a class that cannot be found.',
    );
});

test('an alias the named class does not declare stops the generation', function () {
    expect(fn () => generatedFrom('MissingAlias'))->toThrow(
        LogicException::class,
        'Tests\Fixtures\WireShapes\Described\Card has no type alias Pouch.',
    );
});
