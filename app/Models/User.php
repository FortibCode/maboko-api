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
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string|null $matricule Identifiant Maboko affiché sur la carte du profil
 * @property int|null $compte_principal_id Renseigné pour un profil rattaché (multi-comptes)
 * @property Carbon|null $archive_at Profil retiré de la liste des comptes, données conservées
 * @property string $nom
 * @property string|null $prenom
 * @property string|null $avatar_url
 * @property string $role
 * @property string $statut
 * @property Carbon|null $derniere_connexion_at
 */
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

    /** Préfixes du matricule, selon le rôle à la création du compte. */
    private const PREFIXES_MATRICULE = [
        self::ROLE_ARTISAN => 'MBK-ART',
        self::ROLE_CHAUFFEUR => 'MBK-CHA',
        self::ROLE_ADMIN => 'MBK-ADM',
        self::ROLE_SUPER_ADMIN => 'MBK-ADM',
    ];

    protected $table = 'users';

    protected $fillable = [
        'compte_principal_id',
        'archive_at',
        'matricule',
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

    /**
     * Tout compte reçoit son matricule à la création, quel que soit le
     * chemin (inscription, Google, compte ajouté, administration). Il ne
     * change plus ensuite : c'est un identifiant, pas un libellé de rôle.
     */
    protected static function booted(): void
    {
        static::created(function (self $utilisateur) {
            if ($utilisateur->matricule === null) {
                $utilisateur->forceFill([
                    'matricule' => self::matriculePour($utilisateur->id, $utilisateur->role),
                ])->saveQuietly();
            }
        });
    }

    /** MBK-ART-00042 : préfixe du rôle et numéro du compte sur cinq chiffres au moins. */
    public static function matriculePour(int $id, ?string $role): string
    {
        $prefixe = self::PREFIXES_MATRICULE[$role] ?? 'MBK-CLI';

        return $prefixe.'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // Multi-comptes
    // ------------------------------------------------------------------

    /** Comptes actifs qu'une personne peut réunir (config/multi_comptes.php). */
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