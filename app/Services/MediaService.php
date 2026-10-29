<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
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
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/webm' => 'webm',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/3gpp' => '3gp',
    ];

    /**
     * Disque ou vivent les pieces d'identite.
     *
     * « local » designe le disque du conteneur, qui repart vierge a chaque
     * deploiement : les pieces deposees y disparaitraient avant meme d'avoir
     * ete examinees. En ligne, pointez DISQUE_PRIVE sur un stockage durable
     * — et sur un bucket prive, jamais celui des photos publiques.
     */
    public static function disquePrive(): string
    {
        return config('filesystems.disque_prive', 'local');
    }

    /**
     * Enregistre une pièce d'identité sur le disque privé, chiffrée (§7.1).
     *
     * Le fichier est chiffré avec la clé applicative avant d'être écrit : une
     * copie de la sauvegarde ou un accès au disque ne livre rien d'exploitable.
     *
     * Retourne le chemin, jamais une URL : ces documents ne sont servis qu'à
     * l'administration, par lien signé et temporaire.
     */
    public function enregistrerPiecePrivee(string $valeur, string $dossier): string
    {
        [$binaire, $extension] = $this->decoder($valeur);

        $chemin = $dossier.'/'.Str::uuid().'.'.$extension.'.chiffre';

        Storage::disk(self::disquePrive())->put($chemin, Crypt::encryptString(base64_encode($binaire)));

        return $chemin;
    }

    /**
     * Relit une pièce chiffrée.
     *
     * Retourne null si le fichier est absent ou si le déchiffrement échoue —
     * ce qui arrive après une rotation de la clé applicative sans reprise des
     * fichiers existants.
     */
    public function lirePiecePrivee(string $chemin): ?string
    {
        if (! Storage::disk(self::disquePrive())->exists($chemin)) {
            return null;
        }

        $contenu = Storage::disk(self::disquePrive())->get($chemin);

        if ($contenu === null) {
            return null;
        }

        try {
            return base64_decode(Crypt::decryptString($contenu), true) ?: null;
        } catch (DecryptException $e) {
            Log::error('[IDENTITE] déchiffrement impossible', ['chemin' => $chemin]);

            return null;
        }
    }

    /** Type MIME déduit de l'extension, pour servir le fichier déchiffré. */
    public function typeMimeDe(string $chemin): string
    {
        $extension = pathinfo(str_replace('.chiffre', '', $chemin), PATHINFO_EXTENSION);

        return array_search($extension, self::TYPES_AUTORISES, true) ?: 'application/octet-stream';
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

        $url = Storage::disk($this->disque())->url($chemin);

        // Sur disque local, seul le chemin est conserve. L'hote depend de
        // l'adresse par laquelle le client atteint l'API — « localhost » sur
        // la machine de developpement, une IP sur le reseau local depuis un
        // telephone, un domaine en production. Une URL absolue figee a
        // l'enregistrement ne s'affiche que depuis la machine qui l'a ecrite.
        if ($this->disque() === 'public') {
            return parse_url($url, PHP_URL_PATH) ?: $url;
        }

        return $url;
    }

    /**
     * Rend une URL de media absolue pour le client qui interroge l'API.
     *
     * Les chemins relatifs sont prefixes par l'hote de la requete en cours ;
     * les URLs deja absolues (S3, ou heritees) sont rendues telles quelles.
     */
    public static function absolue(?string $valeur): ?string
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        if (Str::startsWith($valeur, ['http://', 'https://'])) {
            return $valeur;
        }

        return url($valeur);
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
            throw new RuntimeException('Format audio non reconnu.');
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
