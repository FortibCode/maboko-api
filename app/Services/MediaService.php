<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Enregistre les images et fichiers audio recus par l'API.
 *
 * L'application mobile transmet aujourd'hui ses photos en base64. Les stocker
 * telles quelles saturerait la base ; elles sont donc decodees et ecrites sur
 * le disque configure (local en developpement, compatible S3 en production),
 * seule l'URL etant conservee en base.
 *
 * Depuis l'ajout des messages vocaux, la meme logique s'applique aux audios :
 * formats courants (m4a, mp3, ogg, wav, webm), taille bornee a 3 Mo.
 */
class MediaService
{
    /** Taille maximale acceptee pour une image, en octets. */
    public const TAILLE_MAX = 5 * 1024 * 1024;

    /** Taille maximale acceptee pour un audio, en octets (3 Mo). */
    public const TAILLE_MAX_AUDIO = 3 * 1024 * 1024;

    private const TYPES_AUTORISES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const TYPES_AUDIO_AUTORISES = [
        'audio/mp4'        => 'm4a',
        'audio/x-m4a'      => 'm4a',
        'audio/m4a'        => 'm4a',
        'audio/aac'        => 'aac',
        'audio/mpeg'       => 'mp3',
        'audio/mp3'        => 'mp3',
        'audio/ogg'        => 'ogg',
        'audio/webm'       => 'webm',
        'audio/wav'        => 'wav',
        'audio/x-wav'      => 'wav',
        'audio/3gpp'       => '3gp',
    ];

    /**
     * Enregistre une pièce d'identité sur le disque privé (§7.1).
     *
     * Retourne le chemin, jamais une URL : ces documents ne sont servis
     * qu'à l'administration, par lien signé et temporaire.
     */
    public function enregistrerPiecePrivee(string $valeur, string $dossier): string
    {
        [$binaire, $extension] = $this->decoder($valeur);

        $chemin = $dossier.'/'.Str::uuid().'.'.$extension;

        Storage::disk('local')->put($chemin, $binaire);

        return $chemin;
    }

    /**
     * Accepte soit une URL deja hebergee, soit une image encodee en base64
     * sous la forme « data:image/jpeg;base64,... ». Retourne l'URL publique.
     */
    public function enregistrerImage(string $valeur, string $dossier): string
    {
        if (Str::startsWith($valeur, ['http://', 'https://'])) {
            return $valeur;
        }

        [$binaire, $extension] = $this->decoder($valeur);

        $chemin = $dossier.'/'.Str::uuid().'.'.$extension;

        Storage::disk($this->disque())->put($chemin, $binaire);

        return Storage::disk($this->disque())->url($chemin);
    }

    /**
     * Enregistre un message vocal envoye en base64.
     *
     * Le format attendu est « data:audio/mp4;base64,... ». L'application
     * mobile enregistre l'audio puis l'encode en base64, comme pour les
     * photos. Le retour est une URL publique.
     */
    public function enregistrerAudio(string $valeur, string $dossier): string
    {
        if (Str::startsWith($valeur, ['http://', 'https://'])) {
            return $valeur;
        }

        [$binaire, $extension] = $this->decoderAudio($valeur);

        $chemin = $dossier.'/'.Str::uuid().'.'.$extension;

        Storage::disk($this->disque())->put($chemin, $binaire);

        return Storage::disk($this->disque())->url($chemin);
    }

    /**
     * Décode une image base64 et vérifie son format et son poids.
     *
     * @return array{0: string, 1: string} binaire et extension
     */
    private function decoder(string $valeur): array
    {
        if (! preg_match('#^data:(image/[a-z+]+);base64,(.+)$#i', $valeur, $morceaux)) {
            throw new RuntimeException("Format d'image non reconnu.");
        }

        $typeMime = mb_strtolower($morceaux[1]);

        if (! isset(self::TYPES_AUTORISES[$typeMime])) {
            throw new RuntimeException('Format accepté : JPEG, PNG ou WebP.');
        }

        $binaire = base64_decode($morceaux[2], true);

        if ($binaire === false) {
            throw new RuntimeException("L'image n'a pas pu être lue.");
        }

        if (strlen($binaire) > self::TAILLE_MAX) {
            throw new RuntimeException('Image trop lourde : 5 Mo maximum.');
        }

        return [$binaire, self::TYPES_AUTORISES[$typeMime]];
    }

    /**
     * Décode un audio base64 et vérifie son format et son poids.
     *
     * @return array{0: string, 1: string} binaire et extension
     */
    private function decoderAudio(string $valeur): array
    {
        if (! preg_match('#^data:(audio/[a-z0-9+-]+);base64,(.+)$#i', $valeur, $morceaux)) {
            throw new RuntimeException("Format audio non reconnu.");
        }

        $typeMime = mb_strtolower($morceaux[1]);

        if (! isset(self::TYPES_AUDIO_AUTORISES[$typeMime])) {
            throw new RuntimeException('Format audio accepté : m4a, mp3, ogg, webm, wav, aac.');
        }

        $binaire = base64_decode($morceaux[2], true);

        if ($binaire === false) {
            throw new RuntimeException("L'audio n'a pas pu être lu.");
        }

        if (strlen($binaire) > self::TAILLE_MAX_AUDIO) {
            throw new RuntimeException('Audio trop lourd : 3 Mo maximum (environ 3 minutes).');
        }

        return [$binaire, self::TYPES_AUDIO_AUTORISES[$typeMime]];
    }

    private function disque(): string
    {
        return config('filesystems.default') === 'local' ? 'public' : config('filesystems.default');
    }
}