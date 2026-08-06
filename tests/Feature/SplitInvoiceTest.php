<?php

namespace Tests\Feature;

use App\Models\BillingPeriod;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\BillingCalculator;
use App\Services\PdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_layout_keeps_one_invoice_record_and_generates_a_pdf(): void
    {
        $this->seed();

        $lease = Lease::whereHas('unit', fn ($query) => $query->where('label', '1st'))->firstOrFail();
        $lease->update(['invoice_mode' => 'split']);

        $period = BillingPeriod::where('year', 2026)->where('month', 8)->firstOrFail();
        app(BillingCalculator::class)->generate($period);

        $invoices = Invoice::where('billing_period_id', $period->id)
            ->where('lease_id', $lease->id)
            ->get()
            ->values();

        $this->assertCount(1, $invoices);
        $this->assertSame('142285.06', $invoices->first()->total_amount);
        $this->assertSame(
            '100000.00',
            number_format(
                $invoices->first()->lines()
                    ->whereHas('chargeType', fn ($query) => $query->where('category', 'rent'))
                    ->sum('amount'),
                2,
                '.',
                ''
            )
        );

        $pdf = app(PdfGenerator::class)->invoice($invoices->first());
        $this->assertStringStartsWith('%PDF', $pdf->output());
    }
}
