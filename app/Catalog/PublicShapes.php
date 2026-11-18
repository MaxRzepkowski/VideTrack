<?php

namespace App\Catalog;

use App\Catalog\Data\MediaSummary;
use App\Models\Company;
use App\Models\Person;

/**
 * A title, a person and a company in the shape they leave the instance in:
 * the API's answers, and whatever else hands them on in the same words.
 */
final class PublicShapes
{
    /**
     * The card the pages render, field for field, with its images as
     * addresses a client can load; the paths beside them are the providers' own.
     *
     * @return array<string, mixed>
     */
    public static function card(MediaSummary $summary): array
    {
        return [
            ...$summary->jsonSerialize(),
            'posterUrl' => ImageUrls::of($summary->posterPath, ImageUrls::POSTER),
            'backdropUrl' => ImageUrls::of($summary->backdropPath, ImageUrls::BACKDROP),
        ];
    }

    /**
     * @return array{provider: string, ref: string, name: string, profilePath: string|null, profileUrl: string|null}
     */
    public static function person(Person $person): array
    {
        return [
            'provider' => $person->provider,
            'ref' => $person->provider_ref,
            'name' => $person->name,
            'profilePath' => $person->profile_path,
            'profileUrl' => ImageUrls::of($person->profile_path, ImageUrls::POSTER),
        ];
    }

    /**
     * @return array{provider: string, ref: string, kind: string, name: string, logoPath: string|null, logoUrl: string|null}
     */
    public static function company(Company $company): array
    {
        return [
            'provider' => $company->provider,
            'ref' => $company->provider_ref,
            'kind' => $company->kind,
            'name' => $company->name,
            'logoPath' => $company->logo_path,
            'logoUrl' => ImageUrls::of($company->logo_path, ImageUrls::BACKDROP),
        ];
    }
}
