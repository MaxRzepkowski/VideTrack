<?php

namespace App\Accounts\Preferences;

use App\Accounts\Data\MatureContentOption;
use App\Catalog\Contracts\SupportsMatureFilter;
use App\Catalog\MatureChoices;
use App\Catalog\MatureContent;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderRegistry;

/**
 * The providers a viewer can set an adult content level for: those of this
 * instance that file adult material and serve a kind of title here.
 */
final readonly class MatureContentOptions
{
    public function __construct(
        private ProviderRegistry $providers,
        private MediaTypeRegistry $types,
    ) {}

    /**
     * The levels each provider takes, by its code.
     *
     * @return array<string, non-empty-list<MatureContent>>
     */
    public function levels(): array
    {
        $levels = [];

        foreach ($this->providers->allSupporting(SupportsMatureFilter::class) as $code => $provider) {
            if ($provider instanceof SupportsMatureFilter && $this->typesOf($code) !== []) {
                $levels[$code] = $provider->matureLevels();
            }
        }

        return $levels;
    }

    /**
     * @return list<MatureContentOption>
     */
    public function for(UserPreferences $preferences): array
    {
        $chosen = $preferences->matureContent();
        $options = [];

        foreach ($this->levels() as $code => $levels) {
            // A level the provider no longer tells apart reads as no choice, so the page can save it.
            $level = in_array($chosen->for($code), $levels, true) ? $chosen->for($code) : MatureChoices::shared()->for($code);
            $options[] = new MatureContentOption($code, $this->typesOf($code), $levels, $level);
        }

        return $options;
    }

    /**
     * The kinds of title a provider serves here, in the registry's order.
     *
     * @return list<string>
     */
    private function typesOf(string $provider): array
    {
        return array_values(array_filter($this->types->titleCodes(), fn (string $type): bool => $this->types->get($type)->provider === $provider));
    }
}
