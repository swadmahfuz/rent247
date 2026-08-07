<?php

namespace App\Http\Controllers;

use App\Services\HistoricalInvoiceImporter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HistoricalImportController extends Controller
{
    public function index(Request $request, HistoricalInvoiceImporter $importer)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        return Inertia::render('Import/Index', [
            'headers' => $importer->templateHeaders($property),
            'result' => session('import_result'),
        ]);
    }

    public function template(Request $request, HistoricalInvoiceImporter $importer)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        return $importer->downloadTemplate($property);
    }

    public function store(Request $request, HistoricalInvoiceImporter $importer)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        try {
            $result = $importer->import($property, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('success', sprintf(
            'Import finished: %d imported, %d skipped, %d failed.',
            $result['imported'],
            $result['skipped'],
            $result['failed']
        ))->with('import_result', $result);
    }
}
