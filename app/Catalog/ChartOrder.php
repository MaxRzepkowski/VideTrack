<?php

namespace App\Catalog;

/**
 * What a provider's chart ranks its titles by.
 */
enum ChartOrder: string
{
    /** By how many people rated a title: how widely it is known, steadier than a week's popularity. */
    case MostVoted = 'most-voted';

    /** By the rating itself, among titles rated by enough people for it to mean something. */
    case TopRated = 'top-rated';
}
