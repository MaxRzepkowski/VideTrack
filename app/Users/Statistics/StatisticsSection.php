<?php

namespace App\Users\Statistics;

/**
 * A part of the statistics page, worked out and kept on its own: the top
 * (the period's card, tiles and titles) and the sections below it.
 */
enum StatisticsSection: string
{
    case Summary = 'summary';
    case Activity = 'activity';
    case Taste = 'taste';
    case Origins = 'origins';
    case Creators = 'creators';
    case Ratings = 'ratings';
    case Records = 'records';
}
