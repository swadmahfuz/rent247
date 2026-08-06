<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The wider unique index is added before the old one is dropped, since MySQL
        // relies on it to back the billing_period_id foreign key.
        Schema::table('billing_period_documents', function (Blueprint $table) {
            $table->foreignId('meter_id')->nullable()->after('unit_id')->constrained()->nullOnDelete();
            $table->unique(['billing_period_id', 'kind', 'unit_id', 'meter_id'], 'period_docs_meter_scope');
        });

        Schema::table('billing_period_documents', function (Blueprint $table) {
            $table->dropUnique('period_docs_unique_scope');
        });
    }

    public function down(): void
    {
        Schema::table('billing_period_documents', function (Blueprint $table) {
            $table->unique(['billing_period_id', 'kind', 'unit_id'], 'period_docs_unique_scope');
        });

        Schema::table('billing_period_documents', function (Blueprint $table) {
            $table->dropUnique('period_docs_meter_scope');
            $table->dropConstrainedForeignId('meter_id');
        });
    }
};
