<?php

namespace App\Providers;

use App\Models\Artisan;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\DemandeDevis;
use App\Policies\ArtisanPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\CoursePolicy;
use App\Policies\DemandeDevisPolicy;
use App\Services\Push\EnvoyeurPush;
use App\Services\Push\FirebaseEnvoyeurPush;
use App\Services\Push\LogEnvoyeurPush;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioSmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EnvoyeurPush::class, function () {
            return match (config('push.driver')) {
                'firebase' => new FirebaseEnvoyeurPush,
                default => new LogEnvoyeurPush,
            };
        });

        $this->app->bind(SmsSender::class, function () {
            return match (config('sms.driver')) {
                'twilio' => new TwilioSmsSender,
                default => new LogSmsSender,
            };
        });
    }

    public function boot(): void
    {
        $this->configurerLimiteursDeDebit();
        $this->enregistrerPolicies();
    }

    private function enregistrerPolicies(): void
    {
        Gate::policy(DemandeDevis::class, DemandeDevisPolicy::class);
        Gate::policy(Artisan::class, ArtisanPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Course::class, CoursePolicy::class);
    }

    /**
     * Sans ces limiteurs, un code OTP a six chiffres se devine par force brute
     * en quelques minutes et un mot de passe se teste sans aucune entrave.
     */
    private function configurerLimiteursDeDebit(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Tentatives de connexion : par identifiant ET par adresse IP.
        RateLimiter::for('connexion', function (Request $request) {
            $identifiant = (string) $request->input('identifiant', $request->input('email', ''));

            return [
                Limit::perMinutes(10, 5)->by('connexion:'.mb_strtolower($identifiant)),
                Limit::perMinutes(10, 20)->by('connexion-ip:'.$request->ip()),
            ];
        });

        // Emission d'un code OTP : evite le harcelement par SMS et la facture qui va avec.
        RateLimiter::for('otp-envoi', function (Request $request) {
            return [
                Limit::perMinutes(10, 3)->by('otp:'.$request->input('telephone')),
                Limit::perMinutes(10, 10)->by('otp-ip:'.$request->ip()),
            ];
        });

        // Verification d'un code OTP : complete le compteur de tentatives en base.
        RateLimiter::for('otp-verif', function (Request $request) {
            return [
                Limit::perMinutes(10, 10)->by('otpv:'.$request->input('telephone')),
                Limit::perMinutes(10, 30)->by('otpv-ip:'.$request->ip()),
            ];
        });
    }
}
