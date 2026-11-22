<?php

namespace App\Http\Middleware;

use App\Accounts\Identity\Data\IdentityProviderOption;
use App\Accounts\Identity\IdentityProviderRegistry;
use App\Accounts\PasswordLogin;
use App\Accounts\Registration;
use App\Actions\BuildNotificationFeed;
use App\Catalog\Discovery;
use App\Catalog\ImageUrls;
use App\Catalog\MediaTypeDefinition;
use App\Catalog\MediaTypeRegistry;
use App\Http\TitlePages;
use App\Library\Data\UserList;
use App\Library\Data\UserMediaState;
use App\Models\MediaList;
use App\Models\User;
use App\Notifications\Data\NotificationFeed;
use App\Tracking\Data\EventEntry;
use App\Tracking\Events\VisibleEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly MediaTypeRegistry $types,
        private readonly Discovery $discovery,
        private readonly BuildNotificationFeed $notificationFeed,
        private readonly VisibleEvents $events,
        private readonly Registration $registration,
        private readonly PasswordLogin $passwordLogin,
        private readonly IdentityProviderRegistry $identityProviders,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $this->viewer($request->user()),
            ],
            // A signed-in visitor has nothing to sign up for; the sign-up form
            // asks for a password, so it goes where passwords do.
            'registrationEnabled' => $request->user() === null && $this->registration->isOpen() && $this->passwordLogin->isEnabled(),
            // Whether passwords sign in here, and the identity providers that
            // do: the sign-in page and the settings menu are drawn from them.
            'passwordLogin' => $this->passwordLogin->isEnabled(),
            'identityProviders' => fn (): array => array_map(IdentityProviderOption::of(...), $this->identityProviders->all()),
            'locale' => App::getLocale(),
            'imageBase' => ImageUrls::base(),
            // The footer names the running version and links its source on
            // every page: the AGPL asks each instance to offer its users the code.
            'version' => config()->string('tracker.version'),
            'sourceUrl' => config()->string('tracker.source_url'),
            // The registry's types, in its order: the front reads what a type
            // can do (played, given a status, counted in pages…) from here
            // instead of keeping a copy of the backend's knowledge.
            'mediaTypes' => array_values(array_map(fn (MediaTypeDefinition $type): array => [
                'code' => $type->code,
                'parentType' => $type->parentType,
                'childType' => $type->childType,
                'playable' => $type->playable,
                'userStatus' => $type->userStatus,
                'progressUnit' => $type->progressUnit?->value,
                'ownPage' => TitlePages::hasOwnPage($type->code),
                'genreBrowsable' => $this->discovery->browsesGenresOf($type->code),
            ], $this->types->all())),
            // The header bell: unread counter + the latest few for the
            // dropdown. Only the state at render time — an open tab keeps it
            // current by polling notifications.feed, which answers with the
            // very same payload.
            'notifications' => function () use ($request): ?NotificationFeed {
                $user = $request->user();

                if ($user === null) {
                    return null;
                }

                return $this->notificationFeed->execute($user, App::getLocale());
            },
            // The "live" button: the events running or starting soon, as one
            // small read; the page switches the button on and off by their
            // times, never asking again.
            'liveEvents' => function () use ($request): array {
                $user = $request->user();

                return $user === null ? [] : array_values($this->events->liveOrSoon($user, now())->map(EventEntry::of(...))->all());
            },
            // What a card shows for a title the page sent no state for.
            'untouchedState' => fn (): ?UserMediaState => $request->user() === null ? null : UserMediaState::untouched(),
            // The lists modal needs every list of the user, each named as
            // the reader sees it.
            'userLists' => function () use ($request): array {
                $user = $request->user();

                if ($user === null) {
                    return [];
                }

                // The manual list order, exactly as the lists index reads it:
                // the modal is the same set of lists, so someone who dragged
                // theirs into an order must meet it here too.
                return $user->lists()
                    ->inManualOrder()
                    ->get()
                    ->map(fn (MediaList $list) => new UserList($list->id, $list->displayTitle(), $list->system_key))
                    ->all();
            },
        ];
    }

    /**
     * The signed-in user as every page sees them: the profile and the display
     * preferences, picked field by field so a new column never reaches the
     * page by accident. Credentials (a notification channel's address) stay
     * out, here and on the page that edits them.
     *
     * @return array<string, mixed>|null
     */
    private function viewer(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'bio' => $user->bio,
            'avatar' => $user->avatar,
            'is_private' => $user->is_private,
            'locale' => $user->locale,
            'preferences' => $user->preferences->resolved(),
            // Confirms what cannot be undone with the password, or else with the username.
            'password_sign_in' => $this->passwordLogin->isWayInFor($user),
        ];
    }
}
