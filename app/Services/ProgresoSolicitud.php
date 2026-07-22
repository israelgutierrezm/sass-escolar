<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admisiones\Aspirante;
use App\Models\Admisiones\DocumentoRequerido;
use App\Models\Admisiones\ExpedienteDocumento;
use App\Models\Finanzas\Adeudo;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto lleva llenado un aspirante de su solicitud.
 *
 * **Los pasos son fijos para toda la escuela y no varían por campaña ni por
 * carrera** — decisión del cliente. Por eso viven aquí como código y no como
 * tabla configurable: no hay nada que la escuela deba poder reordenar, y una
 * tabla de pasos que siempre tiene las mismas tres filas sería configuración
 * falsa. Lo que SÍ es configurable —si el expediente y el pago son requisito
 * para convertir— vive en `CatalogoAjustes`.
 *
 * **Este avance NO es la etapa del CRM, y es deliberado.** El embudo lo mueve
 * promoción con su criterio; esto solo dice qué tanto llenó el interesado. Que
 * alguien haya subido sus papeles no significa que promoción lo dé por
 * "documentado": puede estar esperando validarlos, o haber hablado con él y
 * saber algo que el sistema no. Mezclarlos haría que el embudo avanzara solo y
 * dejara de reflejar el trabajo del equipo.
 *
 * Da igual quién llenó qué: el mismo cálculo sirve si lo capturó el aspirante
 * desde su portal o un administrador desde la ficha.
 */
class ProgresoSolicitud
{
    public const PASO_DATOS = 'datos';

    public const PASO_DOCUMENTOS = 'documentos';

    public const PASO_PAGO = 'pago';

    /**
     * Los pasos con su estado. Siempre los mismos y en este orden.
     *
     * @return array<string, mixed>
     */
    public function para(Aspirante $aspirante): array
    {
        $aspirante->loadMissing('persona', 'ofertaInteres.carrera');

        $pasos = [
            $this->pasoDatos($aspirante),
            $this->pasoDocumentos($aspirante),
            $this->pasoPago($aspirante),
        ];

        // Los pasos que no aplican —no hay documentos configurados, no hay
        // cargos— no cuentan para el porcentaje. Si contaran, una escuela que
        // no cobra ficha dejaría a todos sus aspirantes atascados en 66%.
        $aplicables = array_values(array_filter($pasos, fn (array $p) => $p['aplica']));
        $completos = array_filter($aplicables, fn (array $p) => $p['completo']);

        return [
            'pasos' => $pasos,
            'porcentaje' => $aplicables === []
                ? 100
                : (int) round((count($completos) / count($aplicables)) * 100),
            'completos' => count($completos),
            'total' => count($aplicables),
            // El primero sin terminar: es a donde manda el botón "continuar".
            'siguiente' => collect($aplicables)->firstWhere('completo', false)['clave'] ?? null,
        ];
    }

    /**
     * Datos personales. Se piden los que hacen falta para poder matricular
     * después: sin CURP no hay documento oficial que cuadre, y sin contacto no
     * hay a quién llamarle.
     *
     * @return array<string, mixed>
     */
    private function pasoDatos(Aspirante $aspirante): array
    {
        $persona = $aspirante->persona;

        $faltantes = [];

        foreach ([
            'nombre' => 'Nombre',
            'primer_apellido' => 'Primer apellido',
            'curp' => 'CURP',
            'email' => 'Correo electrónico',
            'celular' => 'Celular',
            'fecha_nacimiento' => 'Fecha de nacimiento',
        ] as $campo => $etiqueta) {
            if (blank($persona?->{$campo})) {
                $faltantes[] = $etiqueta;
            }
        }

        if ($aspirante->oferta_interes_id === null) {
            $faltantes[] = 'Programa de interés';
        }

        return [
            'clave' => self::PASO_DATOS,
            'titulo' => 'Tus datos',
            'descripcion' => 'Lo mínimo para poder registrarte formalmente.',
            'aplica' => true,
            'completo' => $faltantes === [],
            'faltantes' => $faltantes,
            'detalle' => $faltantes === []
                ? 'Completo'
                : count($faltantes).' por capturar',
        ];
    }

    /**
     * Documentación. Solo cuenta la OBLIGATORIA del ámbito aspirante: pedirle
     * al interesado que suba lo opcional para poder avanzar convertiría un
     * "puedes entregarlo" en un requisito.
     *
     * Un documento RECHAZADO no cuenta como entregado, aunque el archivo esté:
     * quien lo revisó dijo que no sirve, y dar por completo ese paso escondería
     * justo lo que hay que corregir.
     *
     * @return array<string, mixed>
     */
    private function pasoDocumentos(Aspirante $aspirante): array
    {
        $requeridos = DocumentoRequerido::query()
            ->where('obligatorio', true)
            // `ambitos()` del modelo devuelve un arreglo, no una relación: el
            // ámbito vive en un pivote sin modelo propio. Se consulta directo.
            ->whereIn('id', DB::table('documento_ambitos')
                ->where('ambito', DocumentoRequerido::AMBITO_ASPIRANTE)
                ->pluck('documento_id'))
            ->orderBy('nombre')
            ->get();

        if ($requeridos->isEmpty()) {
            return [
                'clave' => self::PASO_DOCUMENTOS,
                'titulo' => 'Tu documentación',
                'descripcion' => 'La escuela no pide documentos en esta etapa.',
                'aplica' => false,
                'completo' => true,
                'faltantes' => [],
                'detalle' => 'No aplica',
            ];
        }

        $entregados = ExpedienteDocumento::query()
            ->with('estado:id,clave')
            ->where('aspirante_id', $aspirante->id)
            ->get()
            ->reject(fn (ExpedienteDocumento $e) => $e->estado?->clave === 'rechazado')
            ->pluck('documento_id')
            ->all();

        $faltantes = $requeridos
            ->reject(fn (DocumentoRequerido $d) => in_array($d->id, $entregados, true))
            ->pluck('nombre')
            ->values()
            ->all();

        return [
            'clave' => self::PASO_DOCUMENTOS,
            'titulo' => 'Tu documentación',
            'descripcion' => 'Sube los papeles que pide la escuela. Alguien los revisará.',
            'aplica' => true,
            'completo' => $faltantes === [],
            'faltantes' => $faltantes,
            'detalle' => $faltantes === []
                ? $requeridos->count().' de '.$requeridos->count()
                : (count($entregados) > 0 ? count($entregados) : 0).' de '.$requeridos->count(),
        ];
    }

    /**
     * Pago. Solo aplica si al aspirante se le generó algún cargo: una escuela
     * que no cobra ficha no debe mostrarle un paso vacío.
     *
     * @return array<string, mixed>
     */
    private function pasoPago(Aspirante $aspirante): array
    {
        $cargos = Adeudo::query()
            ->with('concepto:id,nombre')
            ->deAspirante($aspirante->id)
            ->get();

        if ($cargos->isEmpty()) {
            return [
                'clave' => self::PASO_PAGO,
                'titulo' => 'Tu pago',
                'descripcion' => 'Todavía no hay nada que pagar.',
                'aplica' => false,
                'completo' => true,
                'faltantes' => [],
                'detalle' => 'No aplica',
            ];
        }

        $pendientes = $cargos->filter(fn (Adeudo $a) => $a->saldo() > 0);

        return [
            'clave' => self::PASO_PAGO,
            'titulo' => 'Tu pago',
            'descripcion' => 'Cubre lo que la escuela te haya generado.',
            'aplica' => true,
            'completo' => $pendientes->isEmpty(),
            'faltantes' => $pendientes->map(
                fn (Adeudo $a) => ($a->concepto?->nombre ?? 'Cargo').' — $'.number_format($a->saldo(), 2)
            )->values()->all(),
            'detalle' => $pendientes->isEmpty()
                ? 'Cubierto'
                : '$'.number_format($pendientes->sum(fn (Adeudo $a) => $a->saldo()), 2).' por pagar',
        ];
    }
}
