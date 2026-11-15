<?php

namespace App\Actions\Scrobble;

use App\Exceptions\EndpointLimitReached;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;

/**
 * A new address for one media server, saved with its first token — up to the
 * configured number per server. The address is written before it is counted:
 * the write takes the database's lock, so two requests at once cannot both
 * slip under the limit.
 */
final readonly class AddWebhookEndpoint
{
    public function __construct(private IssueWebhookToken $issueToken) {}

    public function execute(User $user, string $source): WebhookEndpoint
    {
        $limit = config()->integer('tracker.scrobble.endpoints_per_source');

        return DB::transaction(function () use ($user, $source, $limit): WebhookEndpoint {
            $endpoint = new WebhookEndpoint(['user_id' => $user->id, 'source' => $source]);
            $this->issueToken->execute($endpoint);

            if ($user->webhookEndpoints()->where('source', $source)->count() > $limit) {
                throw new EndpointLimitReached($limit);
            }

            return $endpoint;
        });
    }
}
