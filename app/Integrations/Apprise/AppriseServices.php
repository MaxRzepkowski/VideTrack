<?php

namespace App\Integrations\Apprise;

use App\Integrations\Apprise\Data\AppriseService;
use App\Integrations\Apprise\Data\AppriseTemplate;
use App\Integrations\Apprise\Data\AppriseToken;
use App\Support\Payload;
use ErrorException;
use Illuminate\Support\Facades\Cache;

/**
 * The services the instance's Apprise container delivers to, from its own
 * description (`/details`), so a service Apprise adds appears here with the
 * next image. The description is asked for once a day (it changes with the
 * image only) and read once per request.
 */
final class AppriseServices
{
    private const CACHE_KEY = 'apprise.details';

    private const CACHE_SECONDS = 86400;

    /** A description that could not be read is asked for again after a minute. */
    private const RETRY_SECONDS = 60;

    /**
     * Schemes not offered: the instance delivers to Discord itself, with
     * richer messages than Apprise's.
     */
    private const LEFT_OUT = ['discord'];

    /** @var list<AppriseService>|null */
    private ?array $services = null;

    public function __construct(private readonly AppriseClient $apprise) {}

    /**
     * @return list<AppriseService> by name
     */
    public function all(): array
    {
        if ($this->services === null) {
            $services = Payload::listOf($this->details(), $this->service(...));
            usort($services, fn (AppriseService $a, AppriseService $b): int => strnatcasecmp($a->name, $b->name));
            $this->services = $services;
        }

        return $this->services;
    }

    public function find(string $code): ?AppriseService
    {
        return array_find($this->all(), fn (AppriseService $service): bool => $service->code === $code);
    }

    /** @return list<array<array-key, mixed>> */
    private function details(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return Payload::list($cached);
        }

        $schemas = $this->apprise->details();
        Cache::put(self::CACHE_KEY, $schemas ?? [], $schemas === null ? self::RETRY_SECONDS : self::CACHE_SECONDS);

        return $schemas ?? [];
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    private function service(array $schema): ?AppriseService
    {
        $plain = Payload::texts($schema['protocols'] ?? null);
        $secure = Payload::texts($schema['secure_protocols'] ?? null);
        $scheme = $secure[0] ?? $plain[0] ?? null;
        $name = Payload::string($schema, 'service_name');
        $details = Payload::map($schema['details'] ?? null);
        $templates = array_map(AppriseTemplate::of(...), Payload::texts($details['templates'] ?? null));

        if ($scheme === null || $name === null || $templates === [] || Payload::bool($schema, 'enabled') === false
            || array_intersect([...$plain, ...$secure], self::LEFT_OUT) !== []) {
            return null;
        }

        $schemaTokens = Payload::named($details['tokens'] ?? null);
        $tokens = [];

        foreach ($templates as $template) {
            foreach ($template->tokens as $key) {
                $tokens[$key] ??= $this->token($key, Payload::map($schemaTokens[$key] ?? null), $schemaTokens);
            }
        }

        return new AppriseService(
            code: $scheme,
            name: $name,
            setupUrl: Payload::string($schema, 'setup_url'),
            plainScheme: $secure !== [] ? ($plain[0] ?? null) : null,
            templates: $templates,
            tokens: $tokens,
        );
    }

    /**
     * A token as the form asks for it. A list (the targets) is named after
     * what it holds: Telegram's "Targets" are its "Target Chat ID"s.
     *
     * @param  array<array-key, mixed>  $token
     * @param  array<string, mixed>  $all
     */
    private function token(string $key, array $token, array $all): AppriseToken
    {
        $members = array_map(fn (string $member): ?string => Payload::string(Payload::map($all[$member] ?? null), 'name'), Payload::texts($token['group'] ?? null));
        $members = array_values(array_filter($members));
        $type = Payload::text($token, 'type');
        $isList = str_starts_with($type, 'list:');

        return new AppriseToken(
            key: $key,
            name: $members === [] ? Payload::string($token, 'name') ?? $key : implode(' / ', $members),
            type: AppriseTokenType::tryFrom($isList ? substr($type, strlen('list:')) : $type) ?? AppriseTokenType::Text,
            isList: $isList,
            secret: Payload::bool($token, 'private') === true,
            pattern: $this->pattern(Payload::strings($token['regex'] ?? null)),
            choices: Payload::texts($token['values'] ?? null),
            delimiter: Payload::texts($token['delim'] ?? null)[0] ?? '/',
        );
    }

    /**
     * The schema's regex, given as [pattern, flags] in Python's syntax, as a
     * PCRE; null when there is none or PCRE does not read it, and Apprise is
     * left to check the value itself.
     *
     * @param  list<string>  $regex
     */
    private function pattern(array $regex): ?string
    {
        [$source, $flags] = array_pad($regex, 2, '');

        if ($source === '') {
            return null;
        }

        $pattern = '~'.str_replace('~', '\~', $source).'~'.preg_replace('/[^imsx]/', '', $flags);

        try {
            return preg_match($pattern, '') === false ? null : $pattern;
        } catch (ErrorException) {
            return null;
        }
    }
}
