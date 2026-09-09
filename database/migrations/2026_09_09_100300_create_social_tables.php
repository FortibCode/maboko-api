<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reseau social (§4.1) : likes, commentaires, stories, abonnements et favoris.
 * Les compteurs presents sur « posts » n'etaient alimentes par rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Une publication peut porter plusieurs photos.
        Schema::create('post_medias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('url');
            $table->string('type')->default('image'); // image, video
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();
        });

        // Polymorphe : s'applique aux publications comme aux commentaires.
        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('likeable');
            $table->timestamps();

            $table->unique(['user_id', 'likeable_type', 'likeable_id'], 'likes_unicite');
        });

        Schema::create('commentaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('commentaires')->cascadeOnDelete();

            $table->text('contenu');
            $table->unsignedInteger('likes_count')->default(0);
            $table->string('statut_moderation')->default('publie');

            $table->timestamps();

            $table->index(['post_id', 'created_at']);
        });

        // Stories : mises en avant des travaux recents, expirent au bout de 24 h.
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artisan_id')->constrained('users')->cascadeOnDelete();
            $table->string('media_url');
            $table->string('type')->default('image');
            $table->text('legende')->nullable();
            $table->unsignedInteger('vues_count')->default(0);
            $table->timestamp('expire_at');
            $table->timestamps();

            $table->index(['artisan_id', 'expire_at']);
        });

        // Abonnements entre utilisateurs : alimentent le fil personnalise.
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('artisan_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['follower_id', 'artisan_id']);
        });

        // « Mes artisans de confiance » du menu profil client.
        Schema::create('favoris', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('artisan_id')->constrained('artisans')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'artisan_id']);
        });

        // Le traitement d'un signalement doit etre tracable (§5.4.2).
        Schema::table('reports', function (Blueprint $table) {
            $table->string('statut')->default('en_attente'); // en_attente, traite, ignore
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_at')->nullable();
            $table->text('decision')->nullable();

            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('traite_par');
            $table->dropColumn(['statut', 'traite_at', 'decision']);
        });

        Schema::dropIfExists('favoris');
        Schema::dropIfExists('follows');
        Schema::dropIfExists('stories');
        Schema::dropIfExists('commentaires');
        Schema::dropIfExists('likes');
        Schema::dropIfExists('post_medias');
    }
};
