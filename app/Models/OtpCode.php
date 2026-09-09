<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpCode extends Model
{
    use HasFactory;

    protected $table = 'otp_codes';

    protected $fillable = [
        'user_id',
        'telephone',
        'nom',
        'email',
        'password',
        'role',
        'code',
        'expiration',
        'statut',
        'tentatives',
        'reset_token',
        'reset_token_expiration',
        'reset_token_used_at',
    ];

    /**
     * Le mot de passe en attente et le jeton de reinitialisation ne doivent
     * jamais ressortir dans une reponse, meme par accident.
     */
    protected $hidden = [
        'password',
        'code',
        'reset_token',
    ];

    protected function casts(): array
    {
        return [
            'expiration' => 'datetime',
            'statut' => 'boolean',
            'reset_token_expiration' => 'datetime',
            'reset_token_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
