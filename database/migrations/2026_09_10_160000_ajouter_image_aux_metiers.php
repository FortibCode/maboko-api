<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo illustrant un metier.
 *
 * La colonne « icone » ne porte qu'un identifiant d'icone dessinee. Les
 * ecrans de l'application montraient donc un pictogramme la ou une photo de
 * chantier ou d'atelier dit bien mieux ce que le metier recouvre. La photo
 * se depose depuis le back-office, avec l'icone en repli tant qu'il n'y en
 * a pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metiers', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('icone');
        });
    }

    public function down(): void
    {
        Schema::table('metiers', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
