<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index sur les chemins de lecture les plus sollicités (§7.2).
 *
 * PostgreSQL n'indexe pas automatiquement les clés étrangères, contrairement
 * à MySQL. Sans ces index, le fil d'actualité et l'historique des courses
 * font un balayage complet dès quelques milliers de lignes — invisible en
 * développement, ruineux en production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Fil personnalisé : publications des artisans suivis, du plus récent.
            $table->index(['artisan_id', 'created_at'], 'posts_auteur_date_index');
            $table->index('created_at', 'posts_date_index');
        });

        Schema::table('courses', function (Blueprint $table) {
            // Historique client et historique chauffeur.
            $table->index(['utilisateur_id', 'created_at'], 'courses_client_date_index');
            $table->index(['chauffeur_id', 'statut'], 'courses_chauffeur_statut_index');
            // Revenus du chauffeur : filtrés par date de clôture.
            $table->index('terminee_at', 'courses_terminee_index');
        });

        Schema::table('demandes_devis', function (Blueprint $table) {
            // Revenus et statistiques de l'artisan.
            $table->index('terminee_at', 'demandes_terminee_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Synthèse financière du back-office, bornée par période.
            $table->index('payee_at', 'transactions_payee_index');
        });

        Schema::table('avis', function (Blueprint $table) {
            $table->index('created_at', 'avis_date_index');
        });

        Schema::table('users', function (Blueprint $table) {
            // Indicateur « artisans actifs » du tableau de bord.
            $table->index('derniere_connexion_at', 'users_derniere_connexion_index');
        });

        Schema::table('commentaires', function (Blueprint $table) {
            $table->index('user_id', 'commentaires_auteur_index');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_auteur_date_index');
            $table->dropIndex('posts_date_index');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex('courses_client_date_index');
            $table->dropIndex('courses_chauffeur_statut_index');
            $table->dropIndex('courses_terminee_index');
        });

        Schema::table('demandes_devis', fn (Blueprint $table) => $table->dropIndex('demandes_terminee_index'));
        Schema::table('transactions', fn (Blueprint $table) => $table->dropIndex('transactions_payee_index'));
        Schema::table('avis', fn (Blueprint $table) => $table->dropIndex('avis_date_index'));
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex('users_derniere_connexion_index'));
        Schema::table('commentaires', fn (Blueprint $table) => $table->dropIndex('commentaires_auteur_index'));
    }
};
