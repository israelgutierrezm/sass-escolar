<?php

declare(strict_types=1);

namespace App\Services\Cfdi;

use App\Models\Finanzas\Factura;
use App\Models\Nomina\ReciboNomina;

/**
 * El proveedor autorizado de certificación (PAC) que timbra los CFDI.
 *
 * Es una interfaz y no una clase porque cada escuela contrata el suyo
 * —Facturama, SW Sapien, Finkok— y las tres APIs son distintas. El resto del
 * sistema no debe saber cuál está en uso: `TimbrarFactura` pide timbrar y
 * recibe un `ResultadoTimbrado`.
 *
 * NO se incluye todavía ninguna implementación real. Escribir un cliente de
 * Facturama sin credenciales para probarlo produciría código que parece
 * funcionar y que nadie ha visto responder: cuando la escuela contrate su PAC
 * se agrega la clase, se registra en `config/cfdi.php` y nada más cambia. Lo
 * que sí existe es `PacFalso`, que permite ejercer el flujo completo —cola,
 * reintentos, cancelación, sustitución— sin timbrar de verdad.
 */
interface Pac
{
    /** Nombre corto con el que queda registrado en `facturas.pac`. */
    public function nombre(): string;

    /**
     * Manda la factura a timbrar. NO debe lanzar excepciones por un rechazo
     * del SAT —eso es una respuesta legítima y va en el resultado—; sí puede
     * lanzarlas si la comunicación falla, para que la cola reintente.
     */
    public function timbrar(Factura $factura): ResultadoTimbrado;

    /**
     * Manda a timbrar un recibo de NÓMINA.
     *
     * Es otro tipo de comprobante —el complemento de nómina 1.2 tiene su propio
     * nodo, con percepciones, deducciones y datos del patrón— así que va aparte
     * y no reusando `timbrar()`. Quien implemente un PAC real arma el documento
     * a partir del recibo: las APIs comerciales reciben JSON, no XML, así que
     * construirlo aquí sería trabajo que cada driver tiraría.
     *
     * Mismas reglas que arriba: un rechazo del SAT es una respuesta y va en el
     * resultado; una falla de comunicación sí puede ser excepción.
     */
    public function timbrarNomina(ReciboNomina $recibo): ResultadoTimbrado;

    /**
     * Cancela un CFDI ya timbrado.
     *
     * `$motivo` es la clave del SAT (01..04) y `$uuidSustituta` solo aplica al
     * motivo 01, que exige decir qué comprobante lo reemplaza.
     */
    public function cancelar(Factura $factura, string $motivo, ?string $uuidSustituta = null): ResultadoTimbrado;

    /**
     * ¿Este proveedor puede decir qué opina el SAT de un comprobante?
     *
     * Se declara aparte en vez de deducirlo de la respuesta, porque las dos
     * cosas se parecen y significan lo contrario: «este PAC no consulta» es una
     * propiedad del driver que se dice UNA vez, y «el PAC no contestó» es un
     * fallo que hay que reportar factura por factura. Sin separarlas, correr la
     * conciliación en modo de prueba llenaría el informe de errores que no lo
     * son.
     */
    public function puedeConciliar(): bool;

    /**
     * Qué dice el SAT de este comprobante.
     *
     * Un comprobante vive en dos sitios y se separan solos: alguien cancela
     * desde el portal del PAC, o una cancelación pedida aquí se queda esperando
     * que el receptor la acepte. Ninguna de las dos falla ni avisa.
     *
     * Mismas reglas que el resto: que el SAT diga «cancelada» es una respuesta
     * legítima y va en el resultado; una falla de comunicación se devuelve como
     * `desconocido` y NO como si estuviera vigente.
     */
    public function consultarEstado(Factura $factura): EstadoEnElPac;
}
