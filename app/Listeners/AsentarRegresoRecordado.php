<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Identidad\Usuario;
use App\Services\IniciadorSesion;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;

/**
 * Cuando alguien vuelve por la cookie de «recuérdame», también ENTRÓ.
 *
 * ── El hueco que cierra ────────────────────────────────────────────────────
 * La bandera de «recuérdame» siempre funcionó para autenticar: la cookie sale,
 * dura 400 días y sola devuelve la sesión. Lo que no pasaba es que el regreso
 * NO cruza el controlador de login —lo resuelve el guard al pedir
 * `Auth::user()`—, así que `IniciadorSesion::finalizar()` no corría y con él se
 * perdían dos cosas:
 *
 *  - `usuarios.conectado` se quedaba en falso, así que quien mirara quién está
 *    dentro no lo veía.
 *  - **La entrada no se asentaba en `bitacora_accesos`.** Ése es el grave: el
 *    registro que existe para saber quién entró mentía POR OMISIÓN, y no de
 *    forma llamativa —no faltaba una pantalla ni fallaba nada—: simplemente
 *    quien tuviera la casilla marcada dejaba de aparecer.
 *
 * ── Por qué `viaRemember()` y no `$evento->remember` ──────────────────────
 * El evento `Login` se dispara en los DOS caminos con `remember = true` —el
 * login normal con la casilla marcada, y el regreso por la cookie—, así que
 * mirando la bandera del evento se asentarían DOS entradas por cada login con
 * «recuérdame»: la del controlador y la de aquí.
 *
 * Lo que sí los separa es `viaRemember()`, que el guard pone sólo al resolver
 * al usuario desde el recaller. **Medido antes de escribir esto**: en el login
 * normal sale `false` y en el regreso por la cookie, `true`.
 *
 * ── Y por qué no se asienta desde el guard para TODO login ────────────────
 * Sería lo más limpio en abstracto —una sola puerta— y está mal aquí:
 * `Auth::login()` lo llaman también `Suplantador::iniciar` y `::terminar`, que
 * NO son entradas y ya tienen su propio rastro (`suplantacion_inicio`). Con un
 * oyente sin discriminar, suplantar a alguien escribiría una entrada suya en la
 * bitácora, y esa fila diría que esa persona inició sesión cuando no lo hizo.
 */
class AsentarRegresoRecordado
{
    public function __construct(private readonly IniciadorSesion $iniciador) {}

    public function handle(Login $evento): void
    {
        $usuario = $evento->user;

        /*
         * Sólo cuentas de una ESCUELA. La central tiene su propio guard, su
         * propio modelo y su propia bitácora, y una entrada suya aquí caería en
         * la base equivocada.
         *
         * Se comprueba por el MODELO y no por el nombre del guard, que sería
         * una segunda forma de decir lo mismo: hoy `web` es el único respaldado
         * por `Usuario` —medido sobre `config('auth')`— así que las dos
         * condiciones no pueden discrepar, y dos formas de una misma regla es
         * como se llega a que una se quede vieja. Es la lección de
         * `$diseno->exists`.
         */
        if (! $usuario instanceof Usuario) {
            return;
        }

        /*
         * Sólo el regreso por la COOKIE. El login normal ya lo asienta su
         * controlador, y sin esta guarda cada acceso con «recuérdame» marcado
         * dejaría dos filas.
         *
         * Se le pregunta al guard DEL EVENTO y no a «web» a secas: si algún día
         * hay un segundo guard con cuentas de escuela, preguntar siempre por
         * `web` leería el estado de otra sesión — y ese defecto no daría error,
         * sólo asentaría de más o de menos.
         */
        if (! Auth::guard($evento->guard)->viaRemember()) {
            return;
        }

        /*
         * NO se filtra por «esto es HTTP», y se probó a hacerlo.
         *
         * La idea era evitar filas nacidas de una consola o de un trabajador de
         * la cola. Pero para llegar aquí hace falta una petición con la cookie
         * del recuerdo, y eso no lo produce ni un comando ni un job: la guarda
         * no protegía de ningún caso real y a cambio dejaba INTESTABLE lo único
         * que este oyente existe para hacer —las suites de este proyecto corren
         * por consola—. Una salvaguarda que no salva nada y esconde el defecto
         * es peor que no tenerla.
         */
        /*
         * Se anota CÓMO volvió. Es la misma entrada —alguien entró, y las cifras
         * del panel tienen que contarla— pero no es el mismo hecho: aquí nadie
         * escribió una contraseña, y para quien audita eso es justo lo que
         * quiere poder distinguir en una sesión que alguien dejó abierta.
         */
        $this->iniciador->finalizar($usuario, request(), ['via' => 'recordado']);
    }
}
