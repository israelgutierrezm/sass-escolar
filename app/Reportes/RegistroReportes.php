<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Models\Identidad\Usuario;
use App\Models\Reportes\AreaReporte;
use App\Models\Reportes\UbicacionReporte;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El catálogo de fuentes y reportes. Calcado de `RegistroTarjetas`.
 *
 * Se puebla en `AppServiceProvider`. El controlador no conoce ningún reporte
 * concreto: un reporte nuevo es una clase más y aparece solo en su área, igual
 * que una tarjeta del panel.
 */
class RegistroReportes
{
    /** @var array<string, FuenteDeReporte> */
    private array $fuentes = [];

    /** @var array<string, DefinicionReporte> */
    private array $reportes = [];

    /** @param  class-string<FuenteDeReporte>  $clase */
    public function registrarFuente(string $clase): void
    {
        $fuente = app($clase);
        $this->fuentes[$fuente->clave()] = $fuente;
    }

    /**
     * @param  class-string<DefinicionReporte>  $clase
     *
     * Se comprueba que el ORDEN POR OMISIÓN nombre una columna que de verdad se
     * puede ordenar. El motor, si no, lo descarta EN SILENCIO —«ordenable» exige
     * , y sin ella  cae a null— y el reporte sale
     * ordenado por su llave primaria mientras su definición declara otra cosa.
     * Mordió en «Materias sin lista pasada», que pedía orden por matrícula.
     *
     * Aquí y no en  porque hacen falta las dos mitades: la
     * definición dice por qué columna, y quién sabe si esa columna es ordenable
     * es la FUENTE.
     */
    public function registrarReporte(string $clase): void
    {
        $reporte = app($clase);

        // Sin orden por omisión no hay nada que comprobar: el motor cae a la
        // llave primaria y la definición no promete otra cosa.
        $orden = $reporte->ordenPorOmision();

        if ($orden === null) {
            $this->reportes[$reporte->clave()] = $reporte;

            return;
        }

        [$por] = $orden;
        $columna = $this->fuente($reporte->fuente())->columnas()[$por] ?? null;

        if ($columna === null || ! $columna->ordenable) {
            throw new \InvalidArgumentException(
                "El reporte «{$reporte->clave()}» pide orden por omisión «{$por}», que "
                .($columna === null ? 'no existe en su fuente.' : 'no es ordenable.')
                .' Sin columna SQL el motor lo descarta sin avisar y ordena por la llave primaria.',
            );
        }

        $this->reportes[$reporte->clave()] = $reporte;
    }

    /**
     * Una fuente por su clave.
     *
     * 404 y no 403 ante una clave desconocida: un 403 ya confirma que el
     * reporte existe, y eso es información.
     */
    public function fuente(string $clave): FuenteDeReporte
    {
        return $this->fuentes[$clave] ?? throw new NotFoundHttpException("Fuente de reporte desconocida: {$clave}");
    }

    public function definicion(string $clave): DefinicionReporte
    {
        return $this->reportes[$clave] ?? throw new NotFoundHttpException("Reporte desconocido: {$clave}");
    }

    /** @return array<string, DefinicionReporte> */
    public function todos(): array
    {
        return $this->reportes;
    }

    /**
     * Los reportes que este usuario puede ver de verdad.
     *
     * Se filtra por las TRES cosas, y las tres importan por separado:
     *  - el permiso de la fuente (no le toca),
     *  - el módulo apagable (la escuela no usa esa función), y
     *  - la faceta del rol activo (una fuente sin recorte declarado para su
     *    oficio no se le ofrece).
     *
     * @return array<int, DefinicionReporte>
     */
    public function para(Usuario $usuario): array
    {
        $modulos = app(ModulosDeLaEscuela::class);
        $faceta = $usuario->rolActivo?->faceta()?->name;

        $visibles = [];

        foreach ($this->reportes as $reporte) {
            $fuente = $this->fuentes[$reporte->fuente()] ?? null;

            // Un reporte que apunta a una fuente que ya no existe se calla en
            // vez de reventar la pantalla entera de reportes.
            if ($fuente === null) {
                continue;
            }

            if (! $usuario->can($fuente->permiso())) {
                continue;
            }

            if ($fuente->modulo() !== null && ! $modulos->activo($fuente->modulo())) {
                continue;
            }

            if ($faceta !== null && ! in_array($faceta, $fuente->facetas(), true)) {
                continue;
            }

            $visibles[] = $reporte;
        }

        return $visibles;
    }

    /**
     * Los reportes de esta persona, ya AGRUPADOS por su area configurada.
     *
     * El area sale de `ubicaciones_reporte` si la escuela lo movio, y si no de
     * la que el reporte declara. Lo mismo el nombre: `nombre` en null significa
     * «el titulo que declara la clase», asi que un reporte renombrado en el
     * codigo se sigue actualizando solo para quien no lo haya rebautizado.
     *
     * Un reporte APAGADO en su ubicacion no se ofrece --pero eso es cosmetico y
     * NO sustituye al permiso, que ya filtro antes--.
     *
     * @return array<int, array<string, mixed>>
     */
    public function agrupadosPara(Usuario $usuario): array
    {
        $reportes = $this->para($usuario);

        $ubicaciones = UbicacionReporte::query()
            ->with('area')
            ->get()
            ->keyBy('reporte');

        $areas = AreaReporte::query()->get()->keyBy('clave');

        $grupos = [];

        foreach ($reportes as $reporte) {
            $ubicacion = $ubicaciones->get($reporte->clave());

            if ($ubicacion !== null && ! $ubicacion->activo) {
                continue;
            }

            $area = $ubicacion?->area ?? $areas->get($reporte->areaSugerida());

            // Un area apagada esconde lo que tiene dentro: es la forma de
            // retirar del indice un bloque entero sin borrar nada.
            if ($area !== null && ! $area->activo) {
                continue;
            }

            $clave = $area?->clave ?? $reporte->areaSugerida();

            $grupos[$clave] ??= [
                'clave' => $clave,
                'nombre' => $area?->nombre ?? $clave,
                'descripcion' => $area?->descripcion,
                'orden' => $area?->orden ?? 999,
                'reportes' => [],
            ];

            $grupos[$clave]['reportes'][] = [
                'clave' => $reporte->clave(),
                'titulo' => $ubicacion?->nombre ?? $reporte->titulo(),
                'descripcion' => $reporte->descripcion(),
                'orden' => $ubicacion?->orden ?? 0,
            ];
        }

        usort($grupos, fn (array $a, array $b) => [$a['orden'], $a['nombre']] <=> [$b['orden'], $b['nombre']]);

        foreach ($grupos as &$grupo) {
            usort($grupo['reportes'], fn (array $a, array $b) => [$a['orden'], $a['titulo']] <=> [$b['orden'], $b['titulo']]);
        }

        return $grupos;
    }
}
