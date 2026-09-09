<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');

            // Colonnes indispensables pour l'inscription avec OTP (avec ajout du rôle)
            $table->string('telephone')->index();
            $table->string('nom')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->default('client');

            $table->string('code', 6);
            $table->dateTime('expiration');
            $table->boolean('statut')->default(false);
            $table->unsignedTinyInteger('tentatives')->default(0);

            // Jeton remis apres verification OTP, exige par /reset-password.
            // Stocke hashe, a usage unique, duree de vie courte.
            $table->string('reset_token')->nullable()->index();
            $table->dateTime('reset_token_expiration')->nullable();
            $table->dateTime('reset_token_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
