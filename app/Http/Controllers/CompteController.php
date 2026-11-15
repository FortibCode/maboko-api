<?php

namespace App\Http\Controllers;

use App\Exceptions\OperationCompteRefusee;
use App\Models\Artisan;
use App\Models\Notification;
use App\Models\User;
use App\Services\MultiComptes;
use App\Services\OtpService;
use App\Services\SuppressionCompte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Multi-comptes artisan, sur le modèle d'Instagram.
 *
 * Chaque compte visé par un identifiant passe par ComptePolicy : un compte
 * d'une autre personne répond comme un compte inexistant. Les règles métier
 * vivent dans le service MultiComptes.
 */
class CompteController extends Controller
{
    public function __construct(private MultiComptes $comptes) {}

    /** Comptes de la personne connectée : actifs pour le sélecteur, archivés à part. */
    public function index(Request $request): JsonResponse
    {
        $utilisateur = $request->user();

        $tous = $utilisateur->famille()
            ->with('artisan:id,utilisateur_id,specialite')
            // Compte principal en tête, puis les autres par ordre de création.
            ->orderByRaw('compte_principal_id IS NOT NULL')
            ->orderBy('id')
            ->get();

        $nonLues = Notification::query()
            ->whereIn('artisan_id', $tous->pluck('id'))
            ->whereNull('lu_at')
            ->selectRaw('artisan_id, count(*) as total')
            ->groupBy('artisan_id')
            ->pluck('total', 'artisan_id');

        [$archives, $actifs] = $tous->partition(fn (User $c) => $c->estArchive());

        return response()->json([
            'data' => $this->presenter($actifs, $utilisateur, $nonLues),
            'archives' => $this->presenter($archives, $utilisateur, $nonLues),
            'peutAjouter' => $utilisateur->titulaire()->estArtisan()
                && $actifs->count() < User::maximumComptes(),
            'maximum' => User::maximumComptes(),
        ]);
    }

    /** Crée un compte artisan rattaché, puis ouvre sa session. */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'sometimes|nullable|string|max:255',
            'specialite' => 'sometimes|nullable|string|max:255',
            'bio' => 'sometimes|nullable|string|max:2000',
            'metiers' => 'sometimes|array|max:5',
            'metiers.*' => 'string|exists:metiers,slug',
            'photo' => 'sometimes|nullable|string',
        ]);

        try {
            $profil = $this->comptes->creerProfil($request->user(), $donnees);
        } catch (OperationCompteRefusee $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // Photo illisible ou trop lourde (MediaService).
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['photo' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(
            ['message' => 'Compte créé.'] + $this->comptes->ouvrirSession($request->user(), $profil),
            201,
        );
    }

    /** Passe sur un autre compte de la même personne, sans mot de passe. */
    public function basculer(Request $request, int $compte): JsonResponse
    {
        $cible = $this->compteDeLaFamille($request, $compte);

        return response()->json(
            ['message' => 'Compte changé.'] + $this->comptes->ouvrirSession($request->user(), $cible),
        );
    }

    /** Fait d'un compte rattaché le compte principal. Mot de passe exigé. */
    public function definirPrincipal(Request $request, int $compte): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        $cible = $this->compteDeLaFamille($request, $compte);
        $this->exigerMotDePasse($request);

        $this->comptes->definirPrincipal($cible);

        return response()->json([
            'message' => 'Ce compte est désormais votre compte principal.',
        ]);
    }

    /**
     * Retire un compte de la liste sans rien supprimer. Si c'est le compte
     * ouvert, la session passe sur le compte principal.
     */
    public function archiver(Request $request, int $compte): JsonResponse
    {
        $request->validate(['confirmation' => 'required|accepted'], [
            'confirmation.accepted' => 'Confirmez le retrait de ce compte.',
        ]);

        $cible = $this->compteDeLaFamille($request, $compte);
        $estLeCompteOuvert = $cible->id === $request->user()->id;
        $principal = $cible->titulaire();

        $this->comptes->archiver($cible);

        return response()->json(['message' => 'Compte retiré. Ses données sont conservées.']
            + ($estLeCompteOuvert ? $this->sessionDeRepli($principal) : []));
    }

    public function reactiver(Request $request, int $compte): JsonResponse
    {
        $this->comptes->reactiver($this->compteDeLaFamille($request, $compte));

        return response()->json(['message' => 'Compte réactivé.']);
    }

    /**
     * Suppression définitive d'un compte rattaché, parcours distinct de
     * l'archivage : mot de passe et confirmation exigés, données personnelles
     * anonymisées, pièces comptables conservées (SuppressionCompte).
     */
    public function destroy(Request $request, int $compte, SuppressionCompte $suppression): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
            'confirmation' => 'required|accepted',
        ], [
            'confirmation.accepted' => 'Confirmez la suppression définitive de ce compte.',
        ]);

        $cible = $this->compteDeLaFamille($request, $compte);
        $this->exigerMotDePasse($request);

        if (! $cible->estProfilRattache()) {
            return response()->json([
                'message' => 'Le compte principal se supprime depuis « Supprimer mon compte », une fois seul.',
            ], 422);
        }

        $estLeCompteOuvert = $cible->id === $request->user()->id;
        $principal = $cible->titulaire();

        $suppression->supprimer($cible);

        return response()->json(['message' => 'Compte supprimé définitivement.']
            + ($estLeCompteOuvert ? $this->sessionDeRepli($principal) : []));
    }

    /** Association d'un compte existant, étape 1 : identifiants puis code SMS. */
    public function demanderAssociation(Request $request, OtpService $otp): JsonResponse
    {
        $donnees = $request->validate([
            'identifiant' => 'required|string|max:255',
            'password' => 'required|string',
        ]);

        ['compte' => $cible, 'code' => $code] = $this->comptes->demanderAssociation(
            $request->user(),
            $donnees['identifiant'],
            $donnees['password'],
        );

        $reponse = [
            'message' => 'Un code a été envoyé au numéro de ce compte.',
            'compte' => $this->presenterUn($cible->load('artisan:id,utilisateur_id,specialite')) + [
                'telephoneMasque' => $this->masquer($cible->telephone),
            ],
        ];

        if ($otp->codeExposable($cible->telephone, OtpService::CONTEXTE_ASSOCIATION)) {
            $reponse['debug_code'] = $code;
        }

        return response()->json($reponse);
    }

    /** Étape 2 : code SMS et confirmation explicite. */
    public function confirmerAssociation(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'compte_id' => 'required|integer',
            'code' => 'required|string|size:6',
            'confirmation' => 'required|accepted',
        ], [
            'confirmation.accepted' => 'Confirmez le rattachement de ce compte.',
        ]);

        $compte = $this->comptes->confirmerAssociation(
            $request->user(),
            (int) $donnees['compte_id'],
            $donnees['code'],
        );

        return response()->json([
            'message' => 'Compte rattaché. Il se connecte désormais par votre compte principal.',
            'compte' => $this->presenterUn($compte->load('artisan:id,utilisateur_id,specialite')),
        ]);
    }

    // ------------------------------------------------------------------

    /** Résout un compte de la famille, ou 404 : jamais d'indice sur les autres. */
    private function compteDeLaFamille(Request $request, int $id): User
    {
        $compte = User::find($id);

        abort_unless($compte && $request->user()->can('gerer', $compte), 404, 'Compte introuvable.');

        return $compte;
    }

    /** Les opérations sensibles redemandent le mot de passe de la personne. */
    private function exigerMotDePasse(Request $request): void
    {
        if (! Hash::check((string) $request->input('password'), $request->user()->titulaire()->password)) {
            abort(response()->json(['message' => 'Mot de passe incorrect.'], 422));
        }
    }

    /** Session sur le compte principal, quand le compte ouvert vient de partir. */
    private function sessionDeRepli(User $principal): array
    {
        return [
            'token' => $principal->createToken('auth_token')->plainTextToken,
            'user' => $principal->fresh(),
        ];
    }

    private function presenter(Collection $comptes, User $courant, Collection $nonLues): array
    {
        return $comptes->map(fn (User $c) => $this->presenterUn($c) + [
            'estActif' => $c->id === $courant->id,
            'notificationsNonLues' => (int) ($nonLues[$c->id] ?? 0),
        ])->values()->all();
    }

    private function presenterUn(User $compte): array
    {
        /** @var Artisan|null $fiche */
        $fiche = $compte->artisan;

        return [
            'id' => $compte->id,
            'nom' => $compte->nom,
            'prenom' => $compte->prenom,
            'avatarUrl' => $compte->avatar_url,
            'role' => $compte->role,
            'specialite' => $fiche?->specialite,
            'estPrincipal' => ! $compte->estProfilRattache(),
            'archive' => $compte->estArchive(),
            'suspendu' => $compte->statut === User::STATUT_SUSPENDU,
        ];
    }

    private function masquer(string $telephone): string
    {
        return substr($telephone, 0, 6).str_repeat('•', max(0, strlen($telephone) - 8)).substr($telephone, -2);
    }
}
