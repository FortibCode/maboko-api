<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canal des notifications push
    |--------------------------------------------------------------------------
    |
    | "log"      : les envois partent dans les journaux (développement).
    | "firebase" : envoi réel via Firebase Cloud Messaging (API HTTP v1).
    |
    */

    'driver' => env('PUSH_DRIVER', 'log'),

    'firebase' => [
        'projet_id' => env('FIREBASE_PROJECT_ID'),
        // Chemin du fichier de compte de service téléchargé depuis la console.
        'identifiants' => env('FIREBASE_CREDENTIALS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Repli SMS (§7.2)
    |--------------------------------------------------------------------------
    |
    | « Envoi automatique d'un SMS de secours lorsque la notification push
    | échoue. » Le repli ne concerne que les notifications marquées comme
    | importantes : une réaction sur une publication ne justifie pas un SMS.
    |
    */

    'repli_sms' => env('PUSH_REPLI_SMS', true),

];
