<?php

/**
 * El modo AGRUPADO. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-agrupados.php` desde la raíz.
 *
 * ── Qué se vigila, y por qué ──────────────────────────────────────────────
 *  1. **Los subtotales suman el total general.** Es el criterio verificable que
 *     el plan escribió para esta rebanada, y lo único que un agrupado promete:
 *     si no suma, es otra consulta con otro nombre.
 *  2. **Y el agrupado respeta el RECORTE.** Un resumen por campus que enseñe
 *     los campus ajenos es la misma fuga que un total sin acotar, sólo que más
 *     visible: sale con su nombre encima.
 *  3. **Una fuente sin dimensiones NO ofrece el modo**, y pedirlo por la URL se
 *     niega. Falla cerrado: agrupar por lo que hay —identificadores— daría una
 *     fila por grupo, que no es agrupar.
 *  4. **La dimensión pasa por el filtro de permisos.** La etiqueta de un grupo
 *     ES el valor de la columna, y `columnasOmitidas()` no la ve: recorre las
 *     columnas pedidas, y `agrupar_por` es un camino aparte.
 *  5. **El grupo SIN etiqueta se enseña.** Esconderlo haría que los subtotales
 *     dejaran de sumar, que es justo lo que se prueba arriba.
 *  6. **Y lo cortado se DICE.** Un agrupado con más grupos que el tope no es un
 *     agrupado; callarlo lo haría leerse como el total de la escuela.
 */

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Reportes\Agregacion;
use App\Reportes\ColumnaReporte;
use App\Reportes\DimensionReporte;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Reportes\TipoDato;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

tenancy()->initialize(Tenant::find('demo'));

$verificaciones = 0;
$fallidas = 0;

function verificar(string $que, bool $bien, string $detalle = ''): void
{
    global $verificaciones, $fallidas;
    $verificaciones++;

    if ($bien) {
        echo "  \033[32mOK\033[0m   {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    } else {
        $fallidas++;
        echo "  \033[31mFALLA\033[0m {$que}".($detalle !== '' ? "  [{$detalle}]" : '').PHP_EOL;
    }
}

function usuarioConRol(string $rol, ?int $campusId = null): Usuario
{
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Agrupado',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $cuenta = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_agr_'.random_int(100000, 999999),
        'email' => 'prueba_agr_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', $rol)->firstOrFail()->id,
    ]);

    $cuenta->persona->asignacionesRol()->create([
        'rol_id' => $cuenta->rol_activo_id,
        'activo' => true,
        'campus_id' => $campusId,
    ]);

    return $cuenta->fresh(['persona', 'rolActivo']);
}

DB::beginTransaction();

try {
    $ejecutor = app(Ejecutor::class);
    $registro = app(RegistroReportes::class);

    $global = usuarioConRol('director_general');
    auth()->login($global);

    $columnas = ['matricula', 'saldo', 'vencido'];

    echo PHP_EOL.'1. Los subtotales SUMAN el total general'.PHP_EOL;

    $plano = $ejecutor->ejecutar($global, 'estado-de-cartera', [
        'columnas' => $columnas, 'por_pagina' => 500,
    ]);

    verificar('El reporte plano tiene filas y totales sobre los que comparar',
        $plano->total() > 0 && $plano->totales !== null,
        $plano->total().' filas, saldo '.($plano->totales['valores']['saldo'] ?? '—'));

    foreach (['campus', 'programa_academico', 'situacion'] as $dimension) {
        $agrupado = $ejecutor->agrupar($global, 'estado-de-cartera', $dimension, [
            'columnas' => $columnas,
        ]);

        $suma = $agrupado->totalGeneral();

        verificar("«{$dimension}»: las filas de los grupos suman las del reporte",
            $agrupado->filas() === $plano->total(),
            $agrupado->filas().' de '.$plano->total().' en '.count($agrupado->grupos).' grupos');

        $cuadranImportes = true;

        foreach (['saldo', 'vencido'] as $medida) {
            if (abs(($suma[$medida] ?? 0) - (float) $plano->totales['valores'][$medida]) >= 0.01) {
                $cuadranImportes = false;
            }
        }

        verificar("«{$dimension}»: y los importes también",
            $cuadranImportes,
            'saldo '.($suma['saldo'] ?? '—').' vs '.$plano->totales['valores']['saldo']);
    }

    echo PHP_EOL.'2. Sólo se agregan las MEDIDAS, no cualquier columna'.PHP_EOL;

    $conTexto = $ejecutor->agrupar($global, 'estado-de-cartera', 'campus', [
        'columnas' => ['matricula', 'alumno', 'programa_academico', 'saldo'],
    ]);

    verificar('Las columnas de texto no salen como medida',
        array_keys($conTexto->medidas) === ['saldo'],
        implode(', ', array_keys($conTexto->medidas)));

    /*
     * Dentro de un grupo, «alumno» no significa nada —¿el nombre de cuál de los
     * veintidós?— y enseñar el primero que devuelva MySQL sería inventar un
     * representante.
     */
    verificar('Y tampoco se cuelan en los valores de cada grupo',
        collect($conTexto->grupos)->every(
            fn (array $g) => array_keys($g['valores']) === ['saldo'],
        ));

    echo PHP_EOL.'3. Una fuente SIN dimensiones no ofrece el modo'.PHP_EOL;

    $sinDimensiones = null;

    foreach ($registro->todos() as $clave => $definicion) {
        if (! $registro->fuente($definicion->fuente()) instanceof App\Reportes\FuenteAgrupable) {
            $sinDimensiones = $clave;
            break;
        }
    }

    verificar('Hay un reporte cuya fuente no declara dimensiones',
        $sinDimensiones !== null, (string) $sinDimensiones);

    if ($sinDimensiones !== null) {
        $motivo = null;

        try {
            $ejecutor->agrupar($global, $sinDimensiones, 'campus');
        } catch (AvisoParaElUsuario $e) {
            $motivo = $e->getStatusCode().': '.$e->getMessage();
        }

        verificar('Pedirle un agrupado se niega con 422 y su razón',
            $motivo !== null && str_starts_with($motivo, '422') && str_contains($motivo, 'dimensión'),
            $motivo ?? 'lo ejecutó');
    }

    $motivo = null;

    try {
        $ejecutor->agrupar($global, 'estado-de-cartera', 'no-existe-esta-dimension');
    } catch (AvisoParaElUsuario $e) {
        $motivo = $e->getStatusCode();
    }

    verificar('Y una dimensión inventada también',
        $motivo === 422, (string) ($motivo ?? 'la ejecutó'));

    echo PHP_EOL.'4. El agrupado respeta el RECORTE por campus'.PHP_EOL;

    $campusDelCoordinador = DB::table('oferta')->whereNull('deleted_at')->value('campus_id');
    $coordinador = usuarioConRol('director_general', $campusDelCoordinador);

    $global0 = $ejecutor->agrupar($global, 'estado-de-cartera', 'campus', ['columnas' => $columnas]);

    auth()->login($coordinador);
    $suyo = $ejecutor->agrupar($coordinador, 'estado-de-cartera', 'campus', ['columnas' => $columnas]);
    $suPlano = $ejecutor->ejecutar($coordinador, 'estado-de-cartera', [
        'columnas' => $columnas, 'por_pagina' => 500,
    ]);
    auth()->login($global);

    verificar('El coordinador ve MENOS grupos que el global',
        count($suyo->grupos) < count($global0->grupos),
        count($suyo->grupos).' de '.count($global0->grupos));

    verificar('Y sólo el suyo',
        collect($suyo->grupos)->every(
            fn (array $g) => $g['etiqueta'] === DB::table('campus')->where('id', $campusDelCoordinador)->value('nombre'),
        ),
        collect($suyo->grupos)->pluck('etiqueta')->implode(', '));

    /*
     * Y sus subtotales suman SU total, no el de la escuela. Es la comprobación
     * que separa «el agrupado se acota» de «el agrupado se acota pero el pie
     * no», que sería la fuga con el número más visible de la pantalla.
     */
    verificar('Sus subtotales suman SU total, no el de la escuela',
        $suyo->filas() === $suPlano->total() && $suyo->filas() < $global0->filas(),
        $suyo->filas().' suyas de '.$global0->filas().' totales');

    echo PHP_EOL.'5. La DIMENSIÓN pasa por el filtro de permisos'.PHP_EOL;

    /*
     * Se CONSTRUYE la dimensión sensible: ninguna de las declaradas lo es —hoy
     * las 14 columnas sensibles ni siquiera tienen columna SQL, así que la
     * puerta está cerrada por accidente—, y comprobar las que hay no comprueba
     * la regla.
     */
    $conDimensionSensible = new class implements App\Reportes\FuenteAgrupable, App\Reportes\FuenteDeReporte
    {
        public function clave(): string
        {
            return 'prueba-dimension-sensible';
        }

        public function titulo(): string
        {
            return 'Dimensión sensible (sólo para la prueba)';
        }

        public function grano(): string
        {
            return 'Una fila es una matrícula.';
        }

        public function permiso(): string
        {
            return 'ver-alumnos';
        }

        public function modulo(): ?string
        {
            return null;
        }

        public function facetas(): array
        {
            return ['administrativo'];
        }

        public function recorte(): App\Reportes\Recorte
        {
            return App\Reportes\Recorte::porOferta();
        }

        public function columnas(): array
        {
            return [
                'matricula' => new ColumnaReporte(
                    clave: 'matricula',
                    etiqueta: 'Matrícula',
                    columnaSql: 'matricula_oferta.matricula',
                    ordenable: true,
                ),
            ];
        }

        public function dimensiones(): array
        {
            return [
                'curp' => new DimensionReporte(
                    clave: 'curp',
                    etiqueta: 'CURP',
                    sqlAgrupacion: 'personas.curp',
                    sqlEtiqueta: 'personas.curp',
                    join: fn ($q) => $q->join('personas', 'personas.id', '=', 'matricula_oferta.persona_id'),
                    sensible: true,
                    permisoExtra: 'gestionar-usuarios',
                ),
            ];
        }

        public function filtros(): array
        {
            return [];
        }

        public function consulta(Usuario $usuario, array $filtros): \Illuminate\Database\Eloquent\Builder
        {
            return App\Models\Admisiones\MatriculaOferta::query()->select('matricula_oferta.*');
        }

        public function llavePrimaria(): string
        {
            return 'matricula_oferta.id';
        }
    };

    $definicionSensible = new class extends App\Reportes\DefinicionReporte
    {
        public function clave(): string
        {
            return 'prueba-dimension-sensible';
        }

        public function titulo(): string
        {
            return 'Dimensión sensible';
        }

        public function descripcion(): string
        {
            return 'Existe sólo para comprobar que agrupar por un dato sensible exige su permiso.';
        }

        public function fuente(): string
        {
            return 'prueba-dimension-sensible';
        }

        public function ordenPorOmision(): ?array
        {
            return ['matricula', 'asc'];
        }
    };

    (function () use ($conDimensionSensible, $definicionSensible) {
        $this->fuentes['prueba-dimension-sensible'] = $conDimensionSensible;
        $this->reportes['prueba-dimension-sensible'] = $definicionSensible;
    })->call($registro);

    /* Un rol que ve alumnos pero NO gestiona usuarios. */
    $rolCorto = Rol::create([
        'name' => 'prueba_sin_usuarios_'.random_int(1000, 9999),
        'nombre' => 'Rol sin gestionar usuarios',
        'guard_name' => 'web',
        'rol_padre_id' => Rol::where('name', 'administrativo')->value('id'),
    ]);
    $rolCorto->givePermissionTo(['ver-alumnos', 'ver-reportes']);

    $corto = usuarioConRol('director_general');
    DB::table('persona_rol')->where('persona_id', $corto->persona_id)->update(['rol_id' => $rolCorto->id]);
    Usuario::where('id', $corto->id)->update(['rol_activo_id' => $rolCorto->id]);
    $corto = Usuario::find($corto->id);

    verificar('El rol corto ve alumnos y NO gestiona usuarios',
        $corto->can('ver-alumnos') && ! $corto->can('gestionar-usuarios'));

    auth()->login($corto);
    $motivo = null;

    try {
        $ejecutor->agrupar($corto, 'prueba-dimension-sensible', 'curp');
    } catch (AvisoParaElUsuario $e) {
        $motivo = $e->getStatusCode();
    }

    auth()->login($global);

    verificar('Agrupar por un dato sensible sin su permiso da 403',
        $motivo === 403, (string) ($motivo ?? 'lo ejecutó'));

    verificar('Y quien SÍ lo tiene puede',
        count($ejecutor->agrupar($global, 'prueba-dimension-sensible', 'curp')->grupos) > 0);

    echo PHP_EOL.'6. El grupo SIN etiqueta se enseña, no se esconde'.PHP_EOL;

    /*
     * Se prueba sobre ASPIRANTES y no sobre matrículas, y por una razón medida:
     * `matricula_oferta.situacion_id` y `.oferta_id` son NOT NULL, así que ahí
     * el grupo vacío no lo puede producir la base ni sembrándolo —el UPDATE
     * revienta con «Column cannot be null»— y un `leftJoin` prometería un grupo
     * imposible.
     *
     * En `aspirantes` las tres foráneas SÍ son nullable, y a propósito: alguien
     * puede llegar sin campus elegido, sin origen registrado y sin etapa. Ése es
     * además el grupo que hay que atender.
     */
    foreach ([['aspirantes.campus_id', 'campus'], ['aspirantes.origen_id', 'origen']] as [$columna, $dimension]) {
        [$tabla, $campo] = explode('.', $columna);

        $nulable = DB::selectOne(
            'select is_nullable as n from information_schema.columns
             where table_schema = database() and table_name = ? and column_name = ?',
            [$tabla, $campo],
        );

        verificar("`{$columna}` admite NULL, así que el grupo vacío es alcanzable",
            ($nulable->n ?? '') === 'YES', (string) ($nulable->n ?? '?'));
    }

    $victima = DB::table('aspirantes')->whereNull('deleted_at')
        ->whereNotNull('campus_id')->first();

    verificar('Hay un aspirante con campus al que quitárselo', $victima !== null);

    if ($victima !== null) {
        $antes = $ejecutor->agrupar($global, 'prospectos-abiertos', 'campus');

        DB::table('aspirantes')->where('id', $victima->id)->update(['campus_id' => null]);

        $despues = $ejecutor->agrupar($global, 'prospectos-abiertos', 'campus');

        verificar('Aparece un grupo sin etiqueta',
            collect($despues->grupos)->contains(fn (array $g) => $g['etiqueta'] === null),
            collect($despues->grupos)->pluck('etiqueta')->map(fn ($e) => $e ?? '(sin)')->implode(', '));

        /*
         * Y ésta es la que importa: los subtotales SIGUEN sumando el total.
         * Si el grupo vacío se escondiera, aquí faltaría uno — y un agrupado que
         * no suma no es un agrupado.
         */
        verificar('Y los subtotales SIGUEN sumando el total',
            $despues->filas() === $antes->filas(),
            $antes->filas().' → '.$despues->filas());

        DB::table('aspirantes')->where('id', $victima->id)
            ->update(['campus_id' => $victima->campus_id]);
    }

    echo PHP_EOL.'7. Lo CORTADO se dice'.PHP_EOL;

    /*
     * Una dimensión de alta cardinalidad, que es lo que el tope existe para
     * atrapar: agrupar por algo con más valores distintos que el tope no es
     * agrupar, es el detalle con otro nombre.
     */
    $renglones = (int) DB::table('historial')->whereNull('deleted_at')->count();

    verificar('Hay más renglones de historial que el tope de grupos',
        $renglones > 200, $renglones.' renglones');

    if ($renglones > 200) {
        $altaCardinalidad = new class implements App\Reportes\FuenteAgrupable, App\Reportes\FuenteDeReporte
        {
            public function clave(): string
            {
                return 'prueba-alta-cardinalidad';
            }

            public function titulo(): string
            {
                return 'Alta cardinalidad (sólo para la prueba)';
            }

            public function grano(): string
            {
                return 'Una fila es un renglón de historial.';
            }

            public function permiso(): string
            {
                return 'ver-alumnos';
            }

            public function modulo(): ?string
            {
                return null;
            }

            public function facetas(): array
            {
                return ['administrativo'];
            }

            public function recorte(): App\Reportes\Recorte
            {
                return App\Reportes\Recorte::sinCampus('Sólo para la prueba del tope.');
            }

            public function columnas(): array
            {
                return [
                    'id' => new ColumnaReporte(
                        clave: 'id',
                        etiqueta: 'Id',
                        tipo: TipoDato::Entero,
                        columnaSql: 'historial.id',
                        ordenable: true,
                        total: Agregacion::Ninguno,
                        ayuda: 'No se totaliza: es un identificador.',
                    ),
                ];
            }

            public function dimensiones(): array
            {
                return [
                    'renglon' => new DimensionReporte(
                        clave: 'renglon',
                        etiqueta: 'Renglón',
                        sqlAgrupacion: 'historial.id',
                        sqlEtiqueta: 'historial.id',
                    ),
                ];
            }

            public function filtros(): array
            {
                return [];
            }

            public function consulta(Usuario $usuario, array $filtros): \Illuminate\Database\Eloquent\Builder
            {
                return App\Models\ControlEscolar\Historial::query()->select('historial.*');
            }

            public function llavePrimaria(): string
            {
                return 'historial.id';
            }
        };

        $definicionAlta = new class extends App\Reportes\DefinicionReporte
        {
            public function clave(): string
            {
                return 'prueba-alta-cardinalidad';
            }

            public function titulo(): string
            {
                return 'Alta cardinalidad';
            }

            public function descripcion(): string
            {
                return 'Existe sólo para comprobar que el tope de grupos se dispara y se dice.';
            }

            public function fuente(): string
            {
                return 'prueba-alta-cardinalidad';
            }

            public function ordenPorOmision(): ?array
            {
                return ['id', 'asc'];
            }
        };

        (function () use ($altaCardinalidad, $definicionAlta) {
            $this->fuentes['prueba-alta-cardinalidad'] = $altaCardinalidad;
            $this->reportes['prueba-alta-cardinalidad'] = $definicionAlta;
        })->call($registro);

        $cortado = $ejecutor->agrupar($global, 'prueba-alta-cardinalidad', 'renglon');

        verificar('Se corta en el tope', count($cortado->grupos) === 200,
            count($cortado->grupos).' grupos');

        verificar('Y lo DICE, en vez de dejarlo pasar por completo',
            $cortado->truncado === true);

        /*
         * Y esto es lo que el aviso protege: cortado, los subtotales NO suman el
         * total. Callarlo haría leer la tabla como el resumen de la escuela.
         */
        verificar('Cortado, los subtotales ya no suman el total',
            $cortado->filas() < $renglones,
            $cortado->filas().' de '.$renglones);
    }

    echo PHP_EOL.'7b. Lo que el agrupado deja en la BITÁCORA'.PHP_EOL;

    /*
     * Una pantalla agrupada escribe DOS filas —«pantalla» y «agrupado»— porque
     * el controlador llama a `ejecutar()` y además a `agrupar()`. Eso rompió dos
     * cosas a la vez y ninguna daba error:
     *
     *  1. El agrupado quedaba FUERA de la deduplicación, porque ésta sólo
     *     miraba el formato «pantalla» literal.
     *  2. Y su fila envenenaba la de pantalla: la deduplicación comparaba contra
     *     la ÚLTIMA de esa persona, que era siempre la de agrupado, así que
     *     tampoco la de pantalla casaba nunca.
     *
     * Medido antes de arreglarlo: cinco recargas de una pantalla agrupada
     * dejaban NUEVE filas, cuando sin agrupar dejan una.
     */
    auth()->login($global);
    $cuantas = fn () => App\Models\Reportes\EjecucionReporte::query()->count();

    /*
     * Con un juego de columnas PROPIO: las secciones de arriba ya corrieron este
     * reporte y ya están deduplicadas, así que sin esto se contarían cero filas
     * nuevas y la comprobación mediría la deduplicación creyendo medir el
     * defecto del agrupado.
     */
    $columnasPropias = ['matricula', 'alumno', 'saldo'];
    $antesDeAgrupar = $cuantas();

    foreach (range(1, 5) as $vuelta) {
        $ejecutor->ejecutar($global, 'estado-de-cartera', ['columnas' => $columnasPropias]);
        $ejecutor->agrupar($global, 'estado-de-cartera', 'campus', ['columnas' => $columnasPropias]);
    }

    verificar('Cinco recargas de una pantalla agrupada dejan DOS filas',
        $cuantas() - $antesDeAgrupar === 2,
        'quedaron '.($cuantas() - $antesDeAgrupar).' (una de pantalla y una de agrupado)');

    $antesDeOtra = $cuantas();
    $ejecutor->agrupar($global, 'estado-de-cartera', 'programa_academico', ['columnas' => $columnas]);

    /*
     * Y agrupar por OTRA dimensión es otra pregunta. Sin anotar la dimensión, las
     * dos filas serían idénticas y la deduplicación las fundiría: la bitácora no
     * podría decir qué se preguntó.
     */
    verificar('Agrupar por otra dimensión SÍ agrega fila',
        $cuantas() - $antesDeOtra === 1,
        $antesDeOtra.' → '.$cuantas());

    $ultima = App\Models\Reportes\EjecucionReporte::query()->latest('id')->first();

    verificar('Y la bitácora guarda POR QUÉ dimensión se agrupó',
        ($ultima->filtros['agrupar_por'] ?? null) === 'programa_academico',
        json_encode($ultima->filtros, JSON_UNESCAPED_UNICODE));

    /*
     * Y un agrupado NO es una descarga. El resumen de la bitácora las separa
     * enumerando lo que es pantalla, no por descarte: cuando se definía por
     * descarte, el formato nuevo cayó del lado equivocado y la pantalla decía
     * 125 descargas cuando habían salido 79 archivos.
     */
    verificar('El formato del agrupado cuenta como PANTALLA, no como descarga',
        in_array('agrupado', App\Reportes\Ejecutor::FORMATOS_DE_PANTALLA, true));

    echo PHP_EOL.'8. La dimensión comprueba su forma al construirse'.PHP_EOL;

    $rechazo = null;

    try {
        new DimensionReporte(
            clave: 'x', etiqueta: 'X',
            sqlAgrupacion: 'count(*)', sqlEtiqueta: 'campus.nombre',
        );
    } catch (InvalidArgumentException $e) {
        $rechazo = $e->getMessage();
    }

    verificar('Una expresión que no es `tabla.columna` se rechaza',
        $rechazo !== null && str_contains($rechazo, 'sqlAgrupacion'),
        $rechazo === null ? 'la aceptó' : mb_substr($rechazo, 0, 60).'…');

    $rechazo = null;

    try {
        new DimensionReporte(
            clave: 'y', etiqueta: 'Y',
            sqlAgrupacion: 'oferta.campus_id', sqlEtiqueta: 'campus.nombre',
            permisoExtra: 'no-existe-este-permiso',
        );
    } catch (InvalidArgumentException $e) {
        $rechazo = $e->getMessage();
    }

    verificar('Y un permiso inexistente también',
        $rechazo !== null && str_contains($rechazo, 'CatalogoPermisos'),
        $rechazo === null ? 'la aceptó' : mb_substr($rechazo, 0, 60).'…');

    echo PHP_EOL.'9. TODA dimensión de TODO reporte agrupa de verdad'.PHP_EOL;

    /*
     * La red que faltaba, y costó dos defectos escribirla.
     *
     * Todo lo de arriba prueba el MECANISMO sobre una fuente o dos. Lo que
     * ninguna prueba recorría es cada dimensión de cada reporte, y ahí es donde
     * viven los defectos que sólo se ven agrupando:
     *
     *  1. **La dimensión ni se construye.** `DimensionReporte` es un objeto con
     *     parámetros con nombre, así que equivocarse en uno —`columna:` en vez
     *     de `sqlAgrupacion:`— lanza «Unknown named parameter». No falla al
     *     arrancar: `dimensiones()` sólo se ejecuta cuando alguien abre ese
     *     reporte, así que es un 500 en la pantalla del cliente.
     *
     *  2. **Un filtro sin calificar se vuelve AMBIGUO al unir la dimensión.**
     *     `whereIn('estado', …)` funciona perfectamente en la tabla y revienta
     *     en cuanto el `GROUP BY` une otra tabla que también tiene `estado`
     *     —«Column 'estado' in where clause is ambiguous»—. Y con los filtros
     *     FIJOS de un reporte se dispara solo, sin que nadie escriba nada.
     *
     * Se corre con el usuario GLOBAL y con uno ACOTADO, porque el recorte
     * también une tablas y puede volver ambiguo lo que sin él no lo era.
     */
    $acotadoDim = usuarioConRol('director_general', $campusDelCoordinador);

    $rotos = [];
    $pares = 0;

    foreach ($registro->todos() as $clave => $definicion) {
        $fuente = $registro->fuente($definicion->fuente());

        if (! $fuente instanceof App\Reportes\FuenteAgrupable) {
            continue;
        }

        foreach (array_keys($fuente->dimensiones()) as $dimension) {
            foreach (['global' => $global, 'acotado' => $acotadoDim] as $quien => $usuario) {
                $pares++;

                try {
                    $ejecutor->agrupar($usuario, $clave, $dimension);
                } catch (AvisoParaElUsuario $negado) {
                    // 403 de `sinCampus()` al acotado: es su decisión escrita.
                    if ($negado->getStatusCode() === 403 && $quien === 'acotado') {
                        continue;
                    }

                    $rotos[] = "{$clave}/{$dimension} ({$quien}): ".$negado->getMessage();
                } catch (Throwable $e) {
                    $rotos[] = "{$clave}/{$dimension} ({$quien}): ".mb_substr($e->getMessage(), 0, 110);
                }
            }
        }
    }

    verificar('Ninguna revienta', $rotos === [],
        $rotos === []
            ? $pares.' combinaciones'
            : count($rotos).' rotas: '.implode(' | ', array_slice($rotos, 0, 3)));

    verificar('Y se ejercitaron de verdad', $pares > 40, (string) $pares);

} catch (Throwable $falla) {
    /*
     * Sin este `catch`, lo que mata al script se lleva por delante el resumen y
     * un barrido que busca «Resultado:» lo reporta como SUITE ROTA en vez de
     * como prueba que detecto algo. Es la lección del cierre fiscal, y esta
     * suite se quedó sin ella: una mutación que rompe `dimensiones()` no
     * imprimía una sola línea.
     */
    $verificaciones++;
    $fallidas++;
    echo "  [31mFALLA[0m la suite murió antes de terminar: ".$falla->getMessage()
        .' ('.basename($falla->getFile()).':'.$falla->getLine().')'.PHP_EOL;
} finally {
    DB::rollBack();

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;
}
