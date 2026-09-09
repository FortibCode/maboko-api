<?php

use App\Http\Controllers\ArtisanController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvisController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ChauffeurController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DemandeDevisController;
use App\Http\Controllers\FavoriController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\MetierController;
use App\Http\Controllers\MissionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StoryController;
use App\Http\Controllers\TableauDeBordController;
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
    | Routes authentifiees
    */
    Route::middleware('auth:sanctum')->group(function () {

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
        | Modules encore a implementer : chauffeurs (phase 6),
        | missions et courses (remplacees par /demandes, a retirer apres reprise).
        */
        Route::apiResource('chauffeurs', ChauffeurController::class);
        Route::apiResource('missions', MissionController::class);
        Route::apiResource('courses', CourseController::class);

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

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications', [NotificationController::class, 'store']);
    });
});
