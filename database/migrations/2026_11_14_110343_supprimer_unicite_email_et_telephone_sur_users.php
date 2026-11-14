<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // On supprime les contraintes d'unicité pour permettre les doublons (multi-comptes artisans)
            $table->dropUnique(['email']);
            $table->dropUnique(['telephone']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // En cas d'annulation, on remet les contraintes
            $table->unique('email');
            $table->unique('telephone');
        });
    }
};