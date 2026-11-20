<?php

namespace App\Tracking\Settings;

use App\Tracking\Surface;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One setting a kind of title has on the tracking page: a switch per surface,
 * each turning a preference. Most switches own their preference outright; a
 * few share one that holds a choice (a film's premiere and its home release
 * are the two halves of its release mode): each of those reads as on in the
 * values listed, and every combination of them is one of the choices.
 */
#[TypeScript]
final readonly class TypeOption
{
    /**
     * @param  list<TypeOptionSwitch>  $switches
     * @param  list<string>|null  $onWhen  the preference's values this option is on in; null for a plain flag
     * @param  list<string>|null  $choices  every value the preference takes; null for a plain flag
     */
    public function __construct(
        /** Unique among the options of one type. */
        public string $key,
        /** Translation key. */
        public string $label,
        /** Translation key. */
        public ?string $description,
        /** Translation key: the option inside a summary line, lower case where the language wants it. */
        public string $phrase,
        public array $switches,
        public ?array $onWhen = null,
        public ?array $choices = null,
        /**
         * Whether it shows something of the tracked titles (a date, a
         * platform), so means nothing while the type takes none; an event of
         * its medium shows whatever is tracked.
         */
        public bool $ofTrackedTitles = true,
    ) {}

    /**
     * A switch on each of the surfaces, each turning the preference the
     * surface keeps it under.
     *
     * @param  list<Surface>  $surfaces
     * @param  callable(Surface): string  $preference
     * @param  list<string>|null  $onWhen
     * @param  list<string>|null  $choices
     * @param  (callable(Surface): ?TypeOptionRequirement)|null  $requires  what the switch needs on to mean anything there
     */
    public static function on(
        string $key,
        string $label,
        ?string $description,
        string $phrase,
        array $surfaces,
        callable $preference,
        ?array $onWhen = null,
        ?array $choices = null,
        ?callable $requires = null,
        bool $ofTrackedTitles = true,
    ): self {
        return new self(
            $key,
            $label,
            $description,
            $phrase,
            array_map(fn (Surface $surface): TypeOptionSwitch => new TypeOptionSwitch($surface, $preference($surface), $requires === null ? null : $requires($surface)), $surfaces),
            $onWhen,
            $choices,
            $ofTrackedTitles,
        );
    }
}
