<?php

namespace App\Actions\Media;

use App\Models\MediaItem;
use App\Models\User;
use App\Notifications\MediaEventPayload;

/**
 * One notification per user for every title of theirs that became an archive
 * entry — gone from its provider, kept for their history — listing them all.
 */
final readonly class AnnounceArchivedTitles
{
    public function __construct(private SendMediaNotification $send) {}

    /**
     * @param  array<int, list<MediaItem>>  $archived  user id => their titles that became archive entries
     * @return int notifications sent
     */
    public function execute(array $archived): int
    {
        $sent = 0;

        foreach (User::query()->whereIn('id', array_keys($archived))->get() as $user) {
            $sent += (int) $this->send->execute($user, MediaEventPayload::titlesRemoved($archived[$user->id]));
        }

        return $sent;
    }
}
