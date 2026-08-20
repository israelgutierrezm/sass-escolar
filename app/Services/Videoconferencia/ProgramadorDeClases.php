<?php

declare(strict_types=1);

namespace App\Services\Videoconferencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\ControlEscolar\AsignaturaGrupo;
use App\Models\Lms\IntegracionVideo;
use App\Models\Lms\Videoconferencia;
use App\Support\ProveedoresVideoCatalogo;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Programar y cancelar una clase en línea.
 *
 * Junta las tres piezas en el único orden que funciona: se aparta la licencia,
 * se crea la sala en el proveedor y se guarda. Cada una puede fallar y el orden
 * decide qué queda cuando falla.
 *
 * ── La FILA es el apartado ─────────────────────────────────────────────────
 * Primero se inserta la clase sin enlaces, dentro de una transacción que bloquea
 * las cuentas candidatas (`lockForUpdate`); después se llama al proveedor; al
 * final se le ponen los enlaces.
 *
 * Se hizo así por la carrera que de otro modo existe: dos docentes programando a
 * las 9:00 al mismo tiempo consultan «¿hay licencia libre?», los dos leen que sí
 * —ninguno ha escrito todavía— y los dos se llevan la misma. La segunda clase
 * echaría a la primera de la sala, con el grupo dentro, y el registro no diría
 * nada raro. Con la fila apartada dentro del bloqueo, el segundo ya ve ocupada
 * la licencia.
 *
 * La llamada HTTP queda FUERA de la transacción a propósito: sostener un bloqueo
 * de base mientras se espera a Zoom es como se serializa toda la escuela detrás
 * de un servicio que a veces tarda cinco segundos.
 *
 * Si el proveedor falla, el apartado se retira. Y si la sala se creó pero no se
 * pudo guardar, se cancela allá: una reunión huérfana en Zoom ocupa la licencia
 * de las 9:00 para siempre y nadie sabe de dónde salió.
 */
class ProgramadorDeClases
{
    public function __construct(
        private readonly AsignadorDeCuenta $asignador,
        private readonly Proveedores $proveedores,
    ) {}

    /**
     * Programa una clase y devuelve la fila ya creada.
     *
     * @param  int|null  $quien  usuario que la programa (docente o control escolar)
     */
    public function programar(
        AsignaturaGrupo $materia,
        string $proveedor,
        string $titulo,
        CarbonInterface $inicio,
        int $minutos,
        ?int $quien = null,
    ): Videoconferencia {
        AvisoParaElUsuario::aMenosQue(
            ProveedoresVideoCatalogo::existe($proveedor),
            422,
            'Ese proveedor de clase en línea no existe.',
        );

        // El pasado no se programa. Se comprueba aquí y no sólo en el
        // formulario: el proveedor aceptaría la fecha y crearía una reunión que
        // nadie va a usar.
        AvisoParaElUsuario::si(
            $inicio->isPast(),
            422,
            'Esa hora ya pasó. Programa la clase para un momento futuro.',
        );

        $fin = $inicio->clone()->addMinutes($minutos);
        $integracion = IntegracionVideo::para($proveedor);

        // 1. Apartar: dentro del bloqueo, para que dos docentes simultáneos no
        //    se lleven la misma licencia.
        $sesion = DB::transaction(function () use ($materia, $proveedor, $titulo, $inicio, $fin, $quien) {
            /*
             * El bloqueo va sobre las cuentas del proveedor. Es lo que serializa
             * a dos peticiones que preguntan por la misma licencia: la segunda
             * espera aquí y cuando entra ya ve la fila que apartó la primera.
             */
            DB::table('cuentas_videoconferencia')
                ->where('proveedor', $proveedor)
                ->where('activa', true)
                ->lockForUpdate()
                ->get();

            $cuenta = $this->asignador->libre($proveedor, $inicio, $fin);

            if ($cuenta === null) {
                $this->explicarPorQueNoHayCuenta($proveedor, $inicio, $fin);
            }

            // Sin enlaces todavía: es un apartado, no una clase utilizable. El
            // alumno no la ve porque `abiertaPara` exige `url_join`.
            return Videoconferencia::create([
                'asignatura_grupo_id' => $materia->id,
                'cuenta_id' => $cuenta->id,
                'proveedor' => $proveedor,
                'titulo' => $titulo,
                'inicio' => $inicio,
                'fin' => $fin,
                'estado' => Videoconferencia::PROGRAMADA,
                'programada_por' => $quien,
            ]);
        });

        // 2. Crear la sala, ya fuera del bloqueo.
        try {
            $sala = $this->proveedores->para($integracion)->crear(
                $sesion->cuenta,
                $titulo,
                $inicio,
                $minutos,
            );
        } catch (\Throwable $e) {
            // El apartado no llegó a ser clase: se suelta la licencia.
            $sesion->forceDelete();

            throw $e;
        }

        // 3. Ponerle los enlaces. A partir de aquí sí es una clase.
        try {
            $sesion->update([
                'meeting_id' => $sala->meetingId,
                'url_join' => $sala->urlInvitado,
                'url_anfitrion' => $sala->urlAnfitrion,
            ]);
        } catch (\Throwable $e) {
            /*
             * La sala existe del otro lado y aquí no quedó quién la apunte. Si
             * no se retira, esa licencia queda ocupada a esa hora para siempre
             * por una reunión que ningún alumno va a abrir.
             */
            $this->cancelarHuerfana($integracion, $sala->meetingId, $sesion->cuenta_id, $proveedor);
            $sesion->forceDelete();

            throw $e;
        }

        return $sesion->fresh();
    }

    /**
     * Cancela la clase: primero del lado del proveedor, luego aquí.
     *
     * En ese orden a propósito. Marcarla cancelada aquí y fallar al retirarla de
     * Zoom dejaría una sala viva y abierta, a la que entra quien haya guardado el
     * enlace — y ese alumno ve una clase que para la escuela no existe.
     */
    public function cancelar(Videoconferencia $sesion): void
    {
        if ($sesion->estaCancelada()) {
            return;
        }

        $this->proveedores
            ->para(IntegracionVideo::para($sesion->proveedor))
            ->cancelar($sesion);

        $sesion->update([
            'estado' => Videoconferencia::CANCELADA,
            // El enlace deja de servir para nadie: se retira para que ninguna
            // pantalla lo pueda pintar por descuido.
            'url_join' => null,
            'url_anfitrion' => null,
        ]);
    }

    /**
     * Por qué no hay cuenta, dicho de forma accionable.
     *
     * «No hay licencias» no dice qué hacer. Los tres casos piden cosas
     * distintas: cargar cuentas, encender el proveedor, o mover la clase de
     * hora. Distinguirlos es la diferencia entre resolverlo y escribir soporte.
     */
    private function explicarPorQueNoHayCuenta(string $proveedor, CarbonInterface $inicio, CarbonInterface $fin): never
    {
        $nombre = ProveedoresVideoCatalogo::uno($proveedor)['nombre'] ?? $proveedor;
        $ocupacion = $this->asignador->ocupacion($proveedor, $inicio, $fin);

        if ($ocupacion['total'] === 0) {
            AvisoParaElUsuario::lanzar(
                422,
                "No hay ninguna cuenta de {$nombre} cargada. Se agregan en Plataforma › Clases en línea.",
            );
        }

        $cuantas = $ocupacion['total'];
        $plural = $cuantas === 1 ? 'la única cuenta' : "las {$cuantas} cuentas";

        AvisoParaElUsuario::lanzar(
            422,
            "A esa hora {$plural} de {$nombre} ya están ocupadas ("
            .$inicio->format('H:i').' a '.$fin->format('H:i').'). '
            .'Mueve la clase de horario, o agrega otra licencia en Plataforma › Clases en línea.',
        );
    }

    /**
     * Retira del proveedor una sala que aquí no llegó a guardarse.
     *
     * Se traga sus propios errores: ya venimos de una excepción y lo que importa
     * es que salga la de verdad, no una de limpieza que la tape. Queda en el
     * registro para que se pueda ir a borrar a mano si el proveedor no cooperó.
     */
    private function cancelarHuerfana(IntegracionVideo $integracion, string $meetingId, int $cuentaId, string $proveedor): void
    {
        try {
            $fantasma = new Videoconferencia([
                'proveedor' => $proveedor,
                'meeting_id' => $meetingId,
                'cuenta_id' => $cuentaId,
            ]);

            $this->proveedores->para($integracion)->cancelar($fantasma);
        } catch (\Throwable $limpieza) {
            Log::warning('Quedó una reunión huérfana en el proveedor', [
                'proveedor' => $proveedor,
                'meeting_id' => $meetingId,
                'motivo' => $limpieza->getMessage(),
            ]);
        }
    }
}
