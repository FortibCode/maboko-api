<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refus d'une course par un chauffeur (§5.3.1).
 *
 * Le cahier de charges offre au chauffeur « un choix simple entre Accepter et
 * Refuser ». Seul Accepter existait : une course qui ne l'interessait pas
 * revenait dans sa liste a chaque relecture, jusqu'a expiration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refus_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('chauffeur_id')->constrained('chauffeurs')->cascadeOnDelete();
            $table->timestamps();

            // Un chauffeur ne refuse une course qu'une fois.
            $table->unique(['course_id', 'chauffeur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refus_courses');
    }
};
