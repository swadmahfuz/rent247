<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leases', 'invoice_mode')) {
            Schema::table('leases', function (Blueprint $table) {
                $table->string('invoice_mode')->default('combined')->after('rent_label');
            });
        }

        // Run these as separate ALTER statements: MySQL must create the
        // supporting foreign-key index before the old unique index is dropped.
        if (! $this->indexExists('invoices', 'invoices_billing_period_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index('billing_period_id', 'invoices_billing_period_index');
            });
        }

        if (! $this->indexExists('invoices', 'invoices_lease_index')
            && ! $this->indexExists('invoices', 'invoices_lease_id_foreign')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index('lease_id', 'invoices_lease_index');
            });
        }

        if ($this->indexExists('invoices', 'invoices_billing_period_id_lease_id_unique')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropUnique(['billing_period_id', 'lease_id']);
            });
        }

        if (! Schema::hasColumn('invoices', 'type')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('type')->default('combined')->after('number');
            });
        }

        if (! $this->indexExists('invoices', 'invoices_period_lease_type_unique')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unique(
                    ['billing_period_id', 'lease_id', 'type'],
                    'invoices_period_lease_type_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_period_lease_type_unique');
            $table->dropColumn('type');
            $table->unique(['billing_period_id', 'lease_id']);
        });

        if ($this->indexExists('invoices', 'invoices_billing_period_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex('invoices_billing_period_index');
            });
        }

        if ($this->indexExists('invoices', 'invoices_lease_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex('invoices_lease_index');
            });
        }

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('invoice_mode');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($row) => $row->Key_name === $index);
    }
};
