<?php

namespace App\Accounts\Preferences;

/**
 * The kinds of event a notification can be about — an axis orthogonal to the
 * tracking matrix: the matrix picks WHICH titles notify, these pick WHAT
 * ABOUT them. Each can be switched off; all are on until the user does, but
 * the announcements of a game's DLC, remakes and remasters, which a long-lived
 * game brings often.
 */
enum NotificationEvent: string
{
    case Episodes = 'episodes';
    case Premieres = 'premieres';
    case DateChanges = 'date_changes';
    case ShowStatus = 'show_status';
    case PersonTitles = 'person_titles';
    case CompanyTitles = 'company_titles';

    case RelatedAnnounced = 'related_announced';

    /** A bell on a collection IS the opt-in; the toggle is for whoever changes their mind. */
    case CollectionItems = 'collection_items';
}
