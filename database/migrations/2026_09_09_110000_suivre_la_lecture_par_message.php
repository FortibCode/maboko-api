<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La lecture d'une conversation était suivie par un horodatage.
 *
 * Deux messages écrits dans la même seconde — un envoi suivi d'une réponse
 * immédiate — partagent le même horodatage : la réponse était alors comptée
 * comme déjà lue, ou bien le message de l'expéditeur restait marqué non lu.
 * Aucun réglage de la comparaison ne pouvait satisfaire les deux cas.
 *
 * L'identifiant du dernier message lu est monotone : il tranche sans ambiguïté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('dernier_message_lu_id')->nullable();
            $table->dropColumn('lu_jusqu_a');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->timestamp('lu_jusqu_a')->nullable();
            $table->dropColumn('dernier_message_lu_id');
        });
    }
};
