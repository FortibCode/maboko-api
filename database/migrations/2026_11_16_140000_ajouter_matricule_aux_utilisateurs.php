<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matricule Maboko, affiché sur la carte du profil : MBK-ART-00042.
 *
 * Les comptes existants reçoivent le leur ici ; les suivants l'obtiennent à
 * leur création (User::booted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('matricule', 32)->nullable()->unique()->after('id');
        });

        DB::table('users')->select(['id', 'role'])->orderBy('id')->chunkById(500, function ($comptes) {
            foreach ($comptes as $compte) {
                DB::table('users')->where('id', $compte->id)->update([
                    'matricule' => User::matriculePour($compte->id, $compte->role),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['matricule']);
            $table->dropColumn('matricule');
        });
    }
};
