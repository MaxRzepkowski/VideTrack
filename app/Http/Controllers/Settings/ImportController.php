<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AnalyzeImportRequest;
use App\Http\Requests\Settings\ImportRequest;
use App\Integrations\Connections\ConnectionRegistry;
use App\Integrations\Import\Contracts\ExplainsSetup;
use App\Integrations\Import\Contracts\ImportSource;
use App\Integrations\Import\Contracts\ReadsCredentials;
use App\Integrations\Import\Contracts\ReadsFile;
use App\Integrations\Import\Contracts\ReadsFromConnection;
use App\Integrations\Import\Data\ConnectDef;
use App\Integrations\Import\Data\ImportSourceDef;
use App\Integrations\Import\ImportInputKind;
use App\Integrations\Import\ImportSourceRegistry;
use App\Jobs\RunImport;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Import Data" settings section: the page lists
 * whatever the import registry holds, a chosen source is handed to RunImport,
 * and progress and the outcome show up in the "Tasks" section (task_runs).
 */
class ImportController extends Controller
{
    public function index(#[CurrentUser] User $viewer, Request $request, ImportSourceRegistry $sources, ConnectionRegistry $connections): Response
    {
        $held = $viewer->serviceConnections()->get()->keyBy('service');

        return Inertia::render('settings/Import', [
            'sources' => array_map(fn (ImportSource $source) => new ImportSourceDef(
                code: $source->code(),
                label: $source->label(),
                description: $source->description(),
                inputKind: ImportInputKind::of($source),
                accept: $source instanceof ReadsFile ? $source->accept() : null,
                credentials: $source instanceof ReadsCredentials ? $source->credentialsSchema() : [],
                notes: $source instanceof ExplainsSetup ? $source->notes() : [],
                connect: $source instanceof ReadsFromConnection
                    ? ConnectDef::of($source, $connections, $held->get($source->connectionService()))
                    : null,
            ), $sources->all()),
            // The upload limit the request enforces, so the page says the same number.
            'maxFileMegabytes' => intdiv(ImportRequest::MAX_FILE_KB, 1024),
            // A trip to a service's site comes back here naming its source.
            'selected' => $sources->find($request->string('source')->value())?->code(),
        ]);
    }

    public function store(#[CurrentUser] User $viewer, ImportRequest $request): RedirectResponse
    {
        RunImport::queueTask($viewer, $request->source()->code(), $request->stashInput($viewer), $request->selection());

        return back();
    }

    /**
     * Reads the input without importing anything and returns its manifest as
     * JSON — what it holds, with counts, and the custom lists to pick from.
     * Plain JSON (not an Inertia render) so the page URL and history stay
     * put; the front end fetches it directly.
     */
    public function analyze(AnalyzeImportRequest $request): JsonResponse
    {
        return response()->json($request->source()->analyze($request->analysisInput())->toArray());
    }
}
