<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->boolean('attach_water_bill')->default(false)->after('invoice_mode');
            $table->boolean('attach_electricity_bill')->default(false)->after('attach_water_bill');
        });

        Schema::create('billing_period_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_period_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // water | electricity
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->unique(['billing_period_id', 'kind', 'unit_id'], 'period_docs_unique_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_period_documents');

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['attach_water_bill', 'attach_electricity_bill']);
        });
    }
};
