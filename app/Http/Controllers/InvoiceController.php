<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MailLog;
use App\Services\BillingCalculator;
use App\Services\InvoicePacketBuilder;
use App\Services\PdfGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $query = Invoice::with(['lease.tenant', 'lease.unit', 'billingPeriod.documents'])
            ->where('property_id', $property->id)
            ->latest('id');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $items = $query->paginate(30)->withQueryString();
        $packet = app(InvoicePacketBuilder::class);
        $items->getCollection()->transform(function (Invoice $invoice) use ($packet) {
            $invoice->setAttribute('attachment_status', $packet->attachmentStatus($invoice));

            return $invoice;
        });

        return Inertia::render('Invoices/Index', [
            'items' => $items,
            'filters' => ['status' => $status],
        ]);
    }

    public function show(Request $request, Invoice $invoice, BillingCalculator $calculator)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $invoice->property_id === $property->id, 403);

        $invoice->load(['lines.chargeType', 'lease.tenant', 'lease.unit', 'billingPeriod.documents', 'payments', 'property']);
        $attachmentStatus = app(InvoicePacketBuilder::class)->attachmentStatus($invoice);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
            'amountInWords' => $calculator->amountInWords((float) $invoice->total_amount, $property->currency),
            'attachmentStatus' => $attachmentStatus,
        ]);
    }

    public function updateLines(Request $request, Invoice $invoice)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $invoice->property_id === $property->id, 403);
        abort_unless(in_array($invoice->status, ['draft', 'issued', 'partial'], true), 422);

        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'nullable|integer',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.period_label' => 'nullable|string|max:50',
            'lines.*.amount' => 'required|numeric',
            'lines.*.charge_type_id' => 'nullable|exists:charge_types,id',
        ]);

        $invoice->lines()->delete();
        foreach ($data['lines'] as $i => $line) {
            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'charge_type_id' => $line['charge_type_id'] ?? null,
                'description' => $line['description'],
                'period_label' => $line['period_label'] ?? null,
                'amount' => $line['amount'],
                'sort_order' => $i + 1,
            ]);
        }

        $invoice->total_amount = round($invoice->lines()->sum('amount'), 2);
        $invoice->save();
        $invoice->refreshPaymentStatus();

        return back()->with('success', 'Invoice lines updated.');
    }

    public function issue(Request $request, Invoice $invoice)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $invoice->property_id === $property->id, 403);

        $invoice->update([
            'status' => $invoice->paid_amount > 0 ? ($invoice->is_fully_paid ? 'paid' : 'partial') : 'issued',
            'issued_at' => $invoice->issued_at ?? now(),
        ]);

        return back()->with('success', 'Invoice issued.');
    }

    public function pdf(Request $request, Invoice $invoice, InvoicePacketBuilder $packetBuilder): Response
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $invoice->property_id === $property->id, 403);

        return $packetBuilder->downloadResponse($invoice);
    }

    public function email(Request $request, Invoice $invoice, PdfGenerator $pdfGenerator)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $invoice->property_id === $property->id, 403);

        $invoice->load('lease.tenant');
        $email = $invoice->lease->tenant->email;
        abort_unless($email, 422, 'Tenant has no email address.');

        try {
            $path = $pdfGenerator->storeInvoice($invoice);
            Mail::to($email)->send(new InvoiceMail($invoice, $path));
            MailLog::create([
                'invoice_id' => $invoice->id,
                'to_email' => $email,
                'status' => 'sent',
            ]);

            return back()->with('success', 'Invoice emailed to '.$email);
        } catch (\Throwable $e) {
            MailLog::create([
                'invoice_id' => $invoice->id,
                'to_email' => $email,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Email failed: '.$e->getMessage());
        }
    }
}
