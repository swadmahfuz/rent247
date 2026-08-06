<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Collapse any split records created before this PDF-only layout change.
        $duplicateGroups = DB::table('invoices')
            ->select('billing_period_id', 'lease_id')
            ->groupBy('billing_period_id', 'lease_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $invoices = DB::table('invoices')
                ->where('billing_period_id', $group->billing_period_id)
                ->where('lease_id', $group->lease_id)
                ->orderByRaw("CASE WHEN type = 'combined' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->get();

            $keeper = $invoices->first();
            $otherIds = $invoices->skip(1)->pluck('id');

            DB::table('invoice_lines')->whereIn('invoice_id', $otherIds)
                ->update(['invoice_id' => $keeper->id]);
            DB::table('payments')->whereIn('invoice_id', $otherIds)
                ->update(['invoice_id' => $keeper->id]);
            DB::table('mail_logs')->whereIn('invoice_id', $otherIds)
                ->update(['invoice_id' => $keeper->id]);
            DB::table('invoices')->whereIn('id', $otherIds)->delete();

            $total = (float) DB::table('invoice_lines')
                ->where('invoice_id', $keeper->id)
                ->sum('amount');
            $paid = (float) DB::table('payments')
                ->where('invoice_id', $keeper->id)
                ->sum('amount');
            $hadIssuedInvoice = $invoices->contains(
                fn ($invoice) => $invoice->status !== 'draft'
            );

            $status = $paid > 0
                ? ($paid + 0.009 >= $total ? 'paid' : 'partial')
                : ($hadIssuedInvoice ? 'issued' : 'draft');

            DB::table('invoices')->where('id', $keeper->id)->update([
                'type' => 'combined',
                'total_amount' => round($total, 2),
                'paid_amount' => round($paid, 2),
                'status' => $status,
            ]);
        }

        DB::table('invoices')->update(['type' => 'combined']);

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_period_lease_type_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(
                ['billing_period_id', 'lease_id'],
                'invoices_billing_period_id_lease_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_billing_period_id_lease_id_unique');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('type')->default('combined')->after('number');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(
                ['billing_period_id', 'lease_id', 'type'],
                'invoices_period_lease_type_unique'
            );
        });
    }
};
