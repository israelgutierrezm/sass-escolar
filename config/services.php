<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     | SSO con Google (Socialite) para el acceso de las ESCUELAS (tenants).
     |
     | `modo`:
     |  - real: flujo OAuth de verdad (requiere client id/secret registrados y
     |    las URIs de redirección de cada tenant en la consola de Google).
     |  - fake: simula el retorno de Google (para probar en local sin OAuth,
     |    donde los subdominios *.localhost no valen como redirect_uri).
     |  - off: no se ofrece el botón «Continuar con Google».
     |
     | El `redirect` se arma dinámico por tenant (dominio de la escuela) en el
     | controlador; se deja el env por si se quiere fijar uno.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'modo' => env('SSO_GOOGLE_MODO', 'off'),
    ],

];
