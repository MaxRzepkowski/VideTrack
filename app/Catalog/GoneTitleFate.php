<?php

namespace App\Catalog;

/** What became of a title its data source no longer has. */
enum GoneTitleFate
{
    /** Nobody kept anything on it: it is gone here too. */
    case Deleted;

    /** It just became an archive entry; its holders are told once. */
    case Archived;

    /** An archive entry already, since an earlier refresh. */
    case StillArchived;
}
