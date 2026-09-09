<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Journal des actions d'administration (§3.4).
 *
 * Suspendre un compte, supprimer une publication ou valider une identité sont
 * des décisions opposables : elles doivent laisser une trace nominative et
 * horodatée, avec l'état avant et après.
 */
class JournalAdministration
{
    public function __construct(private Request $requete) {}

    /**
     * @param  array<string, mixed>  $avant
     * @param  array<string, mixed>  $apres
     */
    public function enregistrer(string $action, ?Model $cible = null, array $avant = [], array $apres = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $this->requete->user()?->id,
            'action' => $action,
            'cible_type' => $cible === null ? null : $cible::class,
            'cible_id' => $cible?->getKey(),
            'avant' => $avant ?: null,
            'apres' => $apres ?: null,
            'adresse_ip' => $this->requete->ip(),
        ]);
    }
}
