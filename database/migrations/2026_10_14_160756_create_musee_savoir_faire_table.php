<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table du musée du savoir-faire (§5.1.7).
     *
     * Le musée est alimenté automatiquement par les artisans les mieux
     * notés. Un administrateur valide ou ignore les candidats proposés :
     * cela évite qu'un compte récent avec cinq avis gonflés s'affiche à
     * côté des meilleurs.
     *
     * Un artisan ne peut avoir qu'une seule ligne : la colonne artisan_id
     * est unique. S'il est retiré puis re-proposé, la ligne est réutilisée.
     */
    public function up(): void
    {
        Schema::create('musee_savoir_faire', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artisan_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            // candidat   : proposé par le système, en attente de décision
            // valide     : validé par l'admin, visible sur le mobile
            // ignore     : refusé pour 30 jours
            // banni      : refusé définitivement
            $table->enum('statut', ['candidat', 'valide', 'ignore', 'banni'])
                ->default('candidat');

            // Traces de la décision (audit).
            $table->foreignId('valide_par')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('valide_at')->nullable();

            // Tant que cette date est dans le futur, un artisan « ignoré »
            // n'est pas reproposé. C'est ce qui évite de harceler l'admin.
            $table->timestamp('ignore_jusqua')->nullable();

            // Ordre d'affichage manuel (facultatif, prioritaire sur la note).
            $table->integer('ordre')->default(0);

            $table->timestamps();

            // Index pour la requête principale : les validés, triés par note
            // puis par ordre manuel.
            $table->index(['statut', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('musee_savoir_faire');
    }
};
