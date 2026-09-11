<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ramene les URLs de medias a un chemin relatif.
 *
 * Les fichiers etaient enregistres avec l'hote de la machine qui les avait
 * recus — « http://localhost/storage/... ». Un telephone qui demande cette
 * adresse interroge lui-meme, jamais le serveur : la photo partait bien mais
 * ne se reaffichait jamais. L'hote se decide desormais a la lecture.
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
    ];

    public function up(): void
    {
        foreach ($this->colonnes as [$table, $colonne]) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->whereNotNull($colonne)
                ->where($colonne, 'like', '%/storage/%')
                ->orderBy('id')
                ->each(function (object $ligne) use ($table, $colonne) {
                    $valeur = $ligne->{$colonne};
                    $chemin = parse_url((string) $valeur, PHP_URL_PATH);

                    if ($chemin === false || $chemin === null || $chemin === $valeur) {
                        return;
                    }

                    DB::table($table)->where('id', $ligne->id)->update([$colonne => $chemin]);
                });
        }
    }

    public function down(): void
    {
        // Les URLs absolues d'origine designaient un hote qui n'a plus de
        // sens : il n'y a rien a restaurer.
    }
};
