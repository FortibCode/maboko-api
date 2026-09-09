<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('prenom')->nullable();
            $table->string('email')->unique();
            $table->string('telephone')->unique();
            $table->string('password');
            $table->string('role')->default('client')->index();
            $table->boolean('is_verified')->default(false);

            $table->string('statut')->default('actif')->index();
            $table->string('avatar_url')->nullable();
            $table->string('ville')->nullable();
            $table->string('quartier')->nullable();
            $table->timestamp('derniere_connexion_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
