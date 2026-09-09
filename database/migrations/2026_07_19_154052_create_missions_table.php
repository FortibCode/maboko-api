<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            // Lien vers la table artisans
            $table->foreignId('artisan_id')->constrained('artisans')->onDelete('cascade');

            $table->string('titre');
            $table->text('description')->nullable();
            $table->decimal('prix_estime', 10, 2);
            $table->string('statut')->default('en_attente');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
