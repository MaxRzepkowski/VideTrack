<?php

namespace App\Tracking\Creators;

use App\Accounts\Preferences\PersonFollowScope;
use App\Catalog\Creators\CreatorPages;
use App\Catalog\Creators\CreatorRef;
use App\Catalog\Creators\PersonRecords;
use App\Catalog\Data\PersonCredit;
use App\Catalog\Department;
use App\Catalog\MatureChoices;
use App\Models\User;
use App\Notifications\MediaEventType;
use App\Tracking\Data\CreatorTitle;
use App\Tracking\Data\CreatorTitles;

/**
 * A followed person's titles: their own work only — acting, directing,
 * writing, and on Hardcover authorship, so a followed author feeds upcoming
 * books the way a followed actor feeds films — and never a title they appear
 * in as themselves (talk shows, ceremonies, documentaries).
 */
final readonly class PersonTitles implements CreatorTitleSource
{
    private const CORE_DEPARTMENTS = [Department::Acting, Department::Directing, Department::Writing, Department::Author];

    /**
     * Localized phrasings of "as themselves", per UI language — TMDB serves
     * the character name in the viewer's locale, so an English-only "Self"
     * check would leak documentaries and talk shows through on other locales.
     * English is the pronoun pattern in isSelfAppearance(), which every locale
     * also gets (untranslated credits stay English). A new UI language needs
     * its entry here; a test holds the list to the supported locales. Stored
     * lowercased; matched as substrings against the normalized role.
     *
     * @var array<string, list<string>>
     */
    public const SELF_ROLE_PHRASES = [
        'en' => [],
        'pl' => [
            'we własnej osobie',
            'własnej osobie',
            'samego siebie',
            'sama siebie',
            'siebie samego',
            'on sam',
            'ona sama',
        ],
        'de' => [
            'sich selbst',
            'er selbst',
            'sie selbst',
        ],
        'fr' => [
            'lui-même',
            'elle-même',
            'soi-même',
            'eux-mêmes',
            'elles-mêmes',
        ],
        'es' => [
            'él mismo',
            'ella misma',
            'sí mismo',
            'sí misma',
            'ellos mismos',
        ],
        'pt_BR' => [
            'ele mesmo',
            'ela mesma',
            'si mesmo',
            'si mesma',
            'ele próprio',
            'ela própria',
            'eles mesmos',
        ],
        'it' => [
            'se stesso',
            'se stessa',
            'sé stesso',
            'sé stessa',
            'lui stesso',
            'lei stessa',
            'se stessi',
        ],
        'nl' => [
            'zichzelf',
            'hemzelf',
            'haarzelf',
        ],
    ];

    public function __construct(
        private CreatorPages $pages,
        private PersonRecords $records,
    ) {}

    public function read(CreatorRef $creator, string $locale): ?CreatorTitles
    {
        if (! $this->pages->serves($creator)) {
            return null;
        }

        // What a followed person announces is the same for every follower.
        $details = $this->pages->person($creator, $locale, MatureChoices::shared());

        // The daily sweep is where a followed person's row gets its full set
        // of columns, not just the name it was created with; the person page
        // falls back to them when the provider is unreachable.
        $this->records->fromDetails($creator->provider, $creator->ref, $details);

        $names = [$details->name, ...$details->alsoKnownAs];
        $key = fn (PersonCredit $credit): string => "{$credit->media->type}:{$credit->media->ref}";

        // One "as themselves" credit disqualifies the whole title: a
        // documentary about a director that also lists them as its director
        // stays out.
        $selfAppearances = [];

        foreach ($details->credits as $credit) {
            if ($this->isSelfAppearance($credit->role, $names, $locale)) {
                $selfAppearances[$key($credit)] = true;
            }
        }

        $credits = array_values(array_filter(
            $details->credits,
            fn (PersonCredit $credit): bool => in_array($credit->department, self::CORE_DEPARTMENTS, true) && ! isset($selfAppearances[$key($credit)]),
        ));

        return new CreatorTitles(
            name: $details->name,
            credits: array_map(fn (PersonCredit $credit): CreatorTitle => new CreatorTitle($credit->media, $credit->department), $credits),
            creditedKeys: array_fill_keys(array_map($key, $details->credits), true),
            mainScope: $details->knownForDepartment,
        );
    }

    /**
     * The follower's scope preference picks the person's main field alone —
     * every core department when the provider reports something outside them
     * (Production) or nothing at all (Hardcover) — or every core department.
     */
    public function scopesFor(User $follower, CreatorRef $creator, CreatorTitles $titles): array
    {
        if ($follower->preferences->personFollowScope() === PersonFollowScope::All || ! in_array($titles->mainScope, self::CORE_DEPARTMENTS, true)) {
            return self::CORE_DEPARTMENTS;
        }

        return [$titles->mainScope];
    }

    public function announcement(): MediaEventType
    {
        return MediaEventType::PersonNewTitle;
    }

    /**
     * Whether a credit is the person appearing AS THEMSELVES rather than a
     * character. Three ways it shows up: the English "self" pronoun family
     * (Self, Himself, Herself, Themselves…), case-insensitive and bounded so
     * "Selfish"/"Selfie" are spared; a localized phrasing; or the role being
     * the person's own name or a known alias (TMDB crediting "Matthew
     * McConaughey" instead of "Self"), tolerating a trailing "(voice)".
     *
     * @param  list<string>  $names
     */
    public function isSelfAppearance(?string $role, array $names, string $locale): bool
    {
        $normalized = $this->normalize((string) $role);

        if ($normalized === '') {
            return false;
        }

        if (preg_match('/(?<![a-z])(?:one|my|your|him|her|it|them|our)?sel(?:f|ves)(?![a-z])/i', $normalized) === 1) {
            return true;
        }

        foreach (self::SELF_ROLE_PHRASES[$locale] ?? [] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        foreach ($names as $name) {
            $name = $this->normalize($name);

            if ($name === '') {
                continue;
            }

            // Exact stage name, or the full multi-word name embedded in a
            // decorated role ("Matthew McConaughey (voice)"). A single-word
            // alias is only trusted on an exact match, never as a substring.
            if ($normalized === $name || (str_contains($name, ' ') && str_contains($normalized, $name))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
    }
}
