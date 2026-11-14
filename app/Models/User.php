<?php

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property int|null $compte_principal_id Renseigné pour un profil rattaché (multi-comptes)
 * @property string $nom
 * @property string|null $prenom
 * @property string|null $avatar_url
 * @property string $role
 * @property string $statut
 * @property Carbon|null $derniere_connexion_at
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_CLIENT = 'client';

    public const ROLE_ARTISAN = 'artisan';

    public const ROLE_CHAUFFEUR = 'chauffeur';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLES = [
        self::ROLE_CLIENT,
        self::ROLE_ARTISAN,
        self::ROLE_CHAUFFEUR,
        self::ROLE_ADMIN,
        self::ROLE_SUPER_ADMIN,
    ];

    public const STATUT_ACTIF = 'actif';

    public const STATUT_SUSPENDU = 'suspendu';

    /** Comptes d'une même personne, compte principal compris (multi-comptes). */
    public const MAX_COMPTES = 3;

    protected $table = 'users';

    protected $fillable = [
        'compte_principal_id',
        'nom',
        'prenom',
        'telephone',
        'email',
        'role',
        'password',
        'is_verified',
        'statut',
        'avatar_url',
        'ville',
        'quartier',
        'derniere_connexion_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'password' => 'hashed',
            'derniere_connexion_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Roles
    // ------------------------------------------------------------------

    public function estArtisan(): bool
    {
        return $this->role === self::ROLE_ARTISAN;
    }

    public function estChauffeur(): bool
    {
        return $this->role === self::ROLE_CHAUFFEUR;
    }

    public function estAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    // ------------------------------------------------------------------
    // Multi-comptes
    // ------------------------------------------------------------------

    public function estProfilRattache(): bool
    {
        return $this->compte_principal_id !== null;
    }

    /** Compte qui porte la connexion : lui-même, ou celui auquel il est rattaché. */
    public function comptePrincipal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'compte_principal_id');
    }

    public function profilsRattaches(): HasMany
    {
        return $this->hasMany(self::class, 'compte_principal_id');
    }

    public function titulaire(): self
    {
        /** @var self|null $principal */
        $principal = $this->comptePrincipal;

        return $principal ?? $this;
    }

    /**
     * Tous les comptes de la personne, compte principal compris.
     *
     * @return Builder<self>
     */
    public function famille(): Builder
    {
        $principal = $this->compte_principal_id ?? $this->id;

        return self::query()
            ->where(fn (Builder $r) => $r->whereKey($principal)->orWhere('compte_principal_id', $principal));
    }

    /** Les comptes qui servent à se connecter : jamais un profil rattaché. */
    public function scopePrincipaux(Builder $requete): Builder
    {
        return $requete->whereNull('compte_principal_id');
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    public function artisan(): HasOne
    {
        return $this->hasOne(Artisan::class, 'utilisateur_id');
    }

    public function chauffeur(): HasOne
    {
        return $this->hasOne(Chauffeur::class, 'utilisateur_id');
    }

    public function admin(): HasOne
    {
        return $this->hasOne(Admin::class, 'utilisateur_id');
    }

    public function superAdmin(): HasOne
    {
        return $this->hasOne(SuperAdmin::class, 'utilisateur_id');
    }

    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class, 'user_id');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'utilisateur_id');
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(DemandeDevis::class, 'client_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Post::class, 'artisan_id');
    }

    public function avisDeposes(): HasMany
    {
        return $this->hasMany(Avis::class, 'auteur_id');
    }

    public function abonnementsArtisans(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'follows', 'follower_id', 'artisan_id')
            ->withTimestamps();
    }

    public function favoris(): HasMany
    {
        return $this->hasMany(Favori::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('dernier_message_lu_id', 'muet')
            ->withTimestamps();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function verificationIdentite(): HasOne
    {
        return $this->hasOne(VerificationIdentite::class)->latestOfMany();
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (?string $valeur) => MediaService::absolue($valeur));
    }
}
