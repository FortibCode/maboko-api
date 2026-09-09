<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Enregistre les images recues par l'API.
 *
 * L'application mobile transmet aujourd'hui ses photos en base64. Les stocker
 * telles quelles saturerait la base ; elles sont donc decodees et ecrites sur
 * le disque configure (local en developpement, compatible S3 en production),
 * seule l'URL etant conservee en base.
 */
class MediaService
{
    /** Taille maximale acceptee pour une image, en octets. */
    public const TAILLE_MAX = 5 * 1024 * 1024;

    private const TYPES_AUTORISES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Accepte soit une URL deja hebergee, soit une image encodee en base64
     * sous la forme « data:image/jpeg;base64,... ». Retourne l'URL publique.
     */
    public function enregistrerImage(string $valeur, string $dossier): string
    {
        if (Str::startsWith($valeur, ['http://', 'https://'])) {
            return $valeur;
        }

        if (! preg_match('#^data:(image/[a-z+]+);base64,(.+)$#i', $valeur, $morceaux)) {
            throw new RuntimeException("Format d'image non reconnu.");
        }

        [$_, $typeMime, $charge] = $morceaux;
        $typeMime = mb_strtolower($typeMime);

        if (! isset(self::TYPES_AUTORISES[$typeMime])) {
            throw new RuntimeException('Format accepte : JPEG, PNG ou WebP.');
        }

        $binaire = base64_decode($charge, true);

        if ($binaire === false) {
            throw new RuntimeException("L'image n'a pas pu etre lue.");
        }

        if (strlen($binaire) > self::TAILLE_MAX) {
            throw new RuntimeException('Image trop lourde : 5 Mo maximum.');
        }

        $chemin = $dossier.'/'.Str::uuid().'.'.self::TYPES_AUTORISES[$typeMime];

        Storage::disk($this->disque())->put($chemin, $binaire);

        return Storage::disk($this->disque())->url($chemin);
    }

    private function disque(): string
    {
        return config('filesystems.default') === 'local' ? 'public' : config('filesystems.default');
    }
}
