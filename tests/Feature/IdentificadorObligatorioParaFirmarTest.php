<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Emision\Certificacion;
use App\Models\Emision\LoteCertificacion;
use App\Services\ValidadorDec;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaEscuelaDePrueba;
use Tests\TenantTestCase;

/**
 * Un lote no se firma si al campus, la carrera o una asignatura les falta su
 * identificador oficial.
 *
 * ── Por qué esto tiene que BLOQUEAR y no sólo avisar ───────────────────────
 * Porque sin identificador el certificado no fallaba: caía en silencio a la
 * clave —«CENTRO»— y luego al id local, y el XSD lo aceptaba, porque esos
 * atributos son `xs:string` sin patrón. O sea que el documento pasaba la
 * validación de esquema llevando un número que la SEP nunca asignó, y eso se
 * descubre cuando el web service lo rechaza… o cuando no lo rechaza y queda
 * timbrado un documento oficial que apunta a nada.
 *
 * Un aviso que se puede ignorar no sirve para eso: lo que hace obligatorio un
 * dato es que sin él no se pueda avanzar.
 */
class IdentificadorObligatorioParaFirmarTest extends TenantTestCase
{
    use CreaEscuelaDePrueba;

    public function test_sin_identificador_de_campus_no_se_firma(): void
    {
        [$lote, $escuela] = $this->loteDe();

        DB::table('campus')->where('id', $escuela['campus'])->update(['identificador' => null]);

        $this->assertTrue(
            $this->seQuejaDe(app(ValidadorDec::class)->validarLote($lote), 'campus'),
            'El lote se dejó firmar con un campus sin identificador oficial.',
        );
    }

    public function test_sin_identificador_de_carrera_no_se_firma(): void
    {
        [$lote, $escuela] = $this->loteDe();

        // Vacío y no null: en `carreras` la columna es NOT NULL, así que lo que
        // de verdad puede llegar —de una carga masiva, de un dedazo— es la
        // cadena vacía. Probar con null sería probar algo que la base impide.
        DB::table('carreras')->where('id', $escuela['carrera'])->update(['identificador' => '']);

        $this->assertTrue(
            $this->seQuejaDe(app(ValidadorDec::class)->validarLote($lote), 'carrera'),
            'El lote se dejó firmar con una carrera sin identificador oficial.',
        );
    }

    /**
     * Y la asignatura se dice POR SU NOMBRE.
     *
     * Quien va a capturarla necesita saber cuál es; «faltan 7 identificadores»
     * obliga a buscarlas una por una.
     */
    public function test_la_asignatura_sin_identificador_se_dice_por_su_nombre(): void
    {
        [$lote, $escuela] = $this->loteDe();

        $asignatura = $this->conHistorial($escuela);

        DB::table('asignaturas')->where('id', $asignatura)->update(['identificador' => '']);

        $nombre = (string) DB::table('asignaturas')->where('id', $asignatura)->value('nombre');

        $this->assertTrue(
            $this->seQuejaDe(app(ValidadorDec::class)->validarLote($lote), $nombre),
            "No se nombró la asignatura «{$nombre}» a la que le falta el identificador.",
        );
    }

    /** Con todo capturado, esa comprobación no estorba. */
    public function test_con_los_identificadores_puestos_no_se_queja_de_ellos(): void
    {
        [$lote] = $this->loteDe();

        $errores = app(ValidadorDec::class)->validarLote($lote);

        $this->assertFalse(
            $this->mencionan($errores, 'identificador oficial'),
            'Se quejó de identificadores estando todos puestos: '.implode(' | ', $errores),
        );
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /**
     * ¿Hay un error que hable de ESE sujeto Y del identificador que le falta?
     *
     * Las dos cosas juntas, y no sólo el sujeto. Buscando «campus» a secas, la
     * prueba pasaba IGUAL con la comprobación quitada: casaba con el mensaje que
     * ya existía sobre la entidad federativa del campus. Comprobado mutando.
     *
     * @param  array<int, string>  $errores
     */
    private function seQuejaDe(array $errores, string $sujeto): bool
    {
        foreach ($errores as $error) {
            $texto = mb_strtolower($error);

            if (str_contains($texto, mb_strtolower($sujeto)) && str_contains($texto, 'no tiene identificador oficial')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $errores */
    private function mencionan(array $errores, string $texto): bool
    {
        foreach ($errores as $error) {
            if (str_contains(mb_strtolower($error), mb_strtolower($texto))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un lote con una matrícula dentro, y la escuela que la sostiene.
     *
     * Los identificadores se ponen A PROPÓSITO en el alta: cada prueba borra
     * SÓLO el que le toca, y así lo que falla es lo que esa prueba dice probar
     * y no un dato que la escuela de prueba nunca tuvo.
     *
     * @return array{0: LoteCertificacion, 1: array<string, int>}
     */
    private function loteDe(): array
    {
        $escuela = $this->alumnoInscrito();

        DB::table('campus')->where('id', $escuela['campus'])->update(['identificador' => 'CAMPUS-1']);
        DB::table('carreras')->where('id', $escuela['carrera'])->update(['identificador' => 'CARRERA-1']);

        $lote = LoteCertificacion::query()->create([
            'folio' => 'LOTE-'.uniqid(),
            'nombre' => 'Lote de prueba',
            'tipo' => LoteCertificacion::TOTAL,
        ]);

        Certificacion::query()->create([
            'lote_id' => $lote->id,
            'matricula_oferta_id' => $escuela['matricula'],
            'estado' => 'pendiente',
        ]);

        return [$lote->fresh(), $escuela];
    }

    /** Una materia asentada en el historial de esa matrícula. */
    private function conHistorial(array $escuela): int
    {
        $unico = uniqid();

        $asignatura = $this->fila('asignaturas', [
            'identificador' => "ASI-{$unico}",
            'clave' => "A-{$unico}",
            'nombre' => "Materia {$unico}",
            'creditos' => 5,
            'tipo_asignatura_id' => $this->deCatalogo('tipos_asignatura'),
        ]);

        $planMateria = $this->fila('plan_materias', [
            'plan_id' => $escuela['plan'],
            'asignatura_id' => $asignatura,
            'periodo' => 1,
            'clave_en_plan' => "PM-{$unico}",
        ]);

        $this->fila('historial', [
            'matricula_oferta_id' => $escuela['matricula'],
            'plan_materia_id' => $planMateria,
            'ciclo_id' => $this->cicloDePrueba('CIC-'.$unico),
            'calificacion' => 9,
            'estatus_id' => $this->situacionCon('estatus_historial', 'aprobada'),
            'tipo_evaluacion_id' => $this->deCatalogo('tipos_evaluacion'),
        ]);

        return $asignatura;
    }
}
