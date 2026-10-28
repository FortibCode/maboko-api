<?php

use App\Http\Controllers\AbonnementController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ComptesController;
use App\Http\Controllers\Admin\FinancesController;
use App\Http\Controllers\Admin\MetierController as AdminMetierController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\NotificationBroadcastController;
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
use App\Http\Controllers\MuseeController;
use App\Http\Controllers\MoyenPaiementController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PaiementWebhookController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PubliciteController;
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
    */
    Route::post('/send-register-otp', [OtpController::class, 'sendRegisterOtp'])
        ->middleware('throttle:otp-envoi');

    Route::post('/verify-register-otp', [OtpController::class, 'verifyAndRegister'])
        ->middleware('throttle:otp-verif');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:connexion');

    Route::post('/login/google', [AuthController::class, 'loginGoogle'])
        ->middleware('throttle:connexion');

    Route::post('/send-otp', [OtpController::class, 'sendOtp'])
        ->middleware('throttle:otp-envoi');

    Route::post('/verify-otp', [OtpController::class, 'verifyOtp'])
        ->middleware('throttle:otp-verif');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:otp-verif');

    Route::post('/webhooks/paiement/{operateur}', PaiementWebhookController::class)
        ->middleware('throttle:120,1');

    /*
    | Routes authentifiees
    */
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/diffusion/auth', function (Request $requete) {
            return Broadcast::auth($requete);
        });

        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'me']);
        Route::patch('/compte', [AuthController::class, 'modifierProfil']);
        Route::post('/compte/avatar', [AuthController::class, 'enregistrerAvatar']);
        Route::delete('/compte/avatar', [AuthController::class, 'supprimerAvatar']);
        Route::delete('/compte', [AuthController::class, 'supprimerCompte']);

        Route::get('/tableau-de-bord', TableauDeBordController::class);

        Route::get('/favoris', [FavoriController::class, 'index']);
        Route::post('/favoris/{artisan}', [FavoriController::class, 'basculer']);

        Route::get('/metiers', [MetierController::class, 'index']);
        Route::get('/metiers/{slug}', [MetierController::class, 'show']);

        // Artisans
        Route::get('/artisans/me', [ArtisanController::class, 'me']);
        Route::post('/artisans', [ArtisanController::class, 'store']);
        Route::get('/artisans', [ArtisanController::class, 'index']);
        Route::get('/artisans/{artisan}', [ArtisanController::class, 'show']);
        Route::get('/artisans/{artisan}/avis', [ArtisanController::class, 'avis']);
        Route::patch('/artisans/{artisan}', [ArtisanController::class, 'update']);

        // Musée
        Route::get('/musee', [MuseeController::class, 'index']);

        // Publicités
        Route::get('/publicite/active', [PubliciteController::class, 'active']);
        Route::post('/publicite/{publicite}/clic', [PubliciteController::class, 'clic']);

        // Demandes de devis
        Route::get('/demandes', [DemandeDevisController::class, 'index']);
        Route::post('/demandes', [DemandeDevisController::class, 'store']);
        Route::get('/demandes/{demande}', [DemandeDevisController::class, 'show']);
        Route::post('/demandes/{demande}/accepter', [DemandeDevisController::class, 'accepter']);
        Route::post('/demandes/{demande}/refuser', [DemandeDevisController::class, 'refuser']);
        Route::post('/demandes/{demande}/demarrer', [DemandeDevisController::class, 'demarrer']);
        Route::post('/demandes/{demande}/terminer', [DemandeDevisController::class, 'terminer']);
        Route::post('/demandes/{demande}/annuler', [DemandeDevisController::class, 'annuler']);
        Route::post('/demandes/{demande}/payer', [DemandeDevisController::class, 'payer']);

        Route::post('/demandes/{demande}/avis', [AvisController::class, 'store']);
        Route::post('/avis/{avis}/signaler', [AvisController::class, 'signaler']);

        // Allô Chauffeur
        Route::post('/courses/estimation', [CourseController::class, 'estimer']);
        Route::get('/courses', [CourseController::class, 'index']);
        Route::post('/courses', [CourseController::class, 'store']);
        Route::get('/courses/{course}', [CourseController::class, 'show']);
        Route::post('/courses/{course}/annuler', [CourseController::class, 'annuler']);

        Route::post('/chauffeur', [ChauffeurController::class, 'store']);
        Route::get('/chauffeur', [ChauffeurController::class, 'moi']);
        Route::post('/chauffeur', [ChauffeurController::class, 'enregistrerFiche']);
        Route::post('/chauffeur/disponibilite', [ChauffeurController::class, 'basculerDisponibilite']);
        Route::post('/chauffeur/position', [ChauffeurController::class, 'transmettrePosition']);
        Route::get('/chauffeur/revenus', [ChauffeurController::class, 'revenus']);

        Route::get('/chauffeur/propositions', [CourseController::class, 'propositions']);
        Route::post('/courses/{course}/accepter', [CourseController::class, 'accepter']);
        Route::post('/courses/{course}/refuser', [CourseController::class, 'refuser']);
        Route::post('/courses/{course}/demarrer', [CourseController::class, 'demarrer']);
        Route::post('/courses/{course}/prise-en-charge', [CourseController::class, 'prendreEnCharge']);
        Route::post('/courses/{course}/terminer', [CourseController::class, 'terminer']);

        Route::apiResource('missions', MissionController::class);

        /*
        |------------------------------------------------------------------
        | Back-office administrateur (§5.4)
        |------------------------------------------------------------------
        */
        Route::middleware('role:admin,super_admin')->prefix('admin')->group(function () {

            Route::get('/tableau-de-bord', AdminTableauDeBordController::class);

            // Gestion des comptes
            Route::get('/utilisateurs', [ComptesController::class, 'utilisateurs']);
            Route::get('/artisans', [ComptesController::class, 'artisans']);
            Route::get('/chauffeurs', [ComptesController::class, 'chauffeurs']);
            Route::post('/{type}/{id}/validation', [ComptesController::class, 'valider'])->whereIn('type', ['artisans', 'chauffeurs']);
            Route::post('/utilisateurs/{utilisateur}/suspension', [ComptesController::class, 'basculerSuspension']);
            Route::delete('/utilisateurs/{utilisateur}', [ComptesController::class, 'supprimerUtilisateur']);
            Route::post('/artisans/{artisan}/badge', [ComptesController::class, 'attribuerBadge']);

            // Modération
            Route::get('/signalements', [ModerationController::class, 'signalements']);
            Route::post('/signalements/{signalement}', [ModerationController::class, 'traiterSignalement']);
            Route::post('/avis/{avis}/masquer', [ModerationController::class, 'masquerAvis']);
            Route::post('/commentaires/{commentaire}/masquer', [ModerationController::class, 'supprimerCommentaire']);

            // Vérification d'identité
            Route::get('/verifications', [ModerationController::class, 'verifications']);
            Route::post('/verifications/{verification}', [ModerationController::class, 'traiterVerification']);

            // Litiges
            Route::get('/litiges', [ModerationController::class, 'litiges']);
            Route::post('/litiges/{litige}', [ModerationController::class, 'traiterLitige']);

            // Finances
            Route::get('/finances', [FinancesController::class, 'synthese']);
            Route::get('/finances/export', [FinancesController::class, 'exporter']);

            // Musée
            Route::get('/musee', [MuseeController::class, 'adminIndex']);
            Route::post('/musee/rafraichir', [MuseeController::class, 'rafraichir']);
            Route::post('/musee/{musee}/valider', [MuseeController::class, 'valider']);
            Route::post('/musee/{musee}/ignorer', [MuseeController::class, 'ignorer']);
            Route::post('/musee/{musee}/bannir', [MuseeController::class, 'bannir']);
            Route::post('/musee/{musee}/retirer', [MuseeController::class, 'retirer']);
            Route::post('/musee/{musee}/reordonner', [MuseeController::class, 'reordonner']);

            // Publicités
            Route::get('/publicites', [PubliciteController::class, 'index']);
            Route::post('/publicites', [PubliciteController::class, 'store']);
            Route::patch('/publicites/{publicite}', [PubliciteController::class, 'update']);
            Route::post('/publicites/{publicite}/activation', [PubliciteController::class, 'basculerActivation']);
            Route::delete('/publicites/{publicite}', [PubliciteController::class, 'destroy']);

            // Administration des métiers
            Route::get('/metiers', [AdminMetierController::class, 'index']);
            Route::post('/metiers', [AdminMetierController::class, 'store']);
            Route::put('/metiers/{metier}', [AdminMetierController::class, 'update']);
            Route::delete('/metiers/{metier}', [AdminMetierController::class, 'destroy']);

            // Notifications et logs
            Route::post('/notifications/broadcast', [NotificationBroadcastController::class, 'diffuser']);
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
        });

        Route::get('/admin/pieces/{verification}/{face}', PieceIdentiteController::class)->name('admin.piece');

        // Abonnements et paiements
        Route::get('/plans', [AbonnementController::class, 'plans']);
        Route::get('/abonnement', [AbonnementController::class, 'actuel']);
        Route::post('/abonnement', [AbonnementController::class, 'souscrire']);
        Route::get('/moyens-paiement', [MoyenPaiementController::class, 'index']);
        Route::post('/moyens-paiement', [MoyenPaiementController::class, 'store']);
        Route::post('/moyens-paiement/{moyenPaiement}/defaut', [MoyenPaiementController::class, 'definirParDefaut']);
        Route::delete('/moyens-paiement/{moyenPaiement}', [MoyenPaiementController::class, 'destroy']);
        Route::get('/transactions', [AbonnementController::class, 'transactions']);

        // Vérification d'identité (pour le RCCM / Carte d'identité)
        Route::get('/verification-identite', [VerificationIdentiteController::class, 'afficher']);
        Route::post('/verification-identite', [VerificationIdentiteController::class, 'deposer']);
        Route::get('/transactions/{reference}', [AbonnementController::class, 'suivreTransaction']);

        // Messagerie
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/support', [ConversationController::class, 'support']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'envoyer']);
        Route::post('/conversations/{conversation}/lu', [ConversationController::class, 'marquerLu']);

        // Fil d'actualité
        Route::get('/posts', [PostController::class, 'index']);
        Route::post('/posts', [PostController::class, 'store']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        Route::post('/posts/{post}/like', [InteractionController::class, 'basculerLike']);
        Route::get('/posts/{post}/commentaires', [InteractionController::class, 'commentaires']);
        Route::post('/posts/{post}/commentaires', [InteractionController::class, 'commenter']);
        Route::delete('/commentaires/{commentaire}', [InteractionController::class, 'supprimerCommentaire']);

        // Stories
        Route::get('/stories', [StoryController::class, 'index']);
        Route::post('/stories', [StoryController::class, 'store']);
        Route::get('/stories/me', [StoryController::class, 'mesStories']);
        Route::get('/stories/{story}/vues', [StoryController::class, 'voirVues']);
        Route::post('/stories/{story}/vue', [StoryController::class, 'marquerVue']);
        Route::delete('/stories/{story}', [StoryController::class, 'destroy']);

        Route::post('/suivre/{artisan}', [InteractionController::class, 'basculerAbonnement']);
        Route::apiResource('reports', ReportController::class)->only(['index', 'store']);

        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::patch('/bookings/{id}/status', [BookingController::class, 'updateStatus']);

        // Notifications et appareils
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notification}/lu', [NotificationController::class, 'marquerLue']);
        Route::post('/notifications/lu', [NotificationController::class, 'toutMarquerLu']);

        Route::post('/appareils', [AppareilController::class, 'enregistrer']);
        Route::delete('/appareils', [AppareilController::class, 'retirer']);
    });
});