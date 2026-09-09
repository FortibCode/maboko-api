<?php

use App\Http\Controllers\AbonnementController;
use App\Http\Controllers\Admin\ComptesController;
use App\Http\Controllers\Admin\FinancesController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\PieceIdentiteController;
use App\Http\Controllers\Admin\TableauDeBordController as AdminTableauDeBordController;
use App\Http\Controllers\AppareilController;
use App\Http\Controllers\ArtisanController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvisController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ChauffeurController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DemandeDevisController;
use App\Http\Controllers\FavoriController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\MetierController;
use App\Http\Controllers\MissionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PaiementWebhookController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\TableauDeBordController;
use App\Http\Controllers\VerificationIdentiteController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Maboko — version 1
|--------------------------------------------------------------------------
|
| Prefixe complet : /api/v1
|
*/

Route::prefix('v1')->group(function () {

    /*
    | Routes publiques
    |
    | L'inscription passe obligatoirement par la verification OTP :
    | send-register-otp met le formulaire en attente, verify-register-otp
    | cree le compte. Il n'existe volontairement aucune route d'inscription
    | directe, qui contournerait la verification exigee au paragraphe 7.1.
    */
    Route::post('/send-register-otp', [OtpController::class, 'sendRegisterOtp'])
        ->middleware('throttle:otp-envoi');

    Route::post('/verify-register-otp', [OtpController::class, 'verifyAndRegister'])
        ->middleware('throttle:otp-verif');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:connexion');

    // Mot de passe oublie : envoi du code, verification, puis changement
    // du mot de passe contre le jeton a usage unique remis a l'etape 2.
    Route::post('/send-otp', [OtpController::class, 'sendOtp'])
        ->middleware('throttle:otp-envoi');

    Route::post('/verify-otp', [OtpController::class, 'verifyOtp'])
        ->middleware('throttle:otp-verif');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:otp-verif');

    /*
    | Notifications de paiement des opérateurs Mobile Money.
    | Publiques par nature : c'est la signature qui les authentifie.
    */
    Route::post('/webhooks/paiement/{operateur}', PaiementWebhookController::class)
        ->middleware('throttle:120,1');

    /*
    | Routes authentifiees
    */
    Route::middleware('auth:sanctum')->group(function () {

        /*
        | Autorisation des canaux privés Reverb.
        |
        | Laravel place cette route sous le middleware « web » par défaut,
        | ce qui suppose une session. L'application mobile s'authentifie par
        | jeton : elle a donc besoin de sa propre entrée.
        */
        Route::post('/diffusion/auth', function (Request $requete) {
            return Broadcast::auth($requete);
        });

        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'me']);

        /*
        | Tableau de bord — « Mon profil » client (§5.1.10)
        | et « Tableau de bord artisan » (§5.2.1)
        */
        Route::get('/tableau-de-bord', TableauDeBordController::class);

        /*
        | Mes artisans de confiance (§5.1.10)
        */
        Route::get('/favoris', [FavoriController::class, 'index']);
        Route::post('/favoris/{artisan}', [FavoriController::class, 'basculer']);

        /*
        | Referentiel des metiers — ecran « Explorer les metiers » (§5.1.5)
        */
        Route::get('/metiers', [MetierController::class, 'index']);
        Route::get('/metiers/{slug}', [MetierController::class, 'show']);

        /*
        | Artisans — recherche et fiche detaillee (§4.1, §5.1.6)
        |
        | « me » est declare avant « {artisan} », sinon le mot serait
        | interprete comme un identifiant.
        */
        Route::get('/artisans/me', [ArtisanController::class, 'me']);
        Route::get('/artisans', [ArtisanController::class, 'index']);
        Route::get('/artisans/{artisan}', [ArtisanController::class, 'show']);
        Route::get('/artisans/{artisan}/avis', [ArtisanController::class, 'avis']);
        Route::patch('/artisans/{artisan}', [ArtisanController::class, 'update']);

        /*
        | Demandes de devis — cycle de vie complet (§5.1.7, §5.2.2)
        */
        Route::get('/demandes', [DemandeDevisController::class, 'index']);
        Route::post('/demandes', [DemandeDevisController::class, 'store']);
        Route::get('/demandes/{demande}', [DemandeDevisController::class, 'show']);
        Route::post('/demandes/{demande}/accepter', [DemandeDevisController::class, 'accepter']);
        Route::post('/demandes/{demande}/refuser', [DemandeDevisController::class, 'refuser']);
        Route::post('/demandes/{demande}/demarrer', [DemandeDevisController::class, 'demarrer']);
        Route::post('/demandes/{demande}/terminer', [DemandeDevisController::class, 'terminer']);
        Route::post('/demandes/{demande}/annuler', [DemandeDevisController::class, 'annuler']);

        /*
        | Avis (§2.2)
        */
        Route::post('/demandes/{demande}/avis', [AvisController::class, 'store']);
        Route::post('/avis/{avis}/signaler', [AvisController::class, 'signaler']);

        /*
        | Allô Chauffeur — côté client (§5.1.8)
        */
        Route::post('/courses/estimation', [CourseController::class, 'estimer']);
        Route::get('/courses', [CourseController::class, 'index']);
        Route::post('/courses', [CourseController::class, 'store']);
        Route::get('/courses/{course}', [CourseController::class, 'show']);
        Route::post('/courses/{course}/annuler', [CourseController::class, 'annuler']);

        /*
        | Allô Chauffeur — côté chauffeur (§5.3)
        |
        | « propositions » est déclaré avant « {course} », sinon le mot serait
        | interprété comme un identifiant.
        */
        Route::get('/chauffeur', [ChauffeurController::class, 'moi']);
        Route::post('/chauffeur/disponibilite', [ChauffeurController::class, 'basculerDisponibilite']);
        Route::post('/chauffeur/position', [ChauffeurController::class, 'transmettrePosition']);
        Route::get('/chauffeur/revenus', [ChauffeurController::class, 'revenus']);

        Route::get('/chauffeur/propositions', [CourseController::class, 'propositions']);
        Route::post('/courses/{course}/accepter', [CourseController::class, 'accepter']);
        Route::post('/courses/{course}/demarrer', [CourseController::class, 'demarrer']);
        Route::post('/courses/{course}/prise-en-charge', [CourseController::class, 'prendreEnCharge']);
        Route::post('/courses/{course}/terminer', [CourseController::class, 'terminer']);

        /*
        | Table « missions » : remplacée par /demandes, conservée le temps de
        | la reprise de données puis à retirer.
        */
        Route::apiResource('missions', MissionController::class);

        /*
        |------------------------------------------------------------------
        | Back-office administrateur (§5.4)
        |------------------------------------------------------------------
        |
        | Réservé aux comptes d'administration. Chaque action qui modifie un
        | compte ou supprime un contenu est journalisée (table audit_logs).
        */
        Route::middleware('role:admin,super_admin')->prefix('admin')->group(function () {

            Route::get('/tableau-de-bord', AdminTableauDeBordController::class);

            // Gestion des artisans et chauffeurs (§5.4.3)
            Route::get('/artisans', [ComptesController::class, 'artisans']);
            Route::get('/chauffeurs', [ComptesController::class, 'chauffeurs']);
            Route::post('/{type}/{id}/validation', [ComptesController::class, 'valider'])
                ->whereIn('type', ['artisans', 'chauffeurs']);
            Route::post('/utilisateurs/{utilisateur}/suspension', [ComptesController::class, 'basculerSuspension']);
            Route::post('/artisans/{artisan}/badge', [ComptesController::class, 'attribuerBadge']);

            // Modération du contenu (§5.4.2)
            Route::get('/signalements', [ModerationController::class, 'signalements']);
            Route::post('/signalements/{signalement}', [ModerationController::class, 'traiterSignalement']);
            Route::post('/avis/{avis}/masquer', [ModerationController::class, 'masquerAvis']);
            Route::post('/commentaires/{commentaire}/masquer', [ModerationController::class, 'supprimerCommentaire']);

            // Vérification d'identité (§4.5)
            Route::get('/verifications', [ModerationController::class, 'verifications']);
            Route::post('/verifications/{verification}', [ModerationController::class, 'traiterVerification']);

            // Litiges (§3.4)
            Route::get('/litiges', [ModerationController::class, 'litiges']);
            Route::post('/litiges/{litige}', [ModerationController::class, 'traiterLitige']);

            // Abonnements et commissions (§5.4.4)
            Route::get('/finances', [FinancesController::class, 'synthese']);
            Route::get('/finances/export', [FinancesController::class, 'exporter']);
        });

        /*
        | Pièces d'identité : lien signé, valable dix minutes.
        */
        Route::get('/admin/pieces/{verification}/{face}', PieceIdentiteController::class)
            ->name('admin.piece');

        /*
        | Abonnements et paiements (§4.5, §5.2.4)
        */
        Route::get('/plans', [AbonnementController::class, 'plans']);
        Route::get('/abonnement', [AbonnementController::class, 'actuel']);
        Route::post('/abonnement', [AbonnementController::class, 'souscrire']);
        Route::get('/transactions', [AbonnementController::class, 'transactions']);

        /*
        | Vérification d'identité, préalable au badge « Profil vérifié » (§4.5)
        */
        Route::get('/verification-identite', [VerificationIdentiteController::class, 'afficher']);
        Route::post('/verification-identite', [VerificationIdentiteController::class, 'deposer']);
        Route::get('/transactions/{reference}', [AbonnementController::class, 'suivreTransaction']);

        /*
        | Messagerie (§5.1.9)
        */
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/support', [ConversationController::class, 'support']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'envoyer']);
        Route::post('/conversations/{conversation}/lu', [ConversationController::class, 'marquerLu']);

        /*
        | Fil d'actualité (§5.1.4)
        */
        Route::get('/posts', [PostController::class, 'index']);
        Route::post('/posts', [PostController::class, 'store']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);

        Route::post('/posts/{post}/like', [InteractionController::class, 'basculerLike']);
        Route::get('/posts/{post}/commentaires', [InteractionController::class, 'commentaires']);
        Route::post('/posts/{post}/commentaires', [InteractionController::class, 'commenter']);
        Route::delete('/commentaires/{commentaire}', [InteractionController::class, 'supprimerCommentaire']);

        Route::get('/stories', [StoryController::class, 'index']);
        Route::post('/stories', [StoryController::class, 'store']);

        // Suivre un artisan alimente le fil personnalisé.
        Route::post('/suivre/{artisan}', [InteractionController::class, 'basculerAbonnement']);

        Route::apiResource('reports', ReportController::class)->only(['index', 'store']);

        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::patch('/bookings/{id}/status', [BookingController::class, 'updateStatus']);

        /*
        | Notifications et appareils (§6.2)
        */
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/lu', [NotificationController::class, 'marquerLue']);
        Route::post('/notifications/lu', [NotificationController::class, 'toutMarquerLu']);

        Route::post('/appareils', [AppareilController::class, 'enregistrer']);
        Route::delete('/appareils', [AppareilController::class, 'retirer']);
    });
});
