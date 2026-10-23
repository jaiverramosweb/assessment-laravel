<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access level of each admin: 'full' (everything, the default for existing admins) or 'limited'
 * (only the permissions listed in `permissions`, JSON). See App\Auth\AdminAccess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $t) {
            $t->string('access', 20)->default('full');
            $t->text('permissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $t) {
            $t->dropColumn(['access', 'permissions']);
        });
    }
};
