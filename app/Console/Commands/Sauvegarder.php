<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde quotidienne des données (§7.1).
 *
 * Deux volets : la base, et les fichiers privés — pièces d'identité
 * chiffrées comprises. Une sauvegarde de la base seule laisserait des
 * références vers des fichiers disparus.
 */
class Sauvegarder extends Command
{
    protected $signature = 'maboko:sauvegarder
                            {--destination= : dossier de destination}
                            {--conserver=14 : nombre de sauvegardes à garder}';

    protected $description = 'Sauvegarde la base de données et les fichiers privés';

    public function handle(): int
    {
        $destination = $this->option('destination') ?: storage_path('sauvegardes');
        File::ensureDirectoryExists($destination);

        $horodatage = now()->format('Y-m-d_His');

        $base = $this->sauvegarderBase($destination, $horodatage);

        if ($base === null) {
            $this->error('La sauvegarde de la base a échoué.');

            return self::FAILURE;
        }

        $fichiers = $this->sauvegarderFichiers($destination, $horodatage);

        $this->purger($destination, (int) $this->option('conserver'));

        $this->table(
            ['Élément', 'Fichier', 'Taille'],
            array_filter([
                ['Base de données', basename($base), $this->taille($base)],
                $fichiers ? ['Fichiers privés', basename($fichiers), $this->taille($fichiers)] : null,
            ]),
        );

        return self::SUCCESS;
    }

    private function sauvegarderBase(string $destination, string $horodatage): ?string
    {
        $connexion = config('database.default');
        $config = config("database.connections.{$connexion}");

        // On se fonde sur le pilote, pas sur le nom de la connexion : rien
        // n'oblige à l'appeler « pgsql », et une sauvegarde qui échoue en
        // silence est pire que pas de sauvegarde du tout.
        return match ($config['driver'] ?? null) {
            'sqlite' => $this->copierSqlite($config['database'], "{$destination}/base_{$horodatage}.sqlite"),
            'pgsql' => $this->viderPostgres($config, "{$destination}/base_{$horodatage}.sql"),
            'mysql', 'mariadb' => $this->viderMysql($config, "{$destination}/base_{$horodatage}.sql"),
            default => $this->pilotNonSupporte($config['driver'] ?? '?'),
        };
    }

    private function pilotNonSupporte(string $pilote): null
    {
        $this->error("Pilote de base non pris en charge par la sauvegarde : {$pilote}.");

        return null;
    }

    private function copierSqlite(string $source, string $cible): ?string
    {
        if (! is_file($source)) {
            return null;
        }

        return File::copy($source, $cible) ? $cible : null;
    }

    private function viderPostgres(array $config, string $cible): ?string
    {
        $processus = new Process([
            'pg_dump',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--username='.$config['username'],
            '--no-password',
            '--format=plain',
            '--file='.$cible,
            $config['database'],
        ]);

        // Le mot de passe passe par l'environnement : sur la ligne de commande
        // il serait visible de tout processus du serveur.
        $processus->setEnv(['PGPASSWORD' => $config['password'] ?? '']);
        $processus->setTimeout(600);
        $processus->run();

        if (! $processus->isSuccessful()) {
            $this->error(trim($processus->getErrorOutput()));

            return null;
        }

        return $cible;
    }

    private function viderMysql(array $config, string $cible): ?string
    {
        $processus = new Process([
            'mysqldump',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--single-transaction',
            '--result-file='.$cible,
            $config['database'],
        ]);

        $processus->setEnv(['MYSQL_PWD' => $config['password'] ?? '']);
        $processus->setTimeout(600);
        $processus->run();

        return $processus->isSuccessful() ? $cible : null;
    }

    /** Archive les fichiers privés : pièces d'identité chiffrées comprises. */
    private function sauvegarderFichiers(string $destination, string $horodatage): ?string
    {
        $source = storage_path('app/private');

        if (! is_dir($source)) {
            $source = storage_path('app');
        }

        if (! is_dir($source) || count(File::allFiles($source)) === 0) {
            return null;
        }

        $archive = "{$destination}/fichiers_{$horodatage}.zip";
        $zip = new \ZipArchive;

        if ($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        foreach (File::allFiles($source) as $fichier) {
            $zip->addFile($fichier->getRealPath(), $fichier->getRelativePathname());
        }

        $zip->close();

        return $archive;
    }

    /**
     * Ne conserve que les N sauvegardes les plus récentes.
     *
     * Sans purge, le disque se remplit silencieusement jusqu'à ce que le
     * serveur s'arrête — une panne d'autant plus pénible qu'elle vient du
     * dispositif censé protéger les données.
     */
    private function purger(string $destination, int $conserver): void
    {
        foreach (['base_*', 'fichiers_*'] as $motif) {
            $fichiers = collect(glob("{$destination}/{$motif}"))
                ->sortByDesc(fn (string $chemin) => filemtime($chemin))
                ->values();

            $fichiers->slice($conserver)->each(fn (string $chemin) => File::delete($chemin));
        }
    }

    private function taille(string $chemin): string
    {
        $octets = filesize($chemin) ?: 0;

        return $octets > 1048576
            ? round($octets / 1048576, 1).' Mo'
            : round($octets / 1024, 1).' Ko';
    }
}
