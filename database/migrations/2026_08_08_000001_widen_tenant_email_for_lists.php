<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE tenants MODIFY email VARCHAR(500) NULL');
            DB::statement('ALTER TABLE mail_logs MODIFY to_email VARCHAR(500) NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE tenants MODIFY email VARCHAR(255) NULL');
            DB::statement('ALTER TABLE mail_logs MODIFY to_email VARCHAR(255) NOT NULL');
        }
    }
};
