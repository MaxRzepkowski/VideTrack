<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * The translation files as the repo writes them: a directory per locale, its
 * group files (`{locale}/{group}.php`) read as "group.path", and the
 * framework's own strings in `{locale}.json` under the bare string.
 */
final readonly class LangFiles
{
    public function __construct(private string $langDir) {}

    /**
     * Every locale with a directory of its own.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return array_map(basename(...), glob("{$this->langDir}/*", GLOB_ONLYDIR) ?: []);
    }

    /**
     * Every key a locale defines, whatever it holds.
     *
     * @return list<string>
     */
    public function keys(string $locale): array
    {
        return array_keys($this->entries($locale));
    }

    /**
     * Every text a locale defines, under the key the code names it by.
     *
     * @return array<string, string>
     */
    public function texts(string $locale): array
    {
        return array_filter($this->entries($locale), is_string(...));
    }

    /** A fingerprint of everything the locales' files say: it moves when any text does. */
    public function fingerprint(string ...$locales): string
    {
        $context = hash_init('xxh128');

        foreach ($locales as $locale) {
            foreach ([...array_values($this->groupFiles($locale)), $this->jsonFile($locale)] as $file) {
                if (is_file($file)) {
                    hash_update($context, $locale.'/'.basename($file)."\0".file_get_contents($file)."\0");
                }
            }
        }

        return hash_final($context);
    }

    /** @return array<string, mixed> "group.path" for the group files, the bare string for the framework's JSON */
    private function entries(string $locale): array
    {
        $entries = [];

        foreach ($this->groupFiles($locale) as $group => $file) {
            foreach (Arr::dot((array) require $file) as $path => $value) {
                $entries["{$group}.{$path}"] = $value;
            }
        }

        $json = $this->jsonFile($locale);

        foreach (is_file($json) ? (array) json_decode((string) file_get_contents($json), true) : [] as $key => $value) {
            $entries[(string) $key] = $value;
        }

        return $entries;
    }

    /** @return array<string, string> group => file, in the order the directory lists them */
    private function groupFiles(string $locale): array
    {
        $files = [];

        foreach (glob("{$this->langDir}/{$locale}/*.php") ?: [] as $file) {
            $files[basename($file, '.php')] = $file;
        }

        return $files;
    }

    private function jsonFile(string $locale): string
    {
        return "{$this->langDir}/{$locale}.json";
    }
}
