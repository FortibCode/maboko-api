<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qui a vu quelle story. Le compteur stories.vues_count ne suffit pas :
     * l'artisan veut la liste des personnes, et une même personne ne doit
     * compter qu'une fois.
     */
    public function up(): void
    {
        Schema::create('story_vues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['story_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_vues');
    }
};
