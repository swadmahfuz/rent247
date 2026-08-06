<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_property_id')->nullable()->after('remember_token');
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('owner_display_name')->nullable();
            $table->string('currency', 10)->default('BDT');
            $table->string('timezone')->default('Asia/Dhaka');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_property_id')->references('id')->on('properties')->nullOnDelete();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('type'); // residential, commercial, owner_occupied, garage
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->decimal('rent_amount', 14, 2)->default(0);
            $table->string('rent_label')->default('Office Rent');
            $table->string('fee_tier')->nullable(); // full, half, none — for DOHS-like charges
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('kind'); // common, unit
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('charge_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('label');
            $table->string('category')->default('fixed'); // rent, utility, fixed, arrears, other
            $table->decimal('default_amount', 14, 2)->nullable();
            $table->boolean('is_recurring')->default(true);
            $table->boolean('on_invoice')->default(true);
            $table->integer('period_offset_months')->default(0); // -1 = prior month for utilities
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['property_id', 'code']);
        });

        Schema::create('allocation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_type_id')->constrained()->cascadeOnDelete();
            $table->string('strategy'); // equal_units, per_lease, meter_to_unit, water_residential_commercial, fixed_amount, fee_tier, none
            $table->json('params')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('lease_charge_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount_override', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['lease_id', 'charge_type_id']);
        });

        Schema::create('billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('bill_date')->nullable();
            $table->string('status')->default('draft'); // draft, finalized
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'year', 'month']);
        });

        Schema::create('period_meter_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meter_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2)->default(0);
            $table->date('service_period')->nullable();
            $table->timestamps();
            $table->unique(['billing_period_id', 'meter_id']);
        });

        Schema::create('period_charge_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('units', 14, 4)->nullable(); // e.g. water total units
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('number')->nullable();
            $table->string('status')->default('draft'); // draft, issued, paid, partial, void
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
            $table->unique(['billing_period_id', 'lease_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('period_label')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->string('method')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('to_email');
            $table->string('status'); // sent, failed
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('period_charge_inputs');
        Schema::dropIfExists('period_meter_inputs');
        Schema::dropIfExists('billing_periods');
        Schema::dropIfExists('lease_charge_assignments');
        Schema::dropIfExists('allocation_rules');
        Schema::dropIfExists('charge_types');
        Schema::dropIfExists('meters');
        Schema::dropIfExists('leases');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('units');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_property_id']);
            $table->dropColumn('current_property_id');
        });
        Schema::dropIfExists('properties');
    }
};
