<?php

namespace App\Notifications\Channels;

/** What one hourly digest run did, channel by channel, for its task summary. */
final class DigestRun
{
    public int $sent = 0;

    public int $failed = 0;

    public int $empty = 0;

    public int $done = 0;

    public int $waiting = 0;

    public function summary(): string
    {
        $hour = sprintf('%02d:00', DailyDigests::HOUR);

        return "Sent {$this->sent} digests, {$this->failed} failed, {$this->empty} had nothing new, "
            ."{$this->done} already covered today, {$this->waiting} waiting for their local {$hour}.";
    }
}
