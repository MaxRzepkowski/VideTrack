<?php

namespace App\Catalog;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The regions games premiere in. A platform release carries one of these
 * rather than a country (publishers date a game for "Europe", not for
 * Poland), so a viewer's country is read as the region it belongs to.
 */
#[TypeScript]
enum ReleaseRegion: string
{
    case Europe = 'europe';

    case NorthAmerica = 'north_america';

    case Australia = 'australia';

    case NewZealand = 'new_zealand';

    case Japan = 'japan';

    case China = 'china';

    case Asia = 'asia';

    case Worldwide = 'worldwide';

    case Korea = 'korea';

    case Brazil = 'brazil';

    /** Countries that are a region of their own. */
    private const SINGLE_COUNTRY = [
        'AU' => self::Australia,
        'NZ' => self::NewZealand,
        'JP' => self::Japan,
        'CN' => self::China,
        'KR' => self::Korea,
        'BR' => self::Brazil,
    ];

    /** The PAL market: the European continent and Turkey. */
    private const EUROPEAN = [
        'AD', 'AL', 'AT', 'AX', 'BA', 'BE', 'BG', 'BY', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FO', 'FR',
        'GB', 'GG', 'GI', 'GR', 'HR', 'HU', 'IE', 'IM', 'IS', 'IT', 'JE', 'LI', 'LT', 'LU', 'LV', 'MC', 'MD', 'ME',
        'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'RU', 'SE', 'SI', 'SK', 'SM', 'TR', 'UA', 'VA', 'XK',
    ];

    private const NORTH_AMERICAN = ['US', 'CA', 'MX', 'PR'];

    /** Asia outside its single-country regions. */
    private const ASIAN = [
        'AE', 'AF', 'AM', 'AZ', 'BD', 'BH', 'BN', 'BT', 'GE', 'HK', 'ID', 'IL', 'IN', 'IQ', 'IR', 'JO', 'KG', 'KH',
        'KW', 'KZ', 'LA', 'LB', 'LK', 'MM', 'MN', 'MO', 'MV', 'MY', 'NP', 'OM', 'PH', 'PK', 'PS', 'QA', 'SA', 'SG',
        'SY', 'TH', 'TJ', 'TL', 'TM', 'TW', 'UZ', 'VN', 'YE',
    ];

    /** The region an ISO 3166-1 country belongs to; a country in none of them reads worldwide dates. */
    public static function ofCountry(string $country): self
    {
        $country = strtoupper($country);

        return self::SINGLE_COUNTRY[$country] ?? match (true) {
            in_array($country, self::EUROPEAN, true) => self::Europe,
            in_array($country, self::NORTH_AMERICAN, true) => self::NorthAmerica,
            in_array($country, self::ASIAN, true) => self::Asia,
            default => self::Worldwide,
        };
    }
}
