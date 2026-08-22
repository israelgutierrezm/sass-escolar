<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\ControlEscolar\Tutoria;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use App\Services\EstadoDelAlumno;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A quiénes acompaña el tutor educativo, y a cuál hay que buscar.
 *
 * ── Lo financiero NO sale aquí, y es una decisión, no un olvido ───────────
 * `TutoriaController` lo dice en su docblock y lo hace efectivo llamando al
 * estado del alumno con `finanzas: false`: un tutor educativo acompaña el
 * avance, y lo que un alumno deba es asunto de su familia y de la escuela.
 * Ponerlo en el panel abriría por la puerta de atrás el dato que la pantalla le
 * niega.
 *
 * ── Ni inasistencias ──────────────────────────────────────────────────────
 * No existe un criterio compartido de «inasistencia relevante»: la rejilla mide
 * por mes y por curso, y el estado del alumno no lo calcula. Inventarlo aquí
 * sería un tercer criterio que ninguna pantalla respalda. Las señales que sí se
 * sostienen son las que ya usa `/mis-tutorados`.
 *
 * ── Y no se oculta cuando «no hay pendientes» ─────────────────────────────
 * Es su cartera de acompañamiento, no una cola que otro le llena. Lo decisivo:
 * la señal más valiosa del tutor —«a éste no lo he visto en tres meses»— nunca
 * genera un pendiente, así que una tarjeta que desapareciera al no haber nada
 * urgente escondería justo lo que hay que mirar.
 */
class MisTutorados implements TarjetaPanel
{
    /** Cuántos caben antes de que la tarjeta se vuelva un listado. */
    private const A_LA_VISTA = 5;

    /** A partir de cuántos días sin sesión se cuenta como «sin ver». */
    private const DIAS_SIN_VER = 60;

    public function __construct(private readonly EstadoDelAlumno $estado) {}

    public function clave(): string
    {
        return 'mis-tutorados';
    }

    public function titulo(): string
    {
        return 'Mis tutorados';
    }

    public function permiso(): ?string
    {
        return 'ver-mis-tutorados';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        if ($usuario->persona_id === null) {
            return null;
        }

        /*
         * El alcance sale del scope que ya existe —tutor y vínculo activo—, que
         * a propósito NO filtra por ciclo: una escuela que no haya capturado
         * ciclos dejaría al tutor sin nadie a la vista.
         */
        $tutorias = Tutoria::query()
            ->de((int) $usuario->persona_id)
            ->with('alumno')
            ->get()
            ->filter(fn (Tutoria $t) => $t->alumno !== null);

        if ($tutorias->isEmpty()) {
            return null;
        }

        $sesiones = $this->sesionesPorTutoria($tutorias->pluck('id')->all());

        $filas = $this->ordenar($tutorias->map(
            fn (Tutoria $t) => $this->fila($t, $sesiones->get($t->id))
        ));

        return [
            'renglones' => $filas->take(self::A_LA_VISTA)->map(fn (array $f) => [
                'etiqueta' => $f['nombre'],
                'valor' => $this->senal($f),
                'detalle' => null,
                'pie' => $this->cuandoSeLeVio($f),
                'progreso' => null,
                /*
                 * En rojo sólo lo reprobado. «Nunca lo has visto» al empezar el
                 * ciclo es lo normal, y pintarlo de alarma el primer día enseña
                 * a ignorar el color; el aviso va en el pie, que informa sin
                 * gritar.
                 */
                'alerta' => $f['reprobadas'] > 0,
                'enlace' => "/mis-tutorados/{$f['id']}",
            ])->values()->all(),
            'pie' => $this->resumen($filas),
            'enlace' => '/mis-tutorados',
        ];
    }

    /**
     * Cuántas sesiones y cuándo fue la última, de todas las tutorías de golpe.
     *
     * @param  array<int, int>  $tutorias
     */
    private function sesionesPorTutoria(array $tutorias): Collection
    {
        return DB::table('sesiones_tutoria')
            ->whereIn('tutoria_id', $tutorias)
            // Consulta a pelo: aquí el borrado lógico se filtra a mano porque no
            // pasa por el modelo.
            ->whereNull('deleted_at')
            ->groupBy('tutoria_id')
            ->select('tutoria_id', DB::raw('COUNT(*) as cuantas'), DB::raw('MAX(fecha) as ultima'))
            ->get()
            ->keyBy('tutoria_id');
    }

    /** @return array<string, mixed> */
    private function fila(Tutoria $tutoria, ?object $sesiones): array
    {
        $estado = $this->estado->de($tutoria->alumno, academico: true, finanzas: false);
        $ultima = $sesiones->ultima ?? null;

        return [
            'nombre' => $tutoria->alumno->nombreCompleto(),
            'id' => $tutoria->alumno->id,
            'reprobadas' => $estado['reprobadas'] ?? 0,
            'promedio' => $estado['promedio'],
            'sesiones' => (int) ($sesiones->cuantas ?? 0),
            'dias' => $ultima === null
                ? null
                : (int) (now()->startOfDay()->diffInDays($ultima, false) * -1),
        ];
    }

    /**
     * El MISMO orden que `/mis-tutorados`.
     *
     * Copiarlo importa: si el panel y la pantalla ordenaran distinto, «el
     * primero de la lista» sería otra persona en cada sitio. El `?? 99` evita
     * que quien todavía no tiene promedio encabece por delante del que va mal.
     */
    private function ordenar(Collection $filas): Collection
    {
        return $filas->sortBy([
            fn (array $a, array $b) => $b['reprobadas'] <=> $a['reprobadas'],
            fn (array $a, array $b) => ($a['promedio'] ?? 99) <=> ($b['promedio'] ?? 99),
        ])->values();
    }

    /** @param  array<string, mixed>  $fila */
    private function senal(array $fila): string
    {
        if ($fila['reprobadas'] > 0) {
            return $fila['reprobadas'] === 1 ? '1 reprobada' : $fila['reprobadas'].' reprobadas';
        }

        return $fila['promedio'] !== null ? 'Promedio '.$fila['promedio'] : 'Sin calificaciones';
    }

    /** @param  array<string, mixed>  $fila */
    private function cuandoSeLeVio(array $fila): ?string
    {
        if ($fila['sesiones'] === 0) {
            return 'nunca lo has visto';
        }

        return $fila['dias'] === null ? null : "última sesión hace {$fila['dias']} días";
    }

    /**
     * Cuántos son y cuántos reclaman algo.
     *
     * «Reprobando» y «sin ver» son los mismos criterios que el resumen de la
     * pantalla. Se dejó fuera a propósito su banda «en riesgo» (promedio entre 6
     * y 8): tal como está escrita deja fuera a quien va por debajo de 6 sin
     * reprobadas todavía, y repetir ese hueco aquí lo volvería doctrina.
     */
    private function resumen(Collection $filas): string
    {
        $reprobando = $filas->filter(fn (array $f) => $f['reprobadas'] > 0)->count();
        $sinVer = $filas->filter(
            fn (array $f) => $f['sesiones'] === 0 || ($f['dias'] ?? 0) > self::DIAS_SIN_VER
        )->count();

        $piezas = [$filas->count() === 1 ? '1 tutorado' : $filas->count().' tutorados'];

        if ($reprobando > 0) {
            $piezas[] = $reprobando.' reprobando';
        }

        if ($sinVer > 0) {
            $piezas[] = $sinVer.' sin ver';
        }

        return implode(' · ', $piezas);
    }
}
