<?php

declare(strict_types=1);

namespace App\Reportes;

use App\Models\Identidad\Usuario;
use App\Models\Reportes\AreaReporte;
use App\Models\Reportes\ReporteEscuela;
use App\Models\Reportes\UbicacionReporte;
use App\Services\Plataforma\ModulosDeLaEscuela;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Los que armó la escuela, leídos de `reportes_escuela`.
     *
     * @var array<string, ReporteDeLaEscuela>
     */
    private array $deLaEscuela = [];

    /**
     * Los suyos que hoy NO se pueden servir, con su razón.
     *
     * Se guardan en vez de tirarse: es lo que la pantalla del constructor
     * enseña para que alguien los arregle. Un reporte que desaparece sin decir
     * por qué se vuelve a armar igual de roto.
     *
     * @var array<string, string>
     */
    private array $retirados = [];

    /**
     * La escuela cuyos reportes de tabla ya se leyeron. Null = ninguna.
     *
     * ── Por qué se recuerda, y no basta con un booleano ────────────────────
     * Este registro es un SINGLETON, y `reportes:enviar-programados` recorre
     * todas las escuelas en UN proceso. Con un «ya está cargado» a secas, los
     * reportes de la primera escuela se le servirían a la segunda: sus
     * programaciones correrían una definición que no es suya, contra su base.
     * Lo que se recuerda es de QUIÉN son, y al cambiar de escuela se releen.
     */
    private ?string $escuelaLeida = null;

    private bool $leido = false;

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

    /**
     * La fuente, o null si esa clave ya no existe.
     *
     * `fuente()` lanza 404, que es lo correcto cuando alguien PIDE un reporte.
     * El constructor pregunta otra cosa —«¿este reporte de tabla todavía tiene
     * fuente?»— y ahí la ausencia es un dato que hay que enseñar con su razón,
     * no un error.
     */
    public function fuenteONull(string $clave): ?FuenteDeReporte
    {
        return $this->fuentes[$clave] ?? null;
    }

    public function definicion(string $clave): DefinicionReporte
    {
        return $this->catalogo()[$clave] ?? throw new NotFoundHttpException("Reporte desconocido: {$clave}");
    }

    /** @return array<string, DefinicionReporte> */
    public function todos(): array
    {
        return $this->catalogo();
    }

    /**
     * Los reportes del CÓDIGO y los de la escuela, en un solo mapa.
     *
     * Los del código van primero y ganan: uno de la escuela con una clave
     * repetida no puede sombrear al de siempre —y además no puede tenerla,
     * porque nacen con prefijo—. Es cinturón y tirantes sobre lo mismo, y el
     * barato es éste.
     *
     * @return array<string, DefinicionReporte>
     */
    private function catalogo(): array
    {
        $this->sincronizarConLaEscuela();

        return $this->reportes + $this->deLaEscuela;
    }

    /**
     * Relee `reportes_escuela` si cambió la escuela desde la última vez.
     *
     * Perezoso porque al construirse el singleton todavía no hay escuela: se
     * puebla en `AppServiceProvider`, que corre antes de que el middleware la
     * resuelva por dominio.
     */
    private function sincronizarConLaEscuela(): void
    {
        $escuela = tenant()?->getTenantKey();
        $escuela = $escuela === null ? null : (string) $escuela;

        if ($this->leido && $this->escuelaLeida === $escuela) {
            return;
        }

        $this->deLaEscuela = [];
        $this->retirados = [];
        $this->escuelaLeida = $escuela;
        $this->leido = true;

        // Sin escuela no hay tabla que leer, y una recién dada de alta puede no
        // haber migrado todavía.
        if ($escuela === null || ! Schema::hasTable('reportes_escuela')) {
            return;
        }

        foreach (ReporteEscuela::query()->publicados()->orderBy('nombre')->get() as $fila) {
            $problema = RevisionDelReporte::problema($fila, $this->fuentes[$fila->fuente] ?? null);

            if ($problema !== null) {
                $this->retirados[$fila->clave] = $problema;

                continue;
            }

            $this->deLaEscuela[$fila->clave] = new ReporteDeLaEscuela($fila);
        }
    }

    /**
     * Los reportes de la escuela que hoy no se sirven, con su razón.
     *
     * @return array<string, string>
     */
    public function retirados(): array
    {
        $this->sincronizarConLaEscuela();

        return $this->retirados;
    }

    /**
     * Las fuentes sobre las que ESTE usuario puede armar un reporte.
     *
     * Las mismas tres condiciones que `para()`, y por el mismo motivo: sólo se
     * arma encima de lo que ya se puede correr. Si no, alguien sin
     * `ver-adeudos` publicaría el padrón de la cartera sin haberlo visto nunca
     * — no se lo lleva él, pero decide qué se llevan los demás, y no puede
     * mirar lo que publica.
     *
     * @return array<string, FuenteDeReporte>
     */
    public function fuentesPara(Usuario $usuario): array
    {
        $modulos = app(ModulosDeLaEscuela::class);
        $faceta = $usuario->rolActivo?->faceta()?->name;

        return array_filter(
            $this->fuentes,
            function (FuenteDeReporte $fuente) use ($usuario, $modulos, $faceta) {
                if (! $usuario->can($fuente->permiso())) {
                    return false;
                }

                if ($fuente->modulo() !== null && ! $modulos->activo($fuente->modulo())) {
                    return false;
                }

                return $faceta === null || in_array($faceta, $fuente->facetas(), true);
            },
        );
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

        foreach ($this->catalogo() as $reporte) {
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
