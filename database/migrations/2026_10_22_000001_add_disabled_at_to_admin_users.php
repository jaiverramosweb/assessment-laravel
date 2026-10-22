<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deactivated admins (ms timestamp) cannot log in. Null = active. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $t) {
            $t->bigInteger('disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $t) {
            $t->dropColumn('disabled_at');
        });
    }
};
