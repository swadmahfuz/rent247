<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PdfGenerator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        return Inertia::render('Payments/Index', [
            'items' => Payment::with(['invoice.lease.tenant', 'invoice.lease.unit', 'invoice.billingPeriod'])
                ->whereHas('invoice', fn ($q) => $q->where('property_id', $property->id))
                ->latest('paid_on')
                ->latest('id')
                ->paginate(40),
            'openInvoices' => Invoice::with(['lease.tenant', 'lease.unit', 'billingPeriod'])
                ->where('property_id', $property->id)
                ->whereIn('status', ['issued', 'partial'])
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $data = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'paid_on' => 'required|date',
            'method' => 'nullable|string|max:50',
            'note' => 'nullable|string|max:255',
        ]);

        $invoice = Invoice::findOrFail($data['invoice_id']);
        abort_unless($invoice->property_id === $property->id, 403);
        abort_unless(in_array($invoice->status, ['issued', 'partial', 'draft'], true), 422, 'Invoice cannot accept payments.');

        $balance = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);
        if ((float) $data['amount'] > $balance + 0.01) {
            return back()->withErrors([
                'amount' => 'Amount exceeds remaining balance of '.number_format($balance, 2).'.',
            ]);
        }

        if ($invoice->status === 'draft') {
            $invoice->update([
                'status' => 'issued',
                'issued_at' => $invoice->issued_at ?? now(),
            ]);
        }

        Payment::create($data);
        $invoice->refreshPaymentStatus();

        return back()->with('success', 'Payment recorded.');
    }

    public function destroy(Request $request, Payment $payment)
    {
        $property = $request->attributes->get('currentProperty');
        $payment->load('invoice');
        abort_unless($property && $payment->invoice->property_id === $property->id, 403);

        $invoice = $payment->invoice;
        $payment->delete();
        $invoice->refreshPaymentStatus();

        return back()->with('success', 'Payment deleted.');
    }

    public function receipt(Request $request, Payment $payment, PdfGenerator $pdfGenerator): Response
    {
        $property = $request->attributes->get('currentProperty');
        $payment->load('invoice');
        abort_unless($property && $payment->invoice->property_id === $property->id, 403);

        $name = 'receipt-'.$payment->id.'.pdf';

        return $pdfGenerator->receipt($payment)->download($name);
    }
}
