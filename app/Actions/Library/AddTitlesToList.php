<?php

namespace App\Actions\Library;

use App\Catalog\Data\MediaSummary;
use App\Catalog\ItemNotFoundException;
use App\Catalog\MediaTypeRegistry;
use App\Catalog\ProviderException;
use App\Catalog\ProviderRejected;
use App\Catalog\StoredPositions;
use App\Catalog\TitleStore;
use App\Library\Data\ListAddition;
use App\Library\Data\ListTarget;
use App\Library\ListAddOutcome;
use App\Models\MediaItem;
use App\Models\MediaList;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Many titles onto the end of one of the user's lists, in the order given.
 * A title nobody stored is fetched from its provider first, outside the
 * write: a slow provider must not hold the list open. Once a provider
 * cannot be asked, the titles left to fetch from it are not asked about,
 * since each would only wait on the same failure; other providers' titles
 * still are.
 */
final readonly class AddTitlesToList
{
    public function __construct(
        private TitleStore $titles,
        private StoredPositions $stored,
        private MediaTypeRegistry $types,
        private AddToList $add,
    ) {}

    /**
     * @param  list<array{type: string, ref: string}>  $positions  each once, in the order they go on
     * @return list<ListAddition> in the same order
     */
    public function execute(User $user, MediaList $list, array $positions, string $locale): array
    {
        $fetched = $this->fetch($positions, $locale);

        return DB::transaction(function () use ($user, $list, $fetched): array {
            $additions = [];

            foreach ($fetched as $position) {
                if ($position instanceof ListAddition) {
                    $additions[] = $position;

                    continue;
                }

                $added = $this->add->execute($user, $list, ListTarget::item($position['item']->id));
                $additions[] = new ListAddition($position['type'], $position['ref'], $added ? ListAddOutcome::Added : ListAddOutcome::Present, $position['item']);
            }

            return $additions;
        });
    }

    /**
     * Each position's row, or what kept it from having one.
     *
     * @param  list<array{type: string, ref: string}>  $positions
     * @return list<array{type: string, ref: string, item: MediaItem}|ListAddition>
     */
    private function fetch(array $positions, string $locale): array
    {
        $stored = $this->stored->among(array_map(fn (array $position): array => [$position['type'], $position['ref']], $positions));
        $silent = [];
        $fetched = [];

        foreach ($positions as ['type' => $type, 'ref' => $ref]) {
            $item = $stored[MediaSummary::keyOf($type, $ref)] ?? null;
            $provider = $this->types->get($type)->provider;

            if ($item === null && isset($silent[$provider])) {
                $fetched[] = new ListAddition($type, $ref, ListAddOutcome::Unavailable);

                continue;
            }

            try {
                $fetched[] = ['type' => $type, 'ref' => $ref, 'item' => $item ?? $this->titles->persist($type, $ref, $locale)];
            } catch (ItemNotFoundException) {
                $fetched[] = new ListAddition($type, $ref, ListAddOutcome::NotFound);
            } catch (ProviderException|ProviderRejected $e) {
                report($e);
                $silent[$provider] = true;
                $fetched[] = new ListAddition($type, $ref, ListAddOutcome::Unavailable);
            }
        }

        return $fetched;
    }
}
