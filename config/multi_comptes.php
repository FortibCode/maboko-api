<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Comptes par personne (multi-comptes artisan)
    |--------------------------------------------------------------------------
    |
    | Nombre de comptes actifs qu'une personne peut réunir, compte principal
    | compris. Les comptes archivés n'y comptent pas. L'application mobile
    | lit cette valeur dans GET /comptes : la changer ici suffit, par exemple
    | pour une offre premium.
    |
    */
    'maximum' => (int) env('MULTI_COMPTES_MAXIMUM', 3),
];
