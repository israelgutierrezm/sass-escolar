<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\AccesoCaso;
use App\Models\Permanencia\CasoPermanencia;
use App\Models\Permanencia\Intervencion;
use App\Models\Permanencia\TipoIntervencion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registrar lo que se hizo, y decidir quién lo puede leer.
 *
 * ── Las tres cosas que este servicio garantiza ─────────────────────────────
 *  1. Que lo que el TIPO exige esté capturado —evidencia, acuerdos, próxima
 *     fecha—, porque un contacto sin acuerdos deja al siguiente que abra el caso
 *     sin saber qué se dijo.
 *  2. Que sólo se marque reservado lo que su tipo permite: la reserva es para lo
 *     que de verdad la pide, y ofrecerla en todas la convierte en una casilla
 *     que se palomea por costumbre.
 *  3. Que lo que alguien no alcanza **no viaje**. Se filtra aquí, en el
 *     servidor: esconderlo con un `v-if` deja el dato en la respuesta.
 */
class RegistroDeIntervenciones
{
    public function __construct(
        private readonly AlcanceDeCasos $alcance,
        private readonly TransicionDeCaso $transiciones,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function registrar(CasoPermanencia $caso, array $datos, ?Usuario $quien): Intervencion
    {
        AvisoParaElUsuario::aMenosQue(
            $quien?->can('registrar-intervenciones') === true,
            403,
            'Tu rol no puede registrar intervenciones.',
        );

        $this->alcance->exigirQueAlcance($caso, $quien);

        AvisoParaElUsuario::si(
            $caso->estado->esTerminal(),
            422,
            'El caso está cerrado. Para anotar algo más hay que reabrirlo, y eso deja constancia.',
        );

        $tipo = TipoIntervencion::query()->findOrFail($datos['tipo_intervencion_id']);

        $this->exigirLoQueElTipoPide($tipo, $datos);

        /*
         * La reserva sólo donde el tipo la permite. Sin esta guarda, la pantalla
         * sería la única defensa — y una petición a mano la rodea.
         */
        $visibilidad = $datos['visibilidad'] ?? Intervencion::VISIBLE_CASO;

        AvisoParaElUsuario::si(
            $visibilidad === Intervencion::RESERVADA && ! $tipo->permite_reservada,
            422,
            'Este tipo de intervención no admite marcarse como reservada: en «'.$tipo->nombre
            .'» no hay nada personal que proteger, y esconderlo de su equipo le quita el dato que necesita.',
        );

        return DB::transaction(function () use ($caso, $datos, $tipo, $visibilidad, $quien) {
            $intervencion = Intervencion::create([
                'caso_id' => $caso->id,
                'tipo_intervencion_id' => $tipo->id,
                'objetivo' => $datos['objetivo'] ?? null,
                'responsable_id' => $datos['responsable_id'] ?? $quien?->id,
                'fecha' => $datos['fecha'],
                'canal' => $datos['canal'] ?? null,
                'participantes' => $datos['participantes'] ?? null,
                'acuerdos' => $datos['acuerdos'] ?? null,
                'proxima_fecha' => $datos['proxima_fecha'] ?? null,
                'resultado' => $datos['resultado'] ?? null,
                'estado' => $datos['estado'] ?? Intervencion::REALIZADA,
                'visibilidad' => $visibilidad,
                'evidencia_ruta' => $datos['evidencia_ruta'] ?? null,
                'evidencia_nombre' => $datos['evidencia_nombre'] ?? null,
            ]);

            /*
             * Una intervención REALIZADA marca el primer contacto, si no lo
             * había. Una PROGRAMADA no: agendar una cita no es haber hablado con
             * nadie, y contarla arruinaría el indicador de «cuánto tardamos»
             * —que es el que dice si esto sirve—.
             */
            if ($intervencion->estado === Intervencion::REALIZADA) {
                $this->transiciones->anotarPrimerContacto($caso);
            }

            return $intervencion;
        });
    }

    /**
     * Lo que el TIPO exige, comprobado aquí y no en cada formulario.
     *
     * Repartido por las pantallas, la que alguien agregue el mes que viene
     * dejará registrar una canalización sin oficio. Las banderas son del
     * catálogo, así que una escuela que invente un tipo se comporta igual.
     *
     * @param  array<string, mixed>  $datos
     */
    private function exigirLoQueElTipoPide(TipoIntervencion $tipo, array $datos): void
    {
        AvisoParaElUsuario::si(
            $tipo->exige_acuerdos && trim((string) ($datos['acuerdos'] ?? '')) === '',
            422,
            '«'.$tipo->nombre.'» pide escribir a qué se llegó: sin eso, quien abra el caso después no '
            .'sabrá qué se dijo.',
        );

        AvisoParaElUsuario::si(
            $tipo->exige_proxima_fecha && ($datos['proxima_fecha'] ?? null) === null,
            422,
            '«'.$tipo->nombre.'» pide la fecha del siguiente paso: lo que no la tiene se queda esperando '
            .'a que alguien se acuerde.',
        );

        AvisoParaElUsuario::si(
            $tipo->exige_evidencia && ($datos['evidencia_ruta'] ?? null) === null,
            422,
            '«'.$tipo->nombre.'» pide adjuntar el documento: sin él es una intención, no un hecho.',
        );
    }

    /**
     * Las intervenciones que esta persona puede ver, y el conteo de las ocultas.
     *
     * Devuelve las DOS cosas porque la bitácora de consulta necesita saber
     * cuántas quedaron reservadas —es lo que dice cuánto NO se le mostró a quien
     * miró— y porque la pantalla tiene que poder decir «hay 3 notas reservadas
     * que tu rol no alcanza» en vez de esconderlas en silencio: callarlas haría
     * creer que el caso está vacío.
     *
     * @return array{visibles: Collection<int, Intervencion>, ocultas: int}
     */
    public function paraLeer(CasoPermanencia $caso, ?Usuario $usuario): array
    {
        $equipo = $this->personasDelEquipo($caso);

        $todas = $caso->intervenciones()->with('tipo', 'responsable.persona')->get();

        $visibles = $todas->filter(fn (Intervencion $i) => $i->laPuedeVer($usuario, $equipo));

        return [
            'visibles' => $visibles->values(),
            'ocultas' => $todas->count() - $visibles->count(),
        ];
    }

    /**
     * Deja constancia de que alguien abrió este caso.
     *
     * Se cuenta la CONSULTA, no el contenido: una auditoría que copie lo
     * vigilado multiplica el problema que intenta resolver. Calcado de
     * `AsignacionTutoriaController`.
     */
    public function registrarConsulta(CasoPermanencia $caso, ?Usuario $usuario, ?string $ip, int $vistas, int $ocultas): void
    {
        AccesoCaso::create([
            'caso_id' => $caso->id,
            'persona_id' => $usuario?->persona_id,
            'intervenciones_vistas' => $vistas,
            'reservadas_ocultas' => $ocultas,
            'ip' => $ip,
            'creado_en' => now(),
        ]);
    }

    /**
     * Las personas del equipo VIGENTE, más el responsable.
     *
     * El responsable entra aunque no esté en la tabla: es quien lleva el caso, y
     * dejarlo fuera haría que no viera sus propias notas de equipo.
     *
     * @return array<int, int>
     */
    public function personasDelEquipo(CasoPermanencia $caso): array
    {
        $equipo = $caso->equipo()->vigentes()->pluck('persona_id')->all();

        $responsable = $caso->responsable?->persona_id;

        return $responsable === null ? $equipo : array_values(array_unique([...$equipo, $responsable]));
    }
}
