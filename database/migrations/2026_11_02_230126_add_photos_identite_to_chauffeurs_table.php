<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chauffeurs', function (Blueprint $table) {
            if (!Schema::hasColumn('chauffeurs', 'photo_recto')) {
                $table->string('photo_recto')->nullable();
            }
            if (!Schema::hasColumn('chauffeurs', 'photo_verso')) {
                $table->string('photo_verso')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chauffeurs', function (Blueprint $table) {
            $table->dropColumn(['photo_recto', 'photo_verso']);
        });
    }
};