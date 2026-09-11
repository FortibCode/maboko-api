<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repare les URLs de medias qui pointent sur le point d'entree S3.
 *
 * Quand l'adresse de lecture publique n'etait pas configuree, Laravel
 * fabriquait l'URL a partir du point d'entree d'ecriture. Les fichiers
 * partaient bien, mais l'adresse enregistree repondait 403 : les photos
 * existaient et ne s'affichaient nulle part.
 *
 *   https://<ref>.storage.supabase.co/storage/v1/s3/<bucket>/avatars/x.png
 *   -> https://<ref>.supabase.co/storage/v1/object/public/<bucket>/avatars/x.png
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string}> */
    private array $colonnes = [
        ['users', 'avatar_url'],
        ['messages', 'media_url'],
        ['stories', 'media_url'],
        ['demande_photos', 'url'],
        ['post_medias', 'url'],
        ['posts', 'image_url'],
        ['metiers', 'image_url'],
    ];

    public function up(): void
    {
        foreach ($this->colonnes as [$table, $colonne]) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->whereNotNull($colonne)
                ->where($colonne, 'like', '%/storage/v1/s3/%')
                ->orderBy('id')
                ->each(function (object $ligne) use ($table, $colonne) {
                    $repare = $this->versLecturePublique((string) $ligne->{$colonne});

                    if ($repare !== null) {
                        DB::table($table)->where('id', $ligne->id)->update([$colonne => $repare]);
                    }
                });
        }
    }

    private function versLecturePublique(string $url): ?string
    {
        $morceaux = parse_url($url);

        if (! isset($morceaux['host'], $morceaux['path'])) {
            return null;
        }

        // Le chemin porte « /storage/v1/s3/<bucket>/<fichier> ».
        $position = strpos($morceaux['path'], '/storage/v1/s3/');

        if ($position === false) {
            return null;
        }

        $apres = substr($morceaux['path'], $position + strlen('/storage/v1/s3/'));
        $hote = str_replace('.storage.supabase.co', '.supabase.co', $morceaux['host']);

        return "https://{$hote}/storage/v1/object/public/{$apres}";
    }

    public function down(): void
    {
        // L'ancienne forme ne servait a rien : il n'y a rien a restaurer.
    }
};
