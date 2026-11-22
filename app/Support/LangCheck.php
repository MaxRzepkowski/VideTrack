<?php

namespace App\Support;

use Illuminate\Translation\MessageSelector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Checks the translation files the way the repo writes them. Our texts live
 * in group files (`lang/{locale}/{group}.php`) under context keys, named in
 * the code as "group.key"; `lang/{locale}.json` holds only the framework's own
 * strings. The complete locales carry the same keys, every key the code names
 * exists in them, and every key of our groups is named somewhere in the code —
 * a key built at runtime cannot be checked, which is why the code spells them
 * out. Any other locale may lag behind (a missing text reads in the source
 * language), but what it has must fit: no key the source lacks, the same
 * parameters, and as many plural forms as the language counts.
 */
final class LangCheck
{
    /**
     * The start of a translation call. The lookbehind keeps out method calls
     * ($translator->trans, Lang::get) and longer names (wTrans); a dot before
     * it is PHP concatenation, so it counts.
     */
    private const CALL = '/(?<![\w$>:])(?:__|trans|transChoice|trans_choice)\(/';

    /** A quoted string: single or double quotes, escapes kept. */
    private const LITERAL = '/\'((?:\\\\.|[^\'\\\\])*)\'|"((?:\\\\.|[^"\\\\])*)"/';

    private const GROUP_KEY = '/^[\w-]+\.[\w.-]+$/';

    /** Group files the framework owns; we neither add to them nor expect our code to name every key. */
    private const FRAMEWORK_GROUPS = ['auth', 'pagination', 'passwords', 'validation'];

    private const SOURCE_EXTENSIONS = ['php', 'ts', 'vue'];

    /** The languages a change to a text is written in at once. */
    private const COMPLETE_LOCALES = ['en', 'pl'];

    /** A parameter a text fills in (`:count`, `:name`). */
    private const PARAMETER = '/:([A-Za-z_]+)/';

    /** A plural form chosen by its own condition (`{0}`, `[2,*]`) rather than by the language's rule. */
    private const CONDITION = '/^\s*[\{\[]/';

    /** Counts enough to meet every form a language's plural rule has. */
    private const PLURAL_PROBE = 200;

    /**
     * @param  string  $sourceLocale  the language every text is written in first and a missing one reads in
     * @param  list<string>  $sourceDirs
     */
    public function __construct(
        private readonly LangFiles $files,
        private readonly string $sourceLocale,
        private readonly array $sourceDirs,
    ) {}

    /**
     * @return list<string> one line per problem, empty when the files are in order
     */
    public function problems(): array
    {
        $keysByLocale = [];

        foreach ($this->files->locales() as $locale) {
            $keysByLocale[$locale] = $this->keys($locale);
        }

        $complete = array_intersect_key($keysByLocale, array_flip(self::COMPLETE_LOCALES));
        $sources = $this->sources();

        return [
            ...$this->parityProblems($complete),
            ...$this->usageProblems($complete, $sources),
            ...$this->unusedProblems($complete, $sources),
            ...$this->unknownProblems(array_diff_key($keysByLocale, $complete)),
            ...$this->fitProblems(),
        ];
    }

    /**
     * How far each locale that may lag behind is translated.
     *
     * @return array<string, array{translated: int, total: int}>
     */
    public function coverage(): array
    {
        $source = $this->files->texts($this->sourceLocale);
        $coverage = [];

        foreach (array_diff($this->files->locales(), self::COMPLETE_LOCALES) as $locale) {
            $coverage[$locale] = [
                'translated' => count(array_intersect_key($this->files->texts($locale), $source)),
                'total' => count($source),
            ];
        }

        return $coverage;
    }

    /**
     * Keys a lagging locale has that the source language does not: a text
     * renamed or removed since it was translated.
     *
     * @param  array<string, array<string, true>>  $keysByLocale
     * @return list<string>
     */
    private function unknownProblems(array $keysByLocale): array
    {
        $source = $this->keys($this->sourceLocale);
        $problems = [];

        foreach ($keysByLocale as $locale => $keys) {
            foreach (array_keys(array_diff_key($keys, $source)) as $key) {
                $problems[] = "{$locale}: \"{$key}\" is not a text of ".$this->sourceLocale;
            }
        }

        return $problems;
    }

    /**
     * Translations that do not fit their source text: other parameters, or
     * plural forms the language's rule does not count. A single form is a
     * text the count leaves alone, so it always fits; the framework's own
     * strings are left as they come.
     *
     * @return list<string>
     */
    private function fitProblems(): array
    {
        $source = $this->files->texts($this->sourceLocale);
        $problems = [];

        foreach (array_diff($this->files->locales(), [$this->sourceLocale]) as $locale) {
            $forms = $this->pluralForms($locale);

            foreach (array_intersect_key($this->files->texts($locale), $source) as $key => $text) {
                if ($this->parameters($text) !== $this->parameters($source[$key])) {
                    $problems[] = "{$locale}: \"{$key}\" fills in ".$this->listed($this->parameters($text)).', its source '.$this->listed($this->parameters($source[$key]));
                }

                $count = count(explode('|', $text));

                if ($this->isOurs($key) && str_contains($source[$key], '|') && preg_match(self::CONDITION, $text) !== 1 && $count !== 1 && $count !== $forms) {
                    $problems[] = "{$locale}: \"{$key}\" has {$count} plural forms, the language counts {$forms}";
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function parameters(string $text): array
    {
        preg_match_all(self::PARAMETER, $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /** @param  list<string>  $parameters */
    private function listed(array $parameters): string
    {
        return $parameters === [] ? 'no parameters' : ':'.implode(', :', $parameters);
    }

    /** How many plural forms a language's rule tells apart, as the framework and the front both choose them. */
    private function pluralForms(string $locale): int
    {
        $selector = new MessageSelector;

        return count(array_unique(array_map(fn (int $number): int => $selector->getPluralIndex($locale, $number), range(0, self::PLURAL_PROBE))));
    }

    /** A key of our own groups, not one of the framework's strings. */
    private function isOurs(string $key): bool
    {
        return preg_match(self::GROUP_KEY, $key) === 1 && ! in_array(strstr($key, '.', true), self::FRAMEWORK_GROUPS, true);
    }

    /**
     * Every key a locale defines.
     *
     * @return array<string, true>
     */
    private function keys(string $locale): array
    {
        return array_fill_keys($this->files->keys($locale), true);
    }

    /**
     * @param  array<string, array<string, true>>  $keysByLocale
     * @return list<string>
     */
    private function parityProblems(array $keysByLocale): array
    {
        $problems = [];
        $all = $keysByLocale === [] ? [] : array_merge(...array_values($keysByLocale));

        foreach ($keysByLocale as $locale => $keys) {
            foreach (array_keys(array_diff_key($all, $keys)) as $key) {
                $problems[] = "{$locale}: missing \"{$key}\" (present in another locale)";
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, array<string, true>>  $keysByLocale
     * @param  array<string, string>  $sources  path => contents
     * @return list<string>
     */
    private function usageProblems(array $keysByLocale, array $sources): array
    {
        $problems = [];

        foreach ($this->usedKeys($sources) as $key => $file) {
            if (preg_match(self::GROUP_KEY, $key) !== 1) {
                $problems[] = "\"{$key}\" is not a group key — name the text in lang/{locale}/{group}.php (used in {$file})";

                continue;
            }

            foreach ($keysByLocale as $locale => $keys) {
                if (! isset($keys[$key])) {
                    $problems[] = "{$locale}: missing \"{$key}\" (used in {$file})";
                }
            }
        }

        return $problems;
    }

    /**
     * Keys of our own groups that no source names. A key passed around as
     * data (a task's frequency, an exception message) still appears in the
     * code as a literal, so it counts as named.
     *
     * @param  array<string, array<string, true>>  $keysByLocale
     * @param  array<string, string>  $sources
     * @return list<string>
     */
    private function unusedProblems(array $keysByLocale, array $sources): array
    {
        $code = implode("\n", $sources);
        $problems = [];
        $all = $keysByLocale === [] ? [] : array_merge(...array_values($keysByLocale));

        foreach (array_keys($all) as $key) {
            if (! $this->isOurs($key)) {
                continue;
            }

            if (! str_contains($code, "'{$key}'") && ! str_contains($code, "\"{$key}\"")) {
                $problems[] = "\"{$key}\" is not used anywhere";
            }
        }

        return $problems;
    }

    /**
     * Literal keys passed to translation calls, each with the first file
     * that uses it: every quoted key in the first argument, so a key chosen
     * by a condition (`cond ? 'a.b' : 'a.c'`) is checked like a plain one.
     *
     * @param  array<string, string>  $sources
     * @return array<string, string>
     */
    private function usedKeys(array $sources): array
    {
        $keys = [];

        foreach ($sources as $path => $contents) {
            preg_match_all(self::CALL, $contents, $calls, PREG_OFFSET_CAPTURE);

            foreach ($calls[0] as [$call, $offset]) {
                $argument = trim($this->firstArgument($contents, $offset + strlen($call)));

                preg_match_all(self::LITERAL, $argument, $literals, PREG_SET_ORDER);

                // A lone literal is the key; inside an expression only the
                // literals shaped like keys are (a fallback text is not one).
                $lone = count($literals) === 1 && $literals[0][0] === $argument;

                foreach ($literals as $literal) {
                    $single = $literal[1] ?? '';
                    $key = $single !== '' ? stripcslashes($single) : stripcslashes($literal[2] ?? '');

                    // A double-quoted PHP string with a variable is built at runtime.
                    if ($key === '' || ($single === '' && str_contains($key, '$')) || (! $lone && preg_match(self::GROUP_KEY, $key) !== 1)) {
                        continue;
                    }

                    $keys[$key] ??= $path;
                }
            }
        }

        return $keys;
    }

    /** The text of a call's first argument: up to the first comma or the closing parenthesis at its own depth. */
    private function firstArgument(string $contents, int $start): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($contents);

        for ($i = $start; $i < $length; $i++) {
            $char = $contents[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif (($char === ')' || $char === ']' || $char === '}') && $depth-- === 0) {
                break;
            } elseif ($char === ',' && $depth === 0) {
                break;
            }
        }

        return substr($contents, $start, $i - $start);
    }

    /**
     * @return array<string, string> path => contents
     */
    private function sources(): array
    {
        $files = [];

        foreach ($this->sourceDirs as $dir) {
            /** @var iterable<SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), self::SOURCE_EXTENSIONS, true)) {
                    $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        ksort($files);

        return $files;
    }
}
