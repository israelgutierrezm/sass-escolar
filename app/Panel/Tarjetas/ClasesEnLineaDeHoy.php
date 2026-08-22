<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Models\Lms\CuentaVideo;
use App\Models\Lms\Videoconferencia;
use App\Panel\TarjetaPanel;
use App\Support\ProveedoresVideoCatalogo;
use Illuminate\Support\Collection;

/**
 * Las clases en línea de hoy y si van a alcanzar las licencias.
 *
 * ── El dato que importa es el PICO, no el total ───────────────────────────
 * Una licencia de Zoom sostiene UNA reunión a la vez, así que lo que decide si
 * mañana hay que comprar otra no es cuántas clases hay en el día sino cuántas
 * coinciden en el peor momento. Lo declara
 * {@see ProveedoresVideoCatalogo::unaReunionPorCuenta}, que ante un proveedor
 * desconocido responde que sí — el lado seguro.
 *
 * Meet no entra en esa cuenta: su enlace nace de un evento de calendario y no
 * de una licencia de anfitrión, así que anunciarle escasez sería inventarla.
 *
 * ── El pico se calcula en memoria, sin una sola consulta extra ────────────
 * Existe un servicio que contesta «cuántas cuentas están ocupadas en esta
 * ventana», pero es la pregunta de quien programa una clase, no la del día
 * entero, y cuesta una consulta por licencia. Sobre las filas ya cargadas, el
 * pico sale gratis: el máximo solapamiento siempre cae en el ARRANQUE de alguna
 * clase, así que basta mirar esos instantes.
 *
 * ── Y nunca se toca `url_anfitrion` ───────────────────────────────────────
 * Es el enlace que entra como dueño de la sala sin pedir contraseña, o sea una
 * credencial. Aquí ni siquiera hace falta el de alumno: la tarjeta informa, no
 * invita a entrar.
 */
class ClasesEnLineaDeHoy implements TarjetaPanel
{
    private const A_LA_VISTA = 6;

    public function clave(): string
    {
        return 'clases-en-linea-hoy';
    }

    public function titulo(): string
    {
        return 'Clases en línea de hoy';
    }

    public function permiso(): ?string
    {
        return 'gestionar-clases-en-linea';
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
        return 'M15.75 10.5l4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        /*
         * Del DÍA, no «las que no han terminado». Con el scope de vigentes, al
         * mirar el panel a mediodía desaparecería la clase de las ocho — y su
         * licencia sí estuvo ocupada, así que el pico la necesita.
         */
        $clases = Videoconferencia::query()
            ->whereDate('inicio', today())
            // Una cancelada ni se da ni ocupa licencia.
            ->where('estado', '!=', Videoconferencia::CANCELADA)
            ->with([
                'materia.planMateria.asignatura:id,nombre',
                'materia.grupo:id,clave',
                'cuenta:id,etiqueta,proveedor',
            ])
            ->orderBy('inicio')
            ->get();

        /*
         * Es la agenda del día, no una métrica: casi toda escuela tiene días sin
         * clase en línea, y una tarjeta que diga «0 clases hoy» ocupa el sitio
         * de algo accionable. Sin nada hoy, no se dibuja.
         */
        if ($clases->isEmpty()) {
            return null;
        }

        return [
            'renglones' => $clases->take(self::A_LA_VISTA)->map(fn (Videoconferencia $v) => [
                'etiqueta' => $v->materia?->planMateria?->asignatura?->nombre ?? ($v->titulo ?: 'Clase'),
                'detalle' => implode(' · ', array_filter([
                    $v->materia?->grupo?->clave,
                    $this->nombreProveedor((string) $v->proveedor),
                ])),
                'valor' => $v->inicio?->format('H:i').'–'.$v->fin?->format('H:i'),
                'pie' => $v->cuenta?->etiqueta ?? 'sin licencia asignada',
                'progreso' => null,
                // Una clase sin cuenta es una sala que nadie va a poder abrir.
                'alerta' => $v->cuenta_id === null,
                'enlace' => null,
            ])->values()->all(),
            'pie' => $this->licencias($clases),
            'enlace' => '/plataforma/clases-en-linea',
        ];
    }

    /**
     * Cuántas licencias quedan libres en el momento más cargado del día.
     *
     * Limitación consciente: sólo se miran las clases de HOY, así que una que
     * cruzara la medianoche no cuenta en el pico de la madrugada siguiente. No
     * se corrige porque esa clase no existe en la práctica y ampliar la ventana
     * enturbiaría el «hoy» de los renglones.
     */
    private function licencias(Collection $clases): string
    {
        $piezas = [];

        foreach ($clases->groupBy('proveedor') as $proveedor => $delProveedor) {
            if (! ProveedoresVideoCatalogo::unaReunionPorCuenta((string) $proveedor)) {
                continue;
            }

            $total = CuentaVideo::query()->de((string) $proveedor)->activas()->count();
            [$pico, $instante] = $this->pico($delProveedor);

            $libres = max($total - $pico, 0);
            $nombre = $this->nombreProveedor((string) $proveedor);
            $hora = $instante?->format('H:i');

            $piezas[] = $libres === 0
                ? "{$nombre}: sin licencia libre a las {$hora}"
                : "{$nombre}: {$libres} de {$total} libres a las {$hora}";
        }

        return $piezas === []
            ? $clases->count().' clases hoy'
            : implode(' · ', $piezas);
    }

    /**
     * El máximo de licencias ocupadas a la vez, y a qué hora.
     *
     * Se cuentan CUENTAS distintas y no clases: lo que se agota es la licencia,
     * y una clase sin cuenta asignada no ocupa ninguna.
     *
     * @return array{0: int, 1: mixed}
     */
    private function pico(Collection $clases): array
    {
        $pico = 0;
        $instante = null;

        foreach ($clases as $clase) {
            $simultaneas = $clases
                ->filter(fn ($otra) => $otra->inicio <= $clase->inicio && $otra->fin > $clase->inicio)
                ->pluck('cuenta_id')
                ->filter()
                ->unique()
                ->count();

            if ($simultaneas > $pico) {
                $pico = $simultaneas;
                $instante = $clase->inicio;
            }
        }

        return [$pico, $instante];
    }

    private function nombreProveedor(string $clave): string
    {
        return ProveedoresVideoCatalogo::uno($clave)['nombre'] ?? ucfirst($clave);
    }
}
