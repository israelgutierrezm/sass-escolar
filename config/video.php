<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Modo de las clases en línea
    |--------------------------------------------------------------------------
    |
    | `real` habla con Zoom y con Google de verdad, con las credenciales que
    | cada escuela guarda en su configuración.
    |
    | `fake` no sale a internet: inventa un enlace y deja recorrer el flujo
    | entero —programar, repartir licencias, abrir el botón del alumno,
    | cancelar— sin credenciales y sin crear reuniones que nadie va a usar.
    |
    | Mismo patrón que el cobro, el SSO y el web service de títulos. El default
    | es `real` a propósito: a un despliegue que olvide la variable le toca
    | intentar la clase de verdad, no simularla y dejar a un grupo esperando
    | frente a un enlace que no lleva a ninguna parte.
    |
    */

    'modo' => env('VIDEO_MODO', 'real'),

];
