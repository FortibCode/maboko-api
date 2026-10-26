<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canal d'envoi des SMS
    |--------------------------------------------------------------------------
    |
    | "log"    : le message part dans les logs applicatifs (developpement).
    | "twilio" : envoi reel via l'API Twilio (production).
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposition du code OTP dans la reponse HTTP
    |--------------------------------------------------------------------------
    |
    | Uniquement destine au developpement local sans passerelle SMS.
    | Activer ce drapeau en production revient a supprimer la verification
    | par telephone : n'importe qui pourrait creer un compte avec le numero
    | d'un tiers ou reinitialiser son mot de passe.
    |
    */

    'expose_otp_in_response' => env('OTP_EXPOSE_IN_RESPONSE', false),

    /*
    |--------------------------------------------------------------------------
    | Numeros de test
    |--------------------------------------------------------------------------
    |
    | Pour ces numeros, et pour eux seuls, aucun SMS n'est tente et le code
    | revient dans la reponse HTTP. Cela permet d'essayer l'inscription sans
    | passerelle configuree, sans ouvrir cette porte a tous les comptes comme
    | le ferait OTP_EXPOSE_IN_RESPONSE.
    |
    | Format international, separes par des virgules :
    |   OTP_NUMEROS_TEST=+242060000001,+242066123456
    |
    */

    'numeros_test' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OTP_NUMEROS_TEST', '')),
    ))),

];
