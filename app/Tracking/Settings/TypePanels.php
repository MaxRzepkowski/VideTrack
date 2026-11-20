<?php

namespace App\Tracking\Settings;

use App\Catalog\MediaTypeRegistry;
use App\Tracking\Events\VisibleEvents;
use App\Tracking\MetadataChanges;
use App\Tracking\Releases\ReleaseFeed;
use App\Tracking\Releases\ReleaseSource;

/**
 * Every kind of title the tracking page offers, in the registry's order, each
 * with the settings the code that reads them declared: where its releases come
 * from, the events of its medium, then what its refreshes announce.
 */
final readonly class TypePanels
{
    public function __construct(
        private MediaTypeRegistry $types,
        private ReleaseFeed $feed,
        private MetadataChanges $changes,
        private VisibleEvents $events,
    ) {}

    /**
     * @return list<TypePanel>
     */
    public function all(): array
    {
        $panels = [];

        foreach ($this->types->rootCodes() as $code) {
            $type = $this->types->get($code);

            $sources = array_map(fn (ReleaseSource $source): array => $source->typeOptions($type), $this->feed->sourcesOf($type));

            $panels[] = new TypePanel($code, [
                ...array_merge(...$sources),
                ...$this->events->typeOptions($type),
                ...$this->changes->typeOptions($type),
            ]);
        }

        return $panels;
    }

    /**
     * Every preference the options turn, with the values it takes: null for
     * a flag, its choices for one several options share.
     *
     * @return array<string, list<string>|null>
     */
    public function preferences(): array
    {
        $preferences = [];

        foreach ($this->all() as $panel) {
            foreach ($panel->options as $option) {
                foreach ($option->switches as $switch) {
                    $preferences[$switch->preference] = $option->choices;
                }
            }
        }

        return $preferences;
    }
}
