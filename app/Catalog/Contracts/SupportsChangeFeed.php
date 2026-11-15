<?php

namespace App\Catalog\Contracts;

use App\Catalog\Data\ChangeReport;
use Carbon\CarbonImmutable;

/**
 * Capability: the provider can say which of our titles changed since a
 * moment, cheaper than asking about each of them.
 */
interface SupportsChangeFeed
{
    /**
     * Types the list can be asked about.
     *
     * @return list<string>
     */
    public function changeFeedTypes(): array;

    /**
     * The types among those whose list names every change a stored title
     * would show — so a title it does not name needs no asking. A type whose
     * list names only some changes adds to the asking and replaces none.
     *
     * @return list<string>
     */
    public function completeChangeFeedTypes(): array;

    /**
     * Which of the given refs changed since $since; unknown when the provider
     * cannot tell for that window, or finding out would cost more than asking
     * about each ref.
     *
     * @param  list<string>  $refs
     */
    public function changedAmong(string $type, array $refs, CarbonImmutable $since): ChangeReport;
}
