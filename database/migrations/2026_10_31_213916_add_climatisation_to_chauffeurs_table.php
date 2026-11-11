<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chauffeurs', function (Blueprint $table) {
            if (! Schema::hasColumn('chauffeurs', 'est_climatise')) {
                $table->boolean('est_climatise')->default(false)->after('type_vehicule');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chauffeurs', function (Blueprint $table) {
            if (Schema::hasColumn('chauffeurs', 'est_climatise')) {
                $table->dropColumn('est_climatise');
            }
        });
    }
};

