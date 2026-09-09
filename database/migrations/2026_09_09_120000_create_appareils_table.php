<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jetons de notification push (§6.2 : Firebase).
 *
 * Un même compte peut être connecté sur plusieurs appareils ; chacun a son
 * propre jeton, et un jeton révoqué par Firebase doit pouvoir être retiré
 * sans toucher aux autres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appareils', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('jeton_push')->unique();
            $table->string('plateforme')->default('android'); // android, ios, web
            $table->string('modele')->nullable();
            $table->timestamp('derniere_activite_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appareils');
    }
};
