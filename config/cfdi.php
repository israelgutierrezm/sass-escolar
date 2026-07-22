<?php

declare(strict_types=1);
use App\Services\Cfdi\PacFalso;

/*
|--------------------------------------------------------------------------
| Facturación electrónica (CFDI 4.0)
|--------------------------------------------------------------------------
|
| El PAC es configuración de instalación, no de escuela: todas las escuelas de
| esta instancia timbran por el mismo proveedor, con las credenciales de quien
| opera el SaaS. Lo que sí es por escuela —si el alumno puede autofacturar, con
| qué uso de CFDI se emite por defecto— vive en `configuraciones` del tenant.
|
| `driver` apunta a una clase que implemente App\Services\Cfdi\Pac. Mientras no
| haya PAC contratado se queda en `falso`, que ejerce el flujo completo sin
| mandar nada al SAT. Registrar uno real es agregar su clase aquí y nada más:
| ni el job ni el servicio de emisión saben cuál está en uso.
|
*/

return [

    'driver' => env('CFDI_PAC', 'falso'),

    'drivers' => [
        'falso' => PacFalso::class,
    ],

    /*
    | Datos del emisor. Van en el .env y no en la base porque son las
    | credenciales fiscales de quien factura, y un certificado no debería poder
    | cambiarse desde una pantalla de administración.
    */
    'emisor' => [
        'rfc' => env('CFDI_EMISOR_RFC'),
        'razon_social' => env('CFDI_EMISOR_RAZON_SOCIAL'),
        'regimen_fiscal' => env('CFDI_EMISOR_REGIMEN', '601'),
        'cp' => env('CFDI_EMISOR_CP'),
    ],

    /*
    | Cuántas veces reintenta el timbrado antes de darlo por fallido, y cuánto
    | espera entre intentos (segundos). Un PAC que no contesta suele volver en
    | minutos; reintentar en segundos solo gasta la cola.
    */
    'reintentos' => (int) env('CFDI_REINTENTOS', 3),
    'espera' => [60, 300, 900],

    /*
    | Uso de CFDI por defecto. D10 es "pagos por servicios educativos
    | (colegiaturas)", que es lo que pide casi todo alumno; G03 "gastos en
    | general" es lo que aplica cuando no es deducible como colegiatura.
    */
    'uso_cfdi_default' => env('CFDI_USO_DEFAULT', 'D10'),

];
