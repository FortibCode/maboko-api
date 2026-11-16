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
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    public const ROLE_CLIENT = 'client';
    public const ROLE_ARTISAN = 'artisan';
    public const ROLE_CHAUFFEUR = 'chauffeur';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLES = [
        self::ROLE_CLIENT, self::ROLE_ARTISAN, self::ROLE_CHAUFFEUR,
        self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN,
    ];

    public const STATUT_ACTIF = 'actif';
    public const STATUT_SUSPENDU = 'suspendu';

    protected $table = 'users';

    protected $fillable = [
        'compte_principal_id',
        'archive_at',
        'matricule', // 👈 AJOUT
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
            'archive_at' => 'datetime',
        ];
    }

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

    public static function maximumComptes(): int
    {
        return max(1, (int) config('multi_comptes.maximum', 3));
    }

    public function estProfilRattache(): bool
    {
        return $this->compte_principal_id !== null;
    }

    public function estArchive(): bool
    {
        return $this->archive_at !== null;
    }

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

    public function famille(): Builder
    {
        $principal = $this->compte_principal_id ?? $this->id;

        return self::query()
            ->where(fn (Builder $r) => $r->whereKey($principal)->orWhere('compte_principal_id', $principal));
    }

    public function familleActive(): Builder
    {
        return $this->famille()->whereNull('archive_at');
    }

    public function scopePrincipaux(Builder $requete): Builder
    {
        return $requete->whereNull('compte_principal_id');
    }

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

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'artisan_id');
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (?string $valeur) => MediaService::absolue($valeur));
    }
}