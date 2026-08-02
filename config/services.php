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

    /*
     | Web service de Títulos Electrónicos de la SEP (SIGED / MET).
     |
     | `modo`:
     |  - real: llama el WSDL de la SEP con PHP SoapClient (requiere red y las
     |    credenciales usuario/contraseña capturadas en Titulación → Configuración).
     |  - fake: simula la respuesta del WS (para probar el flujo en local sin red
     |    ni credenciales). Devuelve un folio de proceso ficticio.
     |  - off: el envío al WS queda deshabilitado (solo se generan/firman los XML).
     |
     | Las credenciales NO viven aquí: son por escuela y se guardan cifradas en la
     | tabla `titulacion_ws_config`. Aquí solo van los endpoints y el modo global.
     */
    'titulos_sep' => [
        'modo' => env('TITULOS_SEP_MODO', 'fake'),
        'wsdl_pruebas' => env('TITULOS_SEP_WSDL_PRUEBAS', 'https://metqa.siged.sep.gob.mx/met-ws/services/TitulosElectronicos.wsdl'),
        'wsdl_produccion' => env('TITULOS_SEP_WSDL_PRODUCCION'),
        'timeout' => (int) env('TITULOS_SEP_TIMEOUT', 30),
    ],

    /*
    |---------------------------------------------------------------------------
    | Banxico (SIE)
    |---------------------------------------------------------------------------
    |
    | El tipo de cambio FIX, que es el que vale para efectos fiscales en México.
    | El token es GRATUITO y se saca en:
    | https://www.banxico.org.mx/SieAPIRest/service/v1/token
    |
    | Sin token, el panel muestra la referencia del Banco Central Europeo y lo
    | dice —sirve para orientarse, no para timbrar—.
    */
    'banxico' => [
        'token' => env('BANXICO_TOKEN'),
    ],

];
