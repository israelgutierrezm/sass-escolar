<?php

declare(strict_types=1);

namespace App\Services\Videoconferencia;

use App\Models\Lms\CuentaVideo;
use App\Models\Lms\IntegracionVideo;
use App\Models\Lms\Videoconferencia;
use App\Support\ProveedoresVideoCatalogo;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A qué cuenta le toca sostener esta clase.
 *
 * ── El problema que resuelve ───────────────────────────────────────────────
 * El cliente pidió poder cargar tantas licencias como haga falta «porque pueden
 * existir múltiples clases simultáneas». Eso no es una lista de cuentas: es un
 * problema de reparto con una restricción de horario, y si se resuelve mal el
 * síntoma es el peor posible —dos clases arrancan bien y a los diez minutos una
 * echa a la otra de la sala, con cuarenta alumnos dentro—.
 *
 * ── Dos proveedores, dos reglas ────────────────────────────────────────────
 * - Donde una cuenta sólo aguanta UNA reunión a la vez (Zoom), se busca una que
 *   no tenga nada traslapado con la ventana pedida. Si no hay, se dice que no
 *   hay: crear la reunión igual sería vender dos veces el mismo asiento.
 * - Donde no hay tal límite (Meet), cualquiera activa sirve, y se elige la menos
 *   cargada nada más por repartir parejo.
 *
 * Lo declara `ProveedoresVideoCatalogo` y aquí sólo se pregunta. Poner el `if`
 * con el nombre del proveedor escrito a mano habría dejado al tercero que entre
 * comportándose como Zoom sin que nadie lo decidiera.
 *
 * ── Por qué el traslape se pregunta a la BASE y no a un contador ───────────
 * Mismo motivo que en el reparto de asesores: un contador se desincroniza en
 * cuanto alguien cancela, reprograma o se cae una petición a la mitad, y a
 * partir de ahí reparte mal para siempre sin que nadie lo note. Las clases ya
 * programadas son la verdad, y están en una tabla.
 */
class AsignadorDeCuenta
{
    /**
     * La cuenta libre para esa ventana, o null si no hay ninguna.
     *
     * Devolver null NO es un fallo del sistema: es la respuesta correcta cuando
     * la escuela tiene tres licencias y ya hay tres clases a esa hora. Quien
     * llama decide cómo decirlo — `ProgramadorDeClases` lo explica con la cuenta
     * de cuántas hay y cuántas están ocupadas, que es lo accionable.
     *
     * @param  int|null  $excepto  la clase que se está reprogramando, para que
     *                             no choque contra sí misma.
     */
    public function libre(
        string $proveedor,
        CarbonInterface $inicio,
        CarbonInterface $fin,
        ?int $excepto = null,
    ): ?CuentaVideo {
        $candidatas = CuentaVideo::query()
            ->de($proveedor)
            ->activas()
            // Estable: con dos cuentas igual de libres, el orden no puede ser
            // aleatorio o el reparto sería impredecible entre corridas.
            ->orderBy('id')
            ->get();

        if ($candidatas->isEmpty()) {
            return null;
        }

        if (! ProveedoresVideoCatalogo::unaReunionPorCuenta($proveedor)) {
            // Sin límite por cuenta: reparte por carga, sólo por equidad.
            return $this->laMenosCargada($candidatas);
        }

        foreach ($candidatas as $cuenta) {
            if ($cuenta->libreEntre($inicio->toDateTimeString(), $fin->toDateTimeString(), $excepto)) {
                return $cuenta;
            }
        }

        return null;
    }

    /**
     * Cuántas cuentas hay y cuántas están ocupadas en esa ventana.
     *
     * Sirve para el mensaje: «no hay licencias libres» no dice qué hacer, y
     * «tus 3 licencias están ocupadas de 9:00 a 11:00» sí — o se mueve la clase,
     * o se compra otra licencia.
     *
     * @return array{total: int, ocupadas: int}
     */
    public function ocupacion(
        string $proveedor,
        CarbonInterface $inicio,
        CarbonInterface $fin,
        ?int $excepto = null,
    ): array {
        $activas = CuentaVideo::query()->de($proveedor)->activas()->get();

        if (! ProveedoresVideoCatalogo::unaReunionPorCuenta($proveedor)) {
            // Sin límite, ninguna se ocupa por estar en uso.
            return ['total' => $activas->count(), 'ocupadas' => 0];
        }

        $ocupadas = $activas->filter(
            fn (CuentaVideo $c) => ! $c->libreEntre($inicio->toDateTimeString(), $fin->toDateTimeString(), $excepto),
        )->count();

        return ['total' => $activas->count(), 'ocupadas' => $ocupadas];
    }

    /** Los proveedores encendidos Y con credenciales completas. */
    public function disponibles(): array
    {
        return IntegracionVideo::query()
            ->get()
            ->filter(fn (IntegracionVideo $i) => $i->operativa())
            // Y con al menos una cuenta activa: encendido sin anfitriones no
            // puede dar una sola clase, y ofrecerlo sería un callejón.
            ->filter(fn (IntegracionVideo $i) => CuentaVideo::query()->de($i->clave)->activas()->exists())
            ->pluck('clave')
            ->values()
            ->all();
    }

    /**
     * La que menos clases futuras tiene.
     *
     * Se cuentan las que vienen y no las históricas: repartir por el total de
     * siempre castigaría a la cuenta que lleva más tiempo dada de alta, que es
     * exactamente al revés de lo que hace falta.
     *
     * @param  Collection<int, CuentaVideo>  $candidatas
     */
    private function laMenosCargada($candidatas): CuentaVideo
    {
        $cargas = DB::table('videoconferencias')
            ->whereIn('cuenta_id', $candidatas->pluck('id'))
            ->whereIn('estado', [Videoconferencia::PROGRAMADA, Videoconferencia::EN_CURSO])
            ->where('fin', '>=', now())
            ->whereNull('deleted_at')
            ->groupBy('cuenta_id')
            ->pluck(DB::raw('COUNT(*) as total'), 'cuenta_id');

        return $candidatas
            ->sortBy(fn (CuentaVideo $c) => [(int) ($cargas[$c->id] ?? 0), $c->id])
            ->first();
    }
}
