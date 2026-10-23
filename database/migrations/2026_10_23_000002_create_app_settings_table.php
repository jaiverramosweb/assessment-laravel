<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings edited from the admin panel ("Configuración"), as key => value. Secrets (AI API
 * keys) are stored encrypted with SecretBox. See App\Services\Ai\AiSettings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->text('value')->nullable();
            $t->bigInteger('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
