<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $property = $request->attributes->get('currentProperty');

        $propertyIds = $property
            ? collect([$property->id])
            : Property::where('user_id', $user->id)->pluck('id');

        $invoiceQuery = Invoice::whereIn('property_id', $propertyIds)
            ->whereIn('status', ['issued', 'paid', 'partial']);

        $collectable = (clone $invoiceQuery)->sum('total_amount');
        $paid = (clone $invoiceQuery)->sum('paid_amount');
        $outstanding = $collectable - $paid;
        $draftCount = Invoice::whereIn('property_id', $propertyIds)->where('status', 'draft')->count();

        $byMonth = Invoice::query()
            ->select(
                'billing_periods.year',
                'billing_periods.month',
                DB::raw('SUM(invoices.total_amount) as total'),
                DB::raw('SUM(invoices.paid_amount) as paid')
            )
            ->join('billing_periods', 'billing_periods.id', '=', 'invoices.billing_period_id')
            ->whereIn('invoices.property_id', $propertyIds)
            ->whereIn('invoices.status', ['issued', 'paid', 'partial', 'draft'])
            ->groupBy('billing_periods.year', 'billing_periods.month')
            ->orderByDesc('billing_periods.year')
            ->orderByDesc('billing_periods.month')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        $recentPayments = Payment::query()
            ->with(['invoice.lease.tenant', 'invoice.property'])
            ->whereHas('invoice', fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->latest('paid_on')
            ->limit(8)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'collectable' => round((float) $collectable, 2),
                'paid' => round((float) $paid, 2),
                'outstanding' => round((float) $outstanding, 2),
                'draft_count' => $draftCount,
            ],
            'byMonth' => $byMonth,
            'recentPayments' => $recentPayments,
        ]);
    }
}
