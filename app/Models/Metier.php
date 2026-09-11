<?php

namespace App\Models;

use App\Models\Pivots\ArtisanMetier;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $nom
 * @property string $slug
 * @property-read string|null $image_url URL absolue, construite à la lecture
 * @property-read ArtisanMetier $pivot
 */
class Metier extends Model
{
    use HasFactory;

    protected $table = 'metiers';

    protected $fillable = ['nom', 'slug', 'icone', 'image_url', 'description', 'ordre', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    /**
     * Photo du métier : la colonne ne garde qu'un chemin, l'hôte se décide
     * à la lecture — voir MediaService::absolue().
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (?string $valeur) => MediaService::absolue($valeur));
    }

    public function artisans(): BelongsToMany
    {
        return $this->belongsToMany(Artisan::class, 'artisan_metier')
            ->using(ArtisanMetier::class)
            ->withPivot('niveau', 'principal')
            ->withTimestamps();
    }
}
