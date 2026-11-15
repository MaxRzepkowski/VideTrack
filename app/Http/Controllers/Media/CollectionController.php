<?php

namespace App\Http\Controllers\Media;

use App\Actions\Media\BuildListPage;
use App\Actions\Media\MaterializeCollection;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A provider collection's page, at an address of the provider's own: its
 * system list is materialized on the first visit and shown as any list is.
 */
class CollectionController extends Controller
{
    public function __invoke(#[CurrentUser] User $viewer, MaterializeCollection $materialize, BuildListPage $page, string $provider, string $ref): Response
    {
        return Inertia::render('lists/ListDetails', $page->execute($viewer, $materialize->execute($provider, $ref, App::getLocale())));
    }
}
