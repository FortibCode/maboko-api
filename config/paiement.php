<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Passerelle de paiement
    |--------------------------------------------------------------------------
    |
    | "simulation" : aucun appel réseau, la transaction réussit immédiatement.
    |                Destiné au développement, tant que les comptes marchands
    |                Airtel et MTN ne sont pas ouverts.
    | "reel"       : appelle les API Airtel Money et MTN MoMo.
    |
    */

    'mode' => env('PAIEMENT_MODE', 'simulation'),

    'devise' => 'XAF',

    /*
    |--------------------------------------------------------------------------
    | Commissions de la plateforme (§4.5)
    |--------------------------------------------------------------------------
    | Taux en pourcentage, prélevés sur les missions et les courses.
    */

    'commission' => [
        'mission' => (float) env('COMMISSION_MISSION', 10),
        'course' => (float) env('COMMISSION_COURSE', 15),
        'abonnement' => 0.0,
    ],

    'airtel' => [
        'base_url' => env('AIRTEL_BASE_URL', 'https://openapiuat.airtel.africa'),
        'client_id' => env('AIRTEL_CLIENT_ID'),
        'client_secret' => env('AIRTEL_CLIENT_SECRET'),
        'pays' => env('AIRTEL_PAYS', 'CG'),
        'devise' => env('AIRTEL_DEVISE', 'XAF'),
        'secret_webhook' => env('AIRTEL_WEBHOOK_SECRET'),
    ],

    'mtn' => [
        'base_url' => env('MTN_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),
        'cle_abonnement' => env('MTN_SUBSCRIPTION_KEY'),
        'utilisateur_api' => env('MTN_API_USER'),
        'cle_api' => env('MTN_API_KEY'),
        'environnement' => env('MTN_ENVIRONMENT', 'sandbox'),
        'devise' => env('MTN_DEVISE', 'EUR'),
        'secret_webhook' => env('MTN_WEBHOOK_SECRET'),
    ],

];
