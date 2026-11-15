<?php

namespace App\Integrations\Import\Rows;

/**
 * One unit of an import stream, and one tick of the run's progress bar.
 * A closed family: MediaRow, EpisodeRunRow, ListRow, PersonListRow,
 * CompanyListRow, CreatorFollowRow, SkipRow.
 */
interface ImportRow {}
