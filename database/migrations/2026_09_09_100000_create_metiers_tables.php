<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referentiel des metiers (§4.1). La « vingtaine de metiers » annoncee dans le
 * cahier de charges n'existait nulle part : la specialite etait une simple
 * chaine libre sur la fiche artisan, ce qui rend tout filtrage impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metiers', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('slug')->unique();
            $table->string('icone')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->index(['actif', 'ordre']);
        });

        // Un artisan peut exercer plusieurs metiers (maçon et carreleur, par exemple).
        Schema::create('artisan_metier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artisan_id')->constrained('artisans')->cascadeOnDelete();
            $table->foreignId('metier_id')->constrained('metiers')->cascadeOnDelete();
            $table->string('niveau')->default('confirme'); // debutant, confirme, expert
            $table->boolean('principal')->default(false);
            $table->timestamps();

            $table->unique(['artisan_id', 'metier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisan_metier');
        Schema::dropIfExists('metiers');
    }
};
