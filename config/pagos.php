<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Modo del cobro en línea
    |--------------------------------------------------------------------------
    |
    | `real` habla con las pasarelas de verdad (con las credenciales que cada
    | escuela guarda en su configuración).
    |
    | `fake` no sale a internet: manda a una pantalla propia donde se elige el
    | desenlace del pago. Sirve para recorrer el flujo entero —iniciar, volver,
    | recibir el aviso, conciliar— sin credenciales y sin cobrarle a nadie.
    |
    | Mismo patrón que el SSO y el web service de títulos. El default es `real`
    | a propósito: un despliegue al que se le olvide esta variable debe cobrar,
    | no simular.
    |
    */

    'modo' => env('PAGOS_MODO', 'real'),

];
