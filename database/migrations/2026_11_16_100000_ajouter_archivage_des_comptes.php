<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un compte rattaché peut être retiré de la liste des comptes de la personne
 * sans rien perdre : il est archivé, ses commandes, avis et messages restent
 * en place, et il peut être réactivé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('archive_at')->nullable()->after('compte_principal_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('archive_at');
        });
    }
};
