<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $nom
 * @property string|null $prenom
 * @property string|null $avatar_url
 * @property string $role
 * @property string $statut
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

    protected $table = 'users';

    protected $fillable = [
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

    /** Courses reservees par cet utilisateur en tant que client. */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'utilisateur_id');
    }

    /** Demandes de devis envoyees en tant que client. */
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

    /** Artisans suivis par cet utilisateur (fil d'actualite). */
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
            ->withPivot('lu_jusqu_a', 'muet')
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
}
