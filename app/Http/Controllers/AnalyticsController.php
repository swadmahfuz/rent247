<?php

namespace App\Http\Controllers;

use App\Models\BillingPeriod;
use App\Services\PdfGenerator;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __invoke()
    {
        return redirect()->route('dashboard');
    }

    public function summaryPdf(Request $request, BillingPeriod $billing, PdfGenerator $pdfGenerator)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $billing->property_id === $property->id, 403);

        return $pdfGenerator->summary($billing)->stream('summary-'.$billing->period_key.'.pdf');
    }
}
