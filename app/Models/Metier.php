<?php

namespace App\Models;

use App\Models\Pivots\ArtisanMetier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $nom
 * @property string $slug
 * @property-read ArtisanMetier $pivot
 */
class Metier extends Model
{
    use HasFactory;

    protected $table = 'metiers';

    protected $fillable = ['nom', 'slug', 'icone', 'description', 'ordre', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function artisans(): BelongsToMany
    {
        return $this->belongsToMany(Artisan::class, 'artisan_metier')
            ->using(ArtisanMetier::class)
            ->withPivot('niveau', 'principal')
            ->withTimestamps();
    }
}
