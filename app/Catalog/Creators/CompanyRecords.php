<?php

namespace App\Catalog\Creators;

use App\Catalog\Data\CompanyDetails;
use App\Models\Company;

/**
 * The rows of studios, publishers and networks, told apart by `kind` — a
 * studio and a network can share a numeric ref. A company gets its row on
 * first interaction (a bell, a list).
 */
final readonly class CompanyRecords
{
    public function __construct(private CreatorPages $pages) {}

    /** The row, asking the provider only when there is none yet. */
    public function ensure(string $provider, string $ref, CreatorKind $kind, string $locale): Company
    {
        return $this->find($provider, $ref, $kind)
            ?? $this->fromDetails($provider, $ref, $kind, $this->pages->company(new CreatorRef($kind, $provider, $ref), $locale));
    }

    /**
     * Re-reads an existing row from its provider. {@see ensure()} trusts a row
     * once it exists — a logo that moved upstream is put right by the
     * scheduled sweep.
     */
    public function refresh(Company $company, string $locale): Company
    {
        $kind = CreatorKind::from($company->kind);

        return $this->fromDetails(
            $company->provider,
            $company->provider_ref,
            $kind,
            $this->pages->company(new CreatorRef($kind, $company->provider, $company->provider_ref), $locale),
        );
    }

    /** The full row from a page payload — the one path that fills `metadata`. */
    public function fromDetails(string $provider, string $ref, CreatorKind $kind, CompanyDetails $details): Company
    {
        $company = Company::query()->firstOrNew(['provider' => $provider, 'kind' => $kind->value, 'provider_ref' => $ref]);

        $company->fill([
            'name' => $details->name,
            'logo_path' => $details->logoPath ?? $company->logo_path,
            'metadata' => CreatorFields::metadata($company->metadata, CompanyMetadata::fromDetails($details)->toStored()),
            'refreshed_at' => now(),
        ]);

        $company->save();

        return $company;
    }

    /**
     * The row read back as a page, from what {@see fromDetails()} stored: the
     * page a provider outage falls back on. Nothing links a title to its
     * studio in the database (only people get credit rows), so it lists none.
     */
    public function details(Company $company): CompanyDetails
    {
        $metadata = CompanyMetadata::fromStored($company->metadata);

        return new CompanyDetails(
            ref: $company->provider_ref,
            name: $company->name,
            logoPath: $company->logo_path,
            description: $metadata->description,
            founded: $metadata->founded,
            country: $metadata->country,
            parentName: $metadata->parentName,
            parentRef: $metadata->parentRef,
            externalLinks: $metadata->externalLinks,
            credits: [],
        );
    }

    private function find(string $provider, string $ref, CreatorKind $kind): ?Company
    {
        return Company::query()->where('provider', $provider)->where('kind', $kind->value)->where('provider_ref', $ref)->first();
    }
}
