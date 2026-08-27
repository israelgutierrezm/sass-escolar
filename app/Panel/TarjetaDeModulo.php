<?php

declare(strict_types=1);

namespace App\Panel;

/**
 * Una tarjeta que sólo existe si su MÓDULO está encendido.
 *
 * ── El defecto que esto cierra ───────────────────────────────────────────
 * `RegistroTarjetas::para()` filtraba por permiso y por el apagado del rol, y
 * **no miraba el módulo**. Medido sobre el demo, con una postulación sembrada:
 * apagar `bolsa_trabajo` en `/plataforma/modulos` dejaba «Postulantes en
 * proceso» en el panel, con su enlace a `/bolsa/vacantes` — que la ruta sí
 * comprueba, así que el enlace daba 404.
 *
 * Es exactamente la lección que este proyecto ya escribió para el menú lateral:
 * «apagar un MÓDULO dejaba su entrada en la barra dando 404, porque la RUTA
 * comprobaba el módulo y el menú no». Allí se corrigió en el constructor del
 * menú, no enseñándole a cada sección a comprobarse sola.
 *
 * ── Por qué es una interfaz aparte y no un método de `TarjetaPanel` ──────
 * Porque la mayoría de las 31 tarjetas no dependen de ningún módulo apagable, y
 * obligarlas a escribir `return null` sería 28 archivos de ruido para expresar
 * «esto no me aplica».
 *
 * ── La trampa al declararlo ──────────────────────────────────────────────
 * **Sólo lo declara la tarjeta cuya sección ya está gateada por `modulo:`.** Los
 * módulos NÚCLEO —academico, control_escolar, finanzas, admisiones, familia,
 * lms…— figuran como APAGADOS en el demo porque no tienen fila en
 * `modulos_activos` y `ModulosDeLaEscuela` falla cerrado. Ponerle `modulo()` a
 * una tarjeta de finanzas la haría desaparecer de golpe.
 */
interface TarjetaDeModulo
{
    /** La clave del módulo apagable del que depende. */
    public function modulo(): string;
}
