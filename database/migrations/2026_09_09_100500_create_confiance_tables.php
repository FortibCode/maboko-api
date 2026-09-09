<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Le socle de confiance » (§4.5) : badges attribues, plans d'abonnement,
 * paiements Mobile Money, commissions et verification d'identite.
 *
 * La table « badges » existait, mais sans pivot : aucun badge n'etait
 * attribuable a qui que ce soit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique();
            $table->boolean('automatique')->default(true);
            // Conditions d'attribution : nb de missions, note minimale, anciennete.
            $table->json('regle_attribution')->nullable();
            $table->unsignedSmallInteger('poids_classement')->default(0);
        });

        Schema::create('artisan_badge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artisan_id')->constrained('artisans')->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained('badges')->cascadeOnDelete();
            $table->timestamp('obtenu_at');
            $table->foreignId('attribue_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['artisan_id', 'badge_id']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // gratuit, pro, premium, entreprise
            $table->string('nom');
            $table->text('description')->nullable();
            $table->decimal('prix_mensuel', 10, 2)->default(0);
            $table->decimal('prix_annuel', 10, 2)->default(0);
            $table->json('avantages')->nullable();
            // Multiplicateur applique au score de recherche (§4.5).
            $table->decimal('boost_classement', 4, 2)->default(1);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('abonnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artisan_id')->constrained('artisans')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();

            $table->string('periodicite')->default('mensuel'); // mensuel, annuel
            $table->date('debut');
            $table->date('fin');
            $table->string('statut')->default('actif'); // actif, expire, annule, impaye
            $table->boolean('renouvellement_auto')->default(true);
            $table->timestamp('derniere_relance_at')->nullable();

            $table->timestamps();

            $table->index(['statut', 'fin']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Objet paye : abonnement, demande de devis ou course.
            $table->nullableMorphs('payable');

            $table->decimal('montant', 12, 2);
            $table->string('devise', 3)->default('XAF');
            $table->string('operateur'); // airtel, mtn, carte
            $table->string('reference_interne')->unique();
            $table->string('reference_externe')->nullable()->index();

            // initiee, en_attente, reussie, echouee, remboursee
            $table->string('statut')->default('initiee');
            $table->text('motif_echec')->nullable();

            // Reponse brute de l'operateur, indispensable pour la reconciliation.
            $table->json('payload')->nullable();

            $table->timestamp('payee_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'statut']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('type'); // mission, course, abonnement
            $table->decimal('taux', 5, 2);
            $table->decimal('montant', 12, 2);
            $table->timestamps();

            $table->index('type');
        });

        Schema::create('verifications_identite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('type_piece'); // cni, passeport, carte_consulaire
            $table->string('numero_piece')->nullable();
            // Chemins chiffres : ces documents ne sont jamais servis publiquement.
            $table->string('chemin_recto');
            $table->string('chemin_verso')->nullable();
            $table->string('chemin_selfie')->nullable();

            $table->string('statut')->default('en_attente'); // en_attente, valide, rejete
            $table->foreignId('verifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verifie_at')->nullable();
            $table->text('motif_rejet')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications_identite');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('abonnements');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('artisan_badge');

        Schema::table('badges', function (Blueprint $table) {
            $table->dropColumn(['slug', 'automatique', 'regle_attribution', 'poids_classement']);
        });
    }
};
