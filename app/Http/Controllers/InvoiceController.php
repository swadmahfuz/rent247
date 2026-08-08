<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceMail;
use App\Models\BillingPeriod;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MailLog;
use App\Models\Unit;
use App\Services\BillingCalculator;
use App\Services\InvoicePacketBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property, 404);

        $status = $request->string('status')->toString();
        $periodId = $request->integer('billing_period_id') ?: null;
        $unitId = $request->integer('unit_id') ?: null;

        $query = Invoice::query()
            ->select('invoices.*')
            ->join('billing_periods', 'billing_periods.id', '=', 'invoices.billing_period_id')
            ->with(['lease.tenant', 'lease.unit', 'billingPeriod.documents'])
            ->where('invoices.property_id', $property->id);

        if ($status === 'outstanding') {
            $query->whereIn('invoices.status', ['issued', 'partial']);
        } elseif ($status !== '') {
            $query->where('invoices.status', $status);
        }

        if ($periodId) {
            $query->where('invoices.billing_period_id', $periodId);
        }

        if ($unitId) {
            $query->whereHas('lease', fn ($q) => $q->where('unit_id', $unitId));
        }

        // Outstanding first (by remaining balance), then newest period, then id.
        $query->orderByRaw('CASE WHEN invoices.status IN (\'issued\', \'partial\') THEN 0 WHEN invoices.status = \'draft\' THEN 1 ELSE 2 END')
            ->orderByRaw('(invoices.total_amount - invoices.paid_amount) DESC')
            ->orderByDesc('billing_periods.year')
            ->orderByDesc('billing_periods.month')
            ->orderByDesc('invoices.id');

        $items = $query->paginate(30)->withQueryString();
        $packet = app(InvoicePacketBuilder::class);
        $items->getCollection()->transform(function (Invoice $invoice) use ($packet) {
            $invoice->setAttribute('attachment_status', $packet->attachmentStatus($invoice));

            return $invoice;
        });

        return Inertia::render('Invoices/Index', [
            'items' => $items,
            'filters' => [
                'status' => $status,
                'billing_period_id' => $periodId,
                'unit_id' => $unitId,
            ],
            'filterOptions' => [
                'periods' => BillingPeriod::where('property_id', $property->id)
                    ->orderByDesc('year')
                    ->orderByDesc('month')
                    ->get(['id', 'year', 'month']),
                'units' => Unit::where('property_id', $property->id)
                    ->orderBy('label')
                    ->get(['id', 'label']),
            ],
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

    public function email(Request $request, Invoice $invoice, InvoicePacketBuilder $packetBuilder)
    {
        $property = $request->attributes->get('currentProperty');
        abort_unless($property && $invoice->property_id === $property->id, 403);

        $invoice->load(['lease.tenant', 'property', 'billingPeriod']);
        $email = $invoice->lease?->tenant?->email;

        if (! $email) {
            return back()->with('error', 'Tenant has no email address. Add one on the Tenants page, then try again.');
        }

        try {
            $packet = $packetBuilder->build($invoice);
            $path = sprintf('invoices/%d/%s.pdf', $invoice->property_id, $invoice->number ?: $invoice->id);
            Storage::disk('local')->put($path, $packet['contents']);
            $invoice->update(['pdf_path' => $path]);
            Mail::to($email)->send(new InvoiceMail($invoice, $path));
            MailLog::create([
                'invoice_id' => $invoice->id,
                'to_email' => $email,
                'status' => 'sent',
            ]);

            $extra = $packet['has_attachments']
                ? ' (PDF includes utility bill pages)'
                : (count($packet['missing']) ? ' (PDF sent; some utility bills were missing)' : '');

            return back()->with('success', 'Invoice emailed to '.$email.$extra.'.');
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
