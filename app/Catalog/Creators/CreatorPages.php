<?php

namespace App\Catalog\Creators;

use App\Catalog\Contracts\SupportsCompanyPage;
use App\Catalog\Contracts\SupportsNetworkPage;
use App\Catalog\Contracts\SupportsPersonPage;
use App\Catalog\Data\CompanyDetails;
use App\Catalog\Data\CreditPage;
use App\Catalog\Data\PersonDetails;
use App\Catalog\MatureChoices;
use App\Catalog\ProviderRegistry;
use App\Support\ExternalServiceException;

/**
 * The one place that knows which provider capability serves which kind of
 * creator page: a person page, a studio page, a network page — three
 * capabilities a provider may have independently (IGDB has studios and no
 * networks), so they stay three contracts and the choice is made here, once.
 * A collection has a list page of its own and no creator page.
 */
final readonly class CreatorPages
{
    public function __construct(private ProviderRegistry $providers) {}

    public function serves(CreatorRef $creator): bool
    {
        $provider = $this->providers->find($creator->provider);

        return match ($creator->kind) {
            CreatorKind::Person => $provider instanceof SupportsPersonPage,
            CreatorKind::Company => $provider instanceof SupportsCompanyPage,
            CreatorKind::Network => $provider instanceof SupportsNetworkPage,
            CreatorKind::Collection => false,
        };
    }

    /**
     * @throws UnknownCreator|ExternalServiceException
     */
    public function page(CreatorRef $creator, string $locale, MatureChoices $mature): PersonDetails|CompanyDetails
    {
        return $creator->kind === CreatorKind::Person
            ? $this->person($creator, $locale, $mature)
            : $this->company($creator, $locale, $mature);
    }

    /**
     * @throws UnknownCreator|ExternalServiceException
     */
    public function person(CreatorRef $creator, string $locale, MatureChoices $mature): PersonDetails
    {
        $provider = $this->providers->find($creator->provider);

        if ($creator->kind !== CreatorKind::Person || ! $provider instanceof SupportsPersonPage) {
            throw UnknownCreator::of($creator);
        }

        return $provider->person($creator->ref, $locale, $mature->for($creator->provider));
    }

    /**
     * A studio, publisher or network.
     *
     * @throws UnknownCreator|ExternalServiceException
     */
    public function company(CreatorRef $creator, string $locale, MatureChoices $mature): CompanyDetails
    {
        $provider = $this->providers->find($creator->provider);

        return match (true) {
            $creator->kind === CreatorKind::Company && $provider instanceof SupportsCompanyPage => $provider->company($creator->ref, $locale, $mature->for($creator->provider)),
            $creator->kind === CreatorKind::Network && $provider instanceof SupportsNetworkPage => $provider->network($creator->ref, $locale, $mature->for($creator->provider)),
            default => throw UnknownCreator::of($creator),
        };
    }

    /**
     * The later chunks of a studio or network catalogue.
     *
     * @throws UnknownCreator|ExternalServiceException
     */
    public function credits(CreatorRef $creator, string $locale, MatureChoices $mature, int $page): CreditPage
    {
        $provider = $this->providers->find($creator->provider);

        return match (true) {
            $creator->kind === CreatorKind::Company && $provider instanceof SupportsCompanyPage => $provider->companyCredits($creator->ref, $locale, $mature->for($creator->provider), $page),
            $creator->kind === CreatorKind::Network && $provider instanceof SupportsNetworkPage => $provider->networkCredits($creator->ref, $locale, $mature->for($creator->provider), $page),
            default => throw UnknownCreator::of($creator),
        };
    }
}
