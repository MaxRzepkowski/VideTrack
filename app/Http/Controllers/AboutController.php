<?php

namespace App\Http\Controllers;

use App\Catalog\Data\DataSourceCredit;
use App\Catalog\DataSources;
use Inertia\Inertia;
use Inertia\Response;

final class AboutController extends Controller
{
    public function __invoke(DataSources $sources): Response
    {
        return Inertia::render('About', [
            'version' => config('tracker.version'),
            'sourceUrl' => config('tracker.source_url'),
            'dataSources' => array_map(fn (DataSourceCredit $credit): DataSourceCredit => $credit->translated(), $sources->all()),
        ]);
    }
}
