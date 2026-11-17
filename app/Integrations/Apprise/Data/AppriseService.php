<?php

namespace App\Integrations\Apprise\Data;

/** One service Apprise delivers to, read from the container's own description. */
final readonly class AppriseService
{
    /**
     * @param  string  $code  the scheme it is used with, the encrypted one where the service has one
     * @param  list<AppriseTemplate>  $templates
     * @param  array<string, AppriseToken>  $tokens  the tokens the templates name, in their order
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $setupUrl,
        /** The unencrypted scheme of a service that has both (a server of your own), else null. */
        public ?string $plainScheme,
        public array $templates,
        public array $tokens,
    ) {}

    /** Whether every template names the token: a value the service cannot go without. */
    public function needs(string $token): bool
    {
        foreach ($this->templates as $template) {
            if (! in_array($token, $template->tokens, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The template that uses the most of the given tokens, all of its own
     * given; null when none can be filled.
     *
     * @param  list<string>  $given
     */
    public function templateFor(array $given): ?AppriseTemplate
    {
        $best = null;
        $bestCount = -1;

        foreach ($this->templates as $template) {
            if (array_diff($template->tokens, $given) === [] && count($template->tokens) > $bestCount) {
                [$best, $bestCount] = [$template, count($template->tokens)];
            }
        }

        return $best;
    }
}
