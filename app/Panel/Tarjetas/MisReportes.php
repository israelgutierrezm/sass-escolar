<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Models\Reportes\EjecucionReporte;
use App\Models\Reportes\ReporteFavorito;
use App\Panel\TarjetaDeModulo;
use App\Panel\TarjetaPanel;
use App\Reportes\RegistroReportes;
use Illuminate\Support\Collection;

/**
 * Los reportes de esta persona: sus favoritos, y si no, los que más corre.
 *
 * ── Por qué no es un atajo a la sección ──────────────────────────────────
 * Un recuadro que sólo dijera «Reportes» sería el menú lateral otra vez, y este
 * proyecto ya retiró una tarjeta por eso mismo: «Accesos directos» eran doce
 * recuadros idénticos, o sea el menú con una cifra al lado.
 *
 * Lo que la justifica es llevar a la pregunta CONCRETA que esta persona hace: de
 * treinta y cuatro reportes, cada quien abre dos o tres, y llegar a ellos son
 * hoy dos clics y un vistazo a nueve áreas.
 *
 * ── Los FAVORITOS primero, y si no hay, lo que se corre ──────────────────
 * Un favorito es una elección explícita y gana siempre. Sin ninguno, la lista
 * sale de la bitácora —lo que esta persona abrió más veces en los últimos
 * noventa días—, que es una respuesta útil desde el primer día y sin pedirle a
 * nadie que configure nada.
 *
 * Y esto es posible porque la bitácora dejó de contar clics: desde que los
 * repintados se deduplican, «lo que más corres» significa las preguntas que más
 * haces y no las veces que recargaste la pestaña.
 *
 * ── Vacía se calla ───────────────────────────────────────────────────────
 * Quien no ha abierto ningún reporte no tiene nada que continuar, y una tarjeta
 * vacía ocupa el sitio de otra que sí pide trabajo. Es la regla de vacíos del
 * proyecto: una COLA vacía se oculta.
 */
class MisReportes implements TarjetaDeModulo, TarjetaPanel
{
    /** Cuántos caben sin volverse un menú. */
    private const A_LA_VISTA = 5;

    /**
     * Cuánto atrás se mira para «lo que más corres».
     *
     * Noventa días: lo bastante para cubrir un corte del semestre —que es
     * cuando se sacan los reportes— y no tanto como para seguir ofreciendo lo
     * del ciclo pasado.
     */
    private const DIAS = 90;

    public function __construct(private readonly RegistroReportes $registro) {}

    public function modulo(): string
    {
        return 'reportes';
    }

    public function clave(): string
    {
        return 'mis-reportes';
    }

    public function titulo(): string
    {
        return 'Mis reportes';
    }

    public function permiso(): ?string
    {
        return 'ver-reportes';
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
        return 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        if ($usuario->persona_id === null) {
            return null;
        }

        /*
         * Qué reportes ve esta persona lo dice el REGISTRO, no esta tarjeta.
         *
         * `RegistroReportes::para()` ya filtra por las tres cosas —permiso de la
         * fuente, módulo apagable y faceta del rol activo— y reescribir aquí ese
         * criterio es como se llega a que el panel ofrezca lo que la sección
         * niega. Se resuelve UNA vez y se compara por clave.
         */
        $alcanzables = collect($this->registro->para($usuario))
            ->map(fn ($definicion) => $definicion->clave())
            ->flip();

        if ($alcanzables->isEmpty()) {
            return null;
        }

        $renglones = $this->favoritos($usuario, $alcanzables);
        $pie = 'Tus favoritos';

        if ($renglones === []) {
            $renglones = $this->losQueMasCorre($usuario, $alcanzables);
            $pie = 'Lo que más consultas';
        }

        if ($renglones === []) {
            return null;
        }

        return [
            'renglones' => $renglones,
            'pie' => $pie,
            'enlace' => '/reportes',
        ];
    }

    /**
     * Los favoritos.
     *
     * **Sin vista, y no por descuido**: el plan preveía que un favorito apuntara
     * a una VISTA guardada —«la cartera con mis columnas» y no «la cartera»— y
     * `reportes_favoritos` se construyó sin esa columna. Aquí no se agrega:
     * tampoco hay forma de marcar un favorito CON vista desde la pantalla, así
     * que la columna nacería sin quien la escriba, que es lo que este proyecto
     * ya tuvo que retirar cinco veces. Queda anotado en el plan.
     *
     * @return array<int, array<string, mixed>>
     */
    private function favoritos(Usuario $usuario, Collection $alcanzables): array
    {
        return ReporteFavorito::query()
            ->where('persona_id', $usuario->persona_id)
            ->orderBy('id')
            ->limit(self::A_LA_VISTA)
            ->get(['id', 'reporte'])
            ->map(fn (ReporteFavorito $f) => $this->renglon($alcanzables, $f->reporte, null))
            /*
             * Un favorito de un reporte RETIRADO se descarta en vez de pintarse
             * como «Ya no existe»: en una cola de atajos, un renglón que no
             * lleva a ningún lado sólo ocupa sitio.
             */
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Lo que más ha corrido, de la bitácora.
     *
     * Se acota a esta PERSONA: «lo que más se usa en la escuela» es otra
     * pregunta y ya la contesta `/reportes/bitacora`, que además exige el
     * permiso de auditar. Aquí no hace falta ninguno: son las corridas propias.
     *
     * @return array<int, array<string, mixed>>
     */
    private function losQueMasCorre(Usuario $usuario, Collection $alcanzables): array
    {
        return EjecucionReporte::query()
            ->selectRaw('reporte, count(*) as veces')
            ->where('persona_id', $usuario->persona_id)
            ->where('created_at', '>=', now()->subDays(self::DIAS))
            ->groupBy('reporte')
            ->orderByDesc('veces')
            ->limit(self::A_LA_VISTA)
            ->get()
            ->map(fn ($fila) => $this->renglon($alcanzables, $fila->reporte, (int) $fila->veces))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Un renglón, o null si ese reporte ya no existe o esta persona no lo ve.
     *
     * @param  Collection<string, int>  $alcanzables
     * @return array<string, mixed>|null
     */
    private function renglon(
        Collection $alcanzables,
        string $clave,
        ?int $veces,
    ): ?array {
        /*
         * Fuera si ya no existe O si esta persona no lo alcanza HOY.
         *
         * Lo segundo importa tanto como lo primero: los permisos cambian, y la
         * bitácora conserva lo que alguien corrió cuando sí podía. Ofrecerle el
         * atajo lo llevaría a un 403 — y peor, le diría qué reportes existen.
         */
        if (! $alcanzables->has($clave)) {
            return null;
        }

        $definicion = $this->registro->todos()[$clave];
        $fuente = $this->registro->fuente($definicion->fuente());

        return [
            'etiqueta' => $definicion->titulo(),
            /*
             * La fuente sólo cuando dice algo NUEVO. «Cargos emitidos / Cargos
             * emitidos» es ruido; «Estado de cartera / Cartera por alumno» sí
             * informa, porque dice de dónde salen las filas.
             */
            'detalle' => $fuente->titulo() === $definicion->titulo() ? null : $fuente->titulo(),
            'valor' => $veces === null ? null : ($veces === 1 ? '1 vez' : "{$veces} veces"),
            'pie' => null,
            'progreso' => null,
            'alerta' => false,
            'enlace' => '/reportes/'.$clave,
        ];
    }
}
