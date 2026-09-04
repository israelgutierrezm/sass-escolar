<?php

declare(strict_types=1);

namespace App\Services\Permanencia;

use App\Exceptions\AvisoParaElUsuario;
use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Usuario;
use App\Models\Permanencia\Alerta;
use App\Models\Permanencia\NivelRiesgo;
use App\Models\Permanencia\RiesgoMatricula;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * El riesgo compuesto de una matrícula: cómo se combinan sus señales.
 *
 * ── LA DECISIÓN CENTRAL: cómo se evita el doble conteo ─────────────────────
 * El pedido lo exige —«evitar doble conteo de la misma causa»— y la forma
 * ingenua no sirve. Sumar todas las alertas cuenta dos veces al alumno que
 * dispara «tres faltas seguidas» Y «asistencia bajo el 80 %» en la MISMA
 * materia: son dos formas de mirar la misma ausencia, y sumarlas le da el doble
 * de peso que a quien falta en dos materias distintas.
 *
 * Tampoco sirve quedarse con el máximo de toda la categoría: perder asistencia
 * en seis materias es peor que perderla en una, y el máximo las hace iguales.
 *
 * **Se agrupa por (categoría, materia) y dentro de cada grupo gana el mayor;
 * después se suman los grupos.** Así:
 *  - dos señales de asistencia de la MISMA materia cuentan una vez;
 *  - la misma señal en dos materias cuenta dos veces, que es lo correcto;
 *  - y una señal académica y una financiera se suman, porque son dos frentes.
 *
 * Las señales sin materia —promedio, adeudos, expediente— caen en el grupo
 * `(categoría, null)`, que es su propio grupo: no compiten con las de materia.
 *
 * ── El aporte de una alerta ────────────────────────────────────────────────
 * `peso` de la versión × el factor de su severidad. Las dos cosas las configura
 * la escuela: el peso al escribir la regla y la severidad al versionarla.
 * **No hay ningún número mágico aquí** salvo los factores de severidad, que son
 * el orden de magnitud entre «informativo» y «crítico» y están en una constante
 * a la vista.
 *
 * ── El DECAIMIENTO no es una fórmula: es recalcular ────────────────────────
 * El puntaje sale de las alertas ABIERTAS. Cuando una se resuelve —porque la
 * situación mejoró— deja de sumar, y el riesgo baja solo en la siguiente
 * corrida. No hace falta una curva de olvido, y meterla haría que el número
 * cambiara sin que nada hubiera pasado, que es lo contrario de explicable.
 *
 * **Y una DESCARTADA tampoco suma**: una persona dijo que no amerita. Contarla
 * mantendría alto el riesgo de alguien a quien ya se revisó, y enseñaría que
 * descartar no sirve de nada.
 *
 * ── Nada de esto EJECUTA nada ──────────────────────────────────────────────
 * No cambia la situación de la matrícula, no bloquea, no da de baja, no manda
 * avisos. Calcula y escribe su propia fila. El pedido lo prohíbe con esas
 * palabras y una prueba lo vigila.
 */
class CalculadoraDeRiesgo
{
    /**
     * Cuánto pesa cada severidad, en relación con las demás.
     *
     * Es lo único no configurable del cálculo, y está aquí a la vista en vez de
     * repartido: son el orden de magnitud entre lo que se anota y lo que se
     * atiende hoy. Una escuela que quiera mover eso mueve la SEVERIDAD de sus
     * reglas, que es la palanca que le corresponde — cambiar estos factores
     * cambiaría el significado de la palabra «alto» para todo el mundo.
     */
    public const FACTOR = [
        'informativo' => 0,
        'bajo' => 1,
        'medio' => 3,
        'alto' => 6,
        'critico' => 12,
    ];

    /**
     * Recalcula el riesgo de una matrícula y lo guarda SI cambió.
     *
     * @param  bool  $seco  calcula y no escribe
     */
    public function recalcular(
        MatriculaOferta $matricula,
        ?int $corridaId = null,
        bool $seco = false,
        ?CarbonImmutable $hoy = null,
    ): array {
        $momento = $hoy ?? CarbonImmutable::now();

        $alertas = Alerta::query()
            ->abiertas()
            /*
             * Las DESCARTADAS no cuentan. Una persona dijo que no amerita, y
             * mantener alto el riesgo de alguien a quien ya se revisó enseña que
             * descartar no sirve de nada.
             */
            ->where('estado_triage', '!=', Alerta::DESCARTADA)
            ->where('matricula_oferta_id', $matricula->id)
            ->with('categoria:id,clave,nombre,color', 'regla:id,nombre')
            ->get();

        [$puntaje, $desglose] = $this->puntuar($alertas);

        $nivel = NivelRiesgo::paraPuntaje($puntaje);

        if ($nivel === null) {
            /*
             * Sin catálogo de niveles no se inventa uno. Es el mismo criterio
             * que `sin_datos` en el motor: afirmar «riesgo bajo» sobre una
             * escuela que no ha configurado sus umbrales sería decir algo que
             * nadie decidió.
             */
            return ['guardado' => false, 'motivo' => 'la escuela no tiene niveles de riesgo configurados'];
        }

        $vigente = RiesgoMatricula::query()->vigenteDe($matricula->id)->first();

        // Sólo se escribe cuando algo se mueve: un renglón diario por alumno
        // serían 1.8 millones al año diciendo «sigue igual».
        if ($vigente !== null
            && $vigente->nivel_id === $nivel->id
            && $vigente->puntaje === $puntaje) {
            return ['guardado' => false, 'motivo' => 'sin cambios', 'puntaje' => $puntaje,
                'nivel' => $nivel->clave];
        }

        if ($seco) {
            return ['guardado' => false, 'motivo' => 'modo seco', 'puntaje' => $puntaje,
                'nivel' => $nivel->clave];
        }

        $fila = RiesgoMatricula::create([
            'matricula_oferta_id' => $matricula->id,
            'calculado_en' => $momento,
            'nivel_id' => $nivel->id,
            'puntaje' => $puntaje,
            'desglose' => $desglose,
            /*
             * De dónde venía. Se copia del vigente en vez de leerse al mirar:
             * así «subió de medio a alto» se puede contestar de una fila, sin
             * buscar la anterior — y sobre todo, sin que el borrado de una fila
             * vieja cambie lo que dice la nueva.
             */
            'nivel_anterior_id' => $vigente?->nivel_id,
            'puntaje_anterior' => $vigente?->puntaje,
            'corrida_id' => $corridaId,
        ]);

        return ['guardado' => true, 'puntaje' => $puntaje, 'nivel' => $nivel->clave,
            'subio' => $vigente !== null && $puntaje > $vigente->puntaje, 'fila' => $fila->id];
    }

    /**
     * El puntaje y su desglose, sin doble conteo.
     *
     * @param  Collection<int, Alerta>  $alertas
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function puntuar(Collection $alertas): array
    {
        /*
         * El grupo es (categoría, materia). Las señales sin materia caen en su
         * propio grupo con la clave `null`: no compiten con las de materia
         * porque no hablan de lo mismo — «tu promedio general» no es «tu
         * asistencia en Cálculo».
         */
        $grupos = $alertas->groupBy(
            fn (Alerta $a) => $a->categoria_id.':'.($a->asignatura_grupo_id ?? 'sin_materia'),
        );

        $puntaje = 0;
        $porCategoria = [];
        $descartadasPorDuplicado = [];

        foreach ($grupos as $grupo) {
            /*
             * Dentro del grupo gana la MAYOR y las demás se anotan como
             * absorbidas. Anotarlas importa: sin eso, quien mire el desglose
             * verá tres alertas y un aporte que sólo explica una, y no habrá
             * forma de saber si faltó algo o si se descontó a propósito.
             */
            $ordenadas = $grupo->sortByDesc(fn (Alerta $a) => $this->aporteDe($a))->values();
            $mayor = $ordenadas->first();
            $aporte = $this->aporteDe($mayor);

            $puntaje += $aporte;

            $clave = $mayor->categoria?->clave ?? 'sin_categoria';

            $porCategoria[$clave] ??= [
                'nombre' => $mayor->categoria?->nombre,
                'color' => $mayor->categoria?->color,
                'aporte' => 0,
                'senales' => [],
            ];

            $porCategoria[$clave]['aporte'] += $aporte;
            $porCategoria[$clave]['senales'][] = [
                'alerta' => $mayor->id,
                'regla' => $mayor->regla?->nombre,
                'severidad' => $mayor->severidad,
                'materia' => $mayor->asignatura_grupo_id,
                'aporte' => $aporte,
            ];

            foreach ($ordenadas->skip(1) as $absorbida) {
                $descartadasPorDuplicado[] = [
                    'alerta' => $absorbida->id,
                    'regla' => $absorbida->regla?->nombre,
                    'aporte_que_habria_tenido' => $this->aporteDe($absorbida),
                    'motivo' => 'Ya cuenta otra señal de la misma categoría sobre lo mismo.',
                ];
            }
        }

        return [$puntaje, [
            'por_categoria' => $porCategoria,
            'no_contadas_por_duplicado' => $descartadasPorDuplicado,
            'total' => $puntaje,
            'como_se_calcula' => 'Dentro de cada categoría y materia cuenta la señal más grave; '
                .'los grupos se suman. Una señal descartada por una persona no cuenta.',
        ]];
    }

    /**
     * Lo que aporta una alerta: peso de su regla × factor de su severidad.
     *
     * Una severidad desconocida aporta CERO y no un valor por omisión: si
     * alguien introduce una que el sistema no conoce, el lado seguro es no subir
     * el riesgo de nadie por algo que no se sabe leer.
     */
    public function aporteDe(Alerta $alerta): int
    {
        $peso = (int) ($alerta->version?->peso ?? 1);

        return $peso * (self::FACTOR[$alerta->severidad] ?? 0);
    }

    /**
     * Ajustar el nivel a mano, con justificación.
     *
     * ── El calculado se CONSERVA ───────────────────────────────────────────
     * Se escribe una fila nueva con las dos cifras: la que salió del cálculo y
     * la que puso la persona. Sobrescribir haría imposible saber que hubo un
     * ajuste, y con eso se pierde lo único que lo hace legítimo — que alguien se
     * hizo responsable.
     *
     * ── Y el motivo es OBLIGATORIO ─────────────────────────────────────────
     * Reduce o aumenta lo que la escuela va a atender. Sin la razón escrita,
     * dentro de un año nadie puede explicar por qué esta persona figuraba en
     * «bajo» mientras sus señales decían otra cosa.
     */
    public function ajustar(
        MatriculaOferta $matricula,
        NivelRiesgo $nivel,
        string $motivo,
        ?Usuario $quien,
    ): RiesgoMatricula {
        AvisoParaElUsuario::aMenosQue(
            $quien?->can('validar-alertas') === true,
            403,
            'Tu rol no puede ajustar el nivel de riesgo.',
        );

        AvisoParaElUsuario::si(
            trim($motivo) === '',
            422,
            'Ajustar el nivel exige decir por qué: es lo que permite explicarlo dentro de un año.',
        );

        $vigente = RiesgoMatricula::query()->vigenteDe($matricula->id)->first();

        AvisoParaElUsuario::si(
            $vigente === null,
            422,
            'Todavía no hay un riesgo calculado para esta matrícula: el motor no la ha evaluado.',
        );

        return RiesgoMatricula::create([
            'matricula_oferta_id' => $matricula->id,
            'calculado_en' => now(),
            // El CALCULADO se copia tal cual: es lo que se está ajustando y
            // tiene que quedar a la vista al lado del ajuste.
            'nivel_id' => $vigente->nivel_id,
            'puntaje' => $vigente->puntaje,
            'desglose' => $vigente->desglose,
            'nivel_anterior_id' => $vigente->nivelQueManda()?->id,
            'puntaje_anterior' => $vigente->puntaje,
            'nivel_ajustado_id' => $nivel->id,
            'ajuste_motivo' => trim($motivo),
            'ajustado_por' => $quien?->id,
            'ajustado_en' => now(),
        ]);
    }
}
