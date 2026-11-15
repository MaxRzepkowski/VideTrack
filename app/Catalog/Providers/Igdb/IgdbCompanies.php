<?php

namespace App\Catalog\Providers\Igdb;

use App\Catalog\CompanyRole;
use App\Catalog\Data\CompanyCredit;
use App\Catalog\Data\CompanyDetails;
use App\Catalog\Data\CreditPage;
use App\Catalog\ItemNotFoundException;
use App\Support\Payload;

/**
 * A games company: its page and its games in chunks, by role.
 */
final readonly class IgdbCompanies
{
    /** IGDB's involved_companies flags, each the role it credits. */
    public const ROLES = [
        'developer' => CompanyRole::Developer,
        'publisher' => CompanyRole::Publisher,
        'porting' => CompanyRole::Porting,
        'supporting' => CompanyRole::Supporting,
    ];

    /**
     * Games per chunk of a company's ludography — IGDB's own ceiling for one
     * request. Not a cap on the ludography: the page asks for the next chunk
     * until the company runs out of games.
     */
    private const COMPANY_GAMES_PER_REQUEST = 500;

    /**
     * ISO 3166-1 numeric → alpha-2, the subset that covers the vast majority
     * of game studios; the page names the country in the viewer's language.
     * An unmapped code hides the country row rather than showing a number.
     */
    private const COUNTRIES = [
        36 => 'AU',
        40 => 'AT',
        56 => 'BE',
        76 => 'BR',
        124 => 'CA',
        156 => 'CN',
        203 => 'CZ',
        208 => 'DK',
        246 => 'FI',
        250 => 'FR',
        276 => 'DE',
        300 => 'GR',
        344 => 'HK',
        348 => 'HU',
        372 => 'IE',
        376 => 'IL',
        380 => 'IT',
        392 => 'JP',
        410 => 'KR',
        484 => 'MX',
        528 => 'NL',
        554 => 'NZ',
        578 => 'NO',
        616 => 'PL',
        620 => 'PT',
        642 => 'RO',
        643 => 'RU',
        702 => 'SG',
        703 => 'SK',
        710 => 'ZA',
        724 => 'ES',
        752 => 'SE',
        756 => 'CH',
        158 => 'TW',
        764 => 'TH',
        792 => 'TR',
        804 => 'UA',
        826 => 'GB',
        840 => 'US',
    ];

    public function __construct(
        private IgdbClient $client,
        private IgdbPayload $payload,
    ) {}

    public function company(string $ref, string $locale): CompanyDetails
    {
        if (! ctype_digit($ref)) {
            throw new ItemNotFoundException("IGDB does not serve a company for [{$ref}].");
        }

        // The company's developed/published arrays run to thousands of unsorted
        // ids (Nintendo: 674 / 2826), far too many to inline. The core record
        // comes in one request; the games come sorted, in chunks, from the games
        // endpoint (companyCredits) so the page opens on the notable ones.
        $data = $this->client->query(
            'companies',
            'fields name, description, country, start_date, url, logo.image_id, '
            .'websites.url, websites.type, parent.id, parent.name; '
            ."where id = {$ref};",
            $this->payload->ttl('details'),
            requireResults: true,
        )[0] ?? throw new ItemNotFoundException("IGDB returned no company for [{$ref}].");

        $parent = Payload::map($data['parent'] ?? null);
        $founded = Payload::int($data, 'start_date');
        $first = $this->companyCredits($ref, $locale, 1);

        return new CompanyDetails(
            ref: $this->payload->ref($data),
            name: Payload::text($data, 'name'),
            // PNG, not JPG: company logos are transparent, and JPG would flatten
            // them onto a solid (often black) fill.
            logoPath: $this->payload->imageUrl($data, 'logo', 'logo_med', 'png'),
            description: Payload::string($data, 'description'),
            founded: $founded === null ? null : $this->payload->utcDate($founded),
            country: self::COUNTRIES[Payload::int($data, 'country') ?? 0] ?? null,
            parentName: Payload::string($parent, 'name'),
            parentRef: Payload::id($parent['id'] ?? null),
            externalLinks: $this->payload->externalLinks($data),
            credits: $first->credits,
            hasMoreCredits: $first->hasMore,
        );
    }

    /**
     * The company's ludography (both roles, all category shelves), a chunk at a
     * time: one request for the top-rated back catalogue, plus — on the first
     * chunk — one for the upcoming front, since an unreleased game has no
     * rating and a rating sort would bury it where the page never reaches it.
     *
     * IGDB can't sort a company's developed/published arrays inline, but it can
     * filter the games endpoint by them — so rather than a query per role ×
     * category (a dozen round-trips) we fetch once per chunk and bucket role
     * (from each game's involved_companies) and category (from its game_type)
     * in PHP. A game the company both developed and published yields one credit
     * per role, so the page's Developer / Publisher sections both list it.
     *
     * Offsetting a rating sort is not perfectly stable — most games carry no
     * rating at all and tie — so a chunk may repeat a game the page already
     * holds; the page dedupes on arrival.
     */
    public function companyCredits(string $ref, string $locale, int $page): CreditPage
    {
        if (! ctype_digit($ref)) {
            return new CreditPage([], false);
        }

        $fields = IgdbPayload::SUMMARY_FIELDS
            .', involved_companies.company, involved_companies.developer, involved_companies.publisher, involved_companies.porting, involved_companies.supporting';
        $base = "involved_companies.company = {$ref} & version_parent = null "
            .'& '.GameType::clause(GameType::cases());

        $upcoming = $page === 1 ? $this->client->query(
            'games',
            "fields {$fields}; where {$base} & first_release_date >= ".$this->payload->startOfTodayUtc().'; '
            .'sort first_release_date asc; limit '.self::COMPANY_GAMES_PER_REQUEST.';',
            $this->payload->ttl('listing'),
        ) : [];

        $rated = $this->client->query(
            'games',
            "fields {$fields}; where {$base}; sort total_rating desc; "
            .'limit '.self::COMPANY_GAMES_PER_REQUEST.'; '
            .'offset '.((max(1, $page) - 1) * self::COMPANY_GAMES_PER_REQUEST).';',
            $this->payload->ttl('details'),
        );

        $credits = [];
        $seen = [];

        foreach (Payload::rows([...$upcoming, ...$rated]) as $game) {
            $id = $this->payload->ref($game);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $summary = $this->payload->summary($game);
            $category = (Payload::intEnum($game, 'game_type', GameType::class) ?? GameType::Main)->shelf();

            foreach ($this->companyRoles($game, (int) $ref) as $role) {
                $credits[] = new CompanyCredit(media: $summary, role: $role, category: $category);
            }
        }

        return new CreditPage($credits, count($rated) === self::COMPANY_GAMES_PER_REQUEST);
    }

    /**
     * The roles this company holds on a game (developer, publisher, porting,
     * supporting, any of them together), read from the game's
     * involved_companies entry for this company id.
     *
     * @param  array<array-key, mixed>  $game
     * @return list<CompanyRole>
     */
    private function companyRoles(array $game, int $ref): array
    {
        $roles = [];

        foreach (Payload::list($game['involved_companies'] ?? null) as $involved) {
            // Expanded (`company.id`) or bare, by the fields the query asked for.
            $company = $involved['company'] ?? null;
            $companyId = is_array($company) ? Payload::int($company, 'id') : Payload::int($involved, 'company');

            if ($companyId !== $ref) {
                continue;
            }

            foreach (self::ROLES as $flag => $role) {
                if (Payload::bool($involved, $flag) === true) {
                    $roles[$role->value] = $role;
                }
            }
        }

        return array_values($roles);
    }
}
