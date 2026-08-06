<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('portal_token', 64)->nullable()->unique()->after('notes');
            $table->boolean('portal_enabled')->default(false)->after('portal_token');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropColumn(['portal_token', 'portal_enabled']);
        });
    }
};
