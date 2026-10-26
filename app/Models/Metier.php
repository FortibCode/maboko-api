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
<<<<<<< HEAD
 * @property string|null $icone
 * @property string|null $image_url
=======
 * @property-read string|null $image_url URL absolue, construite à la lecture
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61
 * @property-read ArtisanMetier $pivot
 */
class Metier extends Model
{
    use HasFactory;

    protected $table = 'metiers';

<<<<<<< HEAD
    protected $fillable = [
        'nom',
        'slug',
        'icone',
        'image_url',
        'description',
        'ordre',
        'actif',
    ];
=======
    protected $fillable = ['nom', 'slug', 'icone', 'image_url', 'description', 'ordre', 'actif'];
>>>>>>> 191747fdc8be36e8985fed8248eddadb6cd3fa61

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