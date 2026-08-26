<?php

/**
 * TODA columna `ordenable` de TODO reporte se puede exportar. Con rollback.
 *
 * Se corre con `php scripts/prueba-reportes-ordenables.php` desde la raíz.
 *
 * ── El defecto que vigila, y por qué hacía falta una red genérica ─────────
 * El motor ordena la PANTALLA con `orderBy($columnaSql)` y avanza la
 * EXPORTACIÓN con `whereRaw("({$columnaOrden}, {$llave}) > (?, ?)")`. MySQL
 * acepta el alias de un `SELECT` en el `ORDER BY` y **NO lo acepta en el
 * `WHERE`**, así que una columna que salga de un `selectSub` o de un `addSelect`
 * ordena perfectamente en la pantalla y revienta al pulsar «Excel» con
 * «Unknown column 'cobrado' in 'where clause'».
 *
 * Mordió en CUATRO columnas de tres fuentes recién escritas —`cobrado`,
 * `alumnos_count`, `materias` y `sin_titular`— y ninguna suite lo veía, porque
 * todas comprobaban el orden por columnas reales. Una de ellas ni siquiera
 * fallaba: `corte-de-caja` pasaba en verde porque el demo tiene cero pagos.
 *
 * ── Por qué esta suite recorre el REGISTRO y no una lista ────────────────
 * Porque el defecto es de CLASE, no de una fuente: la número doce lo va a
 * repetir. Recorriendo el registro, un reporte nuevo entra solo a la prueba el
 * día que se registra, sin que nadie se acuerde de añadirlo.
 *
 * El lote se fuerza a 2 para que el recorrido tenga que avanzar de verdad: con
 * un solo lote el predicado del keyset no llega a ejecutarse nunca y la
 * comprobación pasaría sin comprobar nada.
 */

use App\Models\Identidad\Persona;
use App\Models\Identidad\Rol;
use App\Models\Identidad\Usuario;
use App\Models\Tenant;
use App\Reportes\ColumnaReporte;
use App\Reportes\Ejecutor;
use App\Reportes\RegistroReportes;
use App\Services\Plataforma\ModulosDeLaEscuela;
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

DB::beginTransaction();

try {
    $persona = Persona::create([
        'nombre' => 'Prueba',
        'primer_apellido' => 'Ordenables',
        'segundo_apellido' => (string) random_int(1000, 9999),
        'sexo_id' => 1,
    ]);

    $usuario = Usuario::create([
        'persona_id' => $persona->id,
        'usuario' => 'prueba_ord_'.random_int(100000, 999999),
        'email' => 'prueba_ord_'.random_int(100000, 999999).'@ejemplo.mx',
        'password' => Hash::make('secreto12345'),
        'rol_activo_id' => Rol::where('name', 'director_general')->firstOrFail()->id,
    ]);

    $usuario->persona->asignacionesRol()->create([
        'rol_id' => $usuario->rol_activo_id,
        'activo' => true,
        'campus_id' => null,
    ]);

    $usuario = $usuario->fresh(['persona', 'rolActivo']);
    auth()->login($usuario);

    $registro = app(RegistroReportes::class);

    // Lotes de 2: el predicado del keyset TIENE que ejecutarse.
    $ejecutor = new class(app(RegistroReportes::class), app(ModulosDeLaEscuela::class)) extends Ejecutor
    {
        protected function tamanoDeLote(): int
        {
            return 2;
        }
    };

    echo PHP_EOL.'1. Toda columna ordenable dice por qué columna REAL ordena'.PHP_EOL;

    /*
     * La regla: `ordenable` exige un nombre CALIFICADO (`tabla.columna`). Un
     * alias de SELECT no lo lleva, y es lo único que distingue una cosa de la
     * otra sin ejecutar SQL.
     */
    $sinCalificar = [];

    foreach ($registro->todos() as $reporte) {
        $fuente = $registro->fuente($reporte->fuente());

        foreach ($fuente->columnas() as $columna) {
            if ($columna->ordenable && ! str_contains((string) $columna->columnaSql, '.')) {
                $sinCalificar[] = $fuente->clave().'.'.$columna->clave.' → '.$columna->columnaSql;
            }
        }
    }

    verificar('Ninguna columna ordenable apunta a un alias sin calificar',
        $sinCalificar === [], $sinCalificar === [] ? 'todas calificadas' : implode(' | ', $sinCalificar));

    echo PHP_EOL.'2. Y se EXPORTA de verdad por cada una, en las dos direcciones'.PHP_EOL;

    /*
     * La comprobación de arriba es de forma; ésta es de hecho. Se exporta por
     * CADA columna ordenable de CADA reporte, en las dos direcciones, con lotes
     * de 2 para obligar al cursor a avanzar.
     */
    $rotas = [];
    $probadas = 0;
    $vacios = [];
    $conObligatorios = [];

    /*
     * Un reporte puede EXIGIR filtros —«Docentes sin carga» no significa nada
     * sin ciclo— y entonces se niega a correr. Eso no es el defecto que esta
     * suite busca, así que se le da lo que pide: la primera opción viva de su
     * catálogo. Se anota cuáles, para que no parezca que corrieron pelados.
     */
    $obligatorios = function ($fuente, $reporte) use ($usuario, &$conObligatorios) {
        $valores = [];

        foreach ($reporte->filtrosObligatorios() as $clave) {
            $filtro = $fuente->filtros()[$clave] ?? null;

            if ($filtro === null) {
                continue;
            }

            $opciones = $filtro->opcionesPara($usuario);
            $valores[$clave] = (string) array_key_first($opciones);
            $conObligatorios[$reporte->clave()] = $clave;
        }

        return $valores;
    };

    foreach ($registro->todos() as $reporte) {
        $fuente = $registro->fuente($reporte->fuente());

        $ordenables = array_filter($fuente->columnas(), fn (ColumnaReporte $c) => $c->ordenable);

        foreach ($ordenables as $columna) {
            foreach (['asc', 'desc'] as $direccion) {
                try {
                    $exportacion = $ejecutor->paraExportar($usuario, $reporte->clave(), [
                        'orden_por' => $columna->clave,
                        'orden_dir' => $direccion,
                        // Los OBLIGATORIOS se rellenan con la primera opción del
                        // catálogo: sin ellos el reporte se niega a correr, y esa
                        // negativa es correcta —no es el defecto que se busca—.
                        'filtros' => $obligatorios($fuente, $reporte),
                    ]);

                    $emitidas = 0;

                    foreach ($exportacion->recorrer() as $fila) {
                        $emitidas++;

                        // Tope de la PRUEBA: si el cursor no avanzara, esto
                        // impide que la suite se cuelgue.
                        if ($emitidas > max(50, $exportacion->total * 3)) {
                            break;
                        }
                    }

                    $probadas++;

                    if ($exportacion->total === 0) {
                        // Un reporte sin filas NO ejercita el keyset. Se cuenta
                        // aparte en vez de dar por buena una comprobación que no
                        // comprobó nada: es como `corte-de-caja` pasaba en verde
                        // con el defecto vivo.
                        $vacios[] = $reporte->clave();

                        continue;
                    }

                    if ($emitidas !== $exportacion->total) {
                        $rotas[] = $reporte->clave().'/'.$columna->clave.' '.$direccion
                            .': '.$emitidas.' de '.$exportacion->total;
                    }
                } catch (\Throwable $e) {
                    $rotas[] = $reporte->clave().'/'.$columna->clave.' '.$direccion
                        .': '.class_basename($e).' — '.mb_substr($e->getMessage(), 0, 60);
                }
            }
        }
    }

    verificar('Se probó al menos una columna ordenable por reporte',
        $probadas >= count($registro->todos()), $probadas.' combinaciones sobre '.count($registro->todos()).' reportes');

    verificar('Ninguna exportación por columna ordenable falla ni descuadra',
        $rotas === [], $rotas === [] ? $probadas.' combinaciones, todas completas' : implode(' | ', array_slice($rotas, 0, 4)));

    /*
     * Los reportes vacíos se DICEN. No es un fallo —el demo tiene áreas en cero
     * a propósito— pero sí es cobertura que no existe, y callarla haría creer
     * que la red cubre más de lo que cubre.
     */
    $sinDatos = array_values(array_unique($vacios));

    if ($conObligatorios !== []) {
        echo "  [33m·[0m    Con su filtro obligatorio puesto para poder correr: "
            .implode(', ', array_keys($conObligatorios)).PHP_EOL;
    }

    echo '  '.($sinDatos === []
        ? "\033[32m·\033[0m    Todos los reportes tenían filas: la red cubrió todo."
        : "\033[33m·\033[0m    Sin filas en el demo, así que su keyset NO se ejercitó: ".implode(', ', $sinDatos)).PHP_EOL;

    echo PHP_EOL.'3. Y el motor lo DICE en vez de dejarlo pasar en silencio'.PHP_EOL;

    /*
     * Las dos comprobaciones de arriba cazan el defecto por sus CONSECUENCIAS
     * —el SQL revienta, o el recorrido descuadra—. Ésta comprueba que el motor
     * lo señale por su CAUSA, que es lo que ahorra la tarde de diagnóstico: sin
     * la red, el síntoma no apunta a nada —descendente trunca y ascendente no
     * termina— y quien lo vea buscará el defecto en el keyset.
     *
     * Se construye una fuente rota A PROPÓSITO. No se puede comprobar mutando
     * una buena: la mutación cuelga la propia suite.
     */
    $rota = new class extends App\Reportes\Fuentes\Grupos
    {
        /** El defecto: la columna sale al SELECT con OTRO nombre. */
        public function consulta(Usuario $usuario, array $filtros): Illuminate\Database\Eloquent\Builder
        {
            /*
             * `select()` REEMPLAZA, asi que esto de verdad quita `cuantos` de la
             * fila. Con un `addSelect` la columna buena seguia ahi y la fuente
             * no estaba rota: la primera version de esta prueba pasaba por eso.
             */
            return parent::consulta($usuario, $filtros)
                ->select('grupos.*')
                ->addSelect(['al.cuantos as con_otro_nombre', 'mat.cuantas', 'mat.sin_titular']);
        }

        public function columnas(): array
        {
            $columnas = parent::columnas();

            $columnas['alumnos'] = new ColumnaReporte(
                clave: 'alumnos',
                etiqueta: 'Alumnos',
                valor: fn ($g) => (int) ($g->con_otro_nombre ?? 0),
                // Apunta a una columna que el SELECT NO saca con ese nombre.
                columnaSql: 'al.cuantos',
                ordenable: true,
            );

            return $columnas;
        }
    };

    app()->instance(App\Reportes\Fuentes\Grupos::class, $rota);

    $registroRoto = new RegistroReportes;
    $registroRoto->registrarFuente(App\Reportes\Fuentes\Grupos::class);
    $registroRoto->registrarReporte(App\Reportes\Definiciones\OcupacionDeGrupos::class);

    $conRota = new class($registroRoto, app(ModulosDeLaEscuela::class)) extends Ejecutor
    {
        protected function tamanoDeLote(): int
        {
            return 2;
        }
    };

    $aviso = null;
    $emitidas = 0;

    try {
        $exp = $conRota->paraExportar($usuario, 'ocupacion-de-grupos', [
            'orden_por' => 'alumnos',
            'orden_dir' => 'asc',
        ]);

        foreach ($exp->recorrer() as $fila) {
            $emitidas++;

            if ($emitidas > 60) {
                break;
            }
        }
    } catch (RuntimeException $e) {
        $aviso = $e->getMessage();
    }

    verificar('Una columna de orden cuyo atributo no llega SE DETIENE',
        $aviso !== null, $aviso === null ? "siguió, {$emitidas} filas" : 'se detuvo');

    verificar('Y el aviso nombra la columna y el atributo que falta',
        $aviso !== null && str_contains($aviso, 'al.cuantos') && str_contains($aviso, 'cuantos'),
        mb_substr((string) $aviso, 0, 70).'…');

    verificar('Y explica la consecuencia, no sólo que falló',
        $aviso !== null && str_contains($aviso, 'no termina'),
        $aviso !== null && str_contains($aviso, 'no termina') ? 'la explica' : 'no la explica');

    echo PHP_EOL.'Resultado: '.($verificaciones - $fallidas)." correctas, {$fallidas} fallidas".PHP_EOL;

} finally {
    DB::rollBack();
}
