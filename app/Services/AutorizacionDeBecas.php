<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finanzas\Beca;
use App\Models\Finanzas\BecaAlumno;
use App\Models\Finanzas\BecaAlumnoAutorizacion;
use App\Models\Finanzas\BecaAlumnoMovimiento;
use App\Models\Finanzas\NivelAutorizacionBeca;
use App\Models\Identidad\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quién tiene que firmar una beca antes de que descuente.
 *
 * ── La autorización BLOQUEA, no anota ──────────────────────────────────────
 * `becas_alumno.autorizado_por` ya guardaba quién la dio, pero se escribía
 * junto con la beca y ésta nacía ACTIVA: era un dato del acta, no una puerta.
 * Aquí una beca que requiere firmas nace `por_autorizar` y no descuenta nada,
 * y no hace falta ninguna guarda nueva para conseguirlo: `aplicaEn()` exige
 * ACTIVA y `CalculadorCargo::becasDe()` sólo mira las activas. El estado ES la
 * defensa; una comprobación aparte sería una segunda verdad que algún día
 * divergiría.
 *
 * ── El umbral se mide sobre la BECA ────────────────────────────────────────
 * No sobre el dinero que acabará descontando: una beca del 40 % no tiene
 * importe hasta que existen los cargos, y quién firma hay que decidirlo al
 * otorgarla. Por eso un nivel declara su `modo` y sólo mira las becas de su
 * misma escala — comparar 0.40 contra un umbral de 5 000 sería comparar cosas
 * distintas—.
 *
 * ── Dos niveles firmados por la misma persona son UN nivel ─────────────────
 * Quien ya firmó uno no puede firmar otro de la misma beca, aunque tenga los
 * dos roles. Sin esa regla, la dirección general —que suele tener varios—
 * cerraría sola una autorización de tres niveles y la escala no serviría de
 * nada. A quien la otorgó NO se le prohíbe: normalmente es el primer nivel, y
 * lo que impide que cierre ella sola es esta misma regla.
 *
 * ── Quién firma sale de los roles ASIGNADOS, no del activo ─────────────────
 * Igual que en el calendario: una firma pendiente no puede desaparecer porque
 * alguien conmutó de rol para revisar otra cosa.
 */
class AutorizacionDeBecas
{
    /**
     * Los niveles que esta beca dispara, del primero al último.
     *
     * Sólo los de su misma escala y con el umbral ya alcanzado. Una beca por
     * debajo de todos los umbrales no dispara ninguno, y entonces se otorga
     * activa como siempre: la escuela que no configure niveles no nota nada.
     */
    public function nivelesPara(Beca $beca): Collection
    {
        return NivelAutorizacionBeca::query()
            ->activos()
            ->where('modo', $beca->modo)
            ->where('desde', '<=', (float) $beca->valor)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    public function requiereAutorizacion(Beca $beca): bool
    {
        return $this->nivelesPara($beca)->isNotEmpty();
    }

    /**
     * Abre las firmas pendientes de una beca recién otorgada.
     *
     * Las filas nacen VACÍAS. Es lo que permite decir qué falta —«la dirección
     * todavía no firma»— en vez de sólo cuántas firmas van: creándolas al
     * firmar, una beca sin autorizar sería indistinguible de una que no
     * requería ninguna.
     *
     * @return int cuántas firmas quedaron pendientes (0 = no requiere)
     */
    public function abrir(BecaAlumno $becaAlumno, Beca $beca): int
    {
        $niveles = $this->nivelesPara($beca);

        foreach ($niveles as $nivel) {
            BecaAlumnoAutorizacion::create([
                'beca_alumno_id' => $becaAlumno->id,
                'nivel_id' => $nivel->id,
            ]);
        }

        return $niveles->count();
    }

    /** Las firmas de una beca, con quién falta y quién ya firmó. */
    public function autorizaciones(BecaAlumno $becaAlumno): Collection
    {
        return BecaAlumnoAutorizacion::query()
            ->where('beca_alumno_id', $becaAlumno->id)
            ->with(['nivel.rol:id,name,nombre', 'usuario.persona:id,nombre,primer_apellido,segundo_apellido'])
            ->get()
            ->sortBy(fn (BecaAlumnoAutorizacion $a) => [$a->nivel?->orden ?? 0, $a->nivel_id])
            ->values();
    }

    public function faltanFirmas(BecaAlumno $becaAlumno): bool
    {
        return BecaAlumnoAutorizacion::query()
            ->where('beca_alumno_id', $becaAlumno->id)
            ->whereNull('autorizada_en')
            ->exists();
    }

    /**
     * Por qué este usuario no puede firmar esta autorización, o null si puede.
     *
     * Devuelve la RAZÓN y no un booleano: un «no se puede» pelado manda a la
     * gente a soporte, y aquí las tres razones se resuelven de formas
     * distintas.
     */
    public function motivoParaNoFirmar(Usuario $usuario, BecaAlumnoAutorizacion $autorizacion): ?string
    {
        if ($autorizacion->estaFirmada()) {
            return 'Ese nivel ya está firmado.';
        }

        $rol = $autorizacion->nivel?->rol_id;
        $roles = $usuario->rolesDisponibles()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($rol === null || ! in_array((int) $rol, $roles, true)) {
            $quien = $autorizacion->nivel?->rol?->nombre ?: $autorizacion->nivel?->rol?->name;

            return "Ese nivel lo firma «{$quien}», y no es uno de tus roles.";
        }

        // Dos niveles firmados por la misma persona son un nivel.
        $yaFirmo = BecaAlumnoAutorizacion::query()
            ->where('beca_alumno_id', $autorizacion->beca_alumno_id)
            ->where('usuario_id', $usuario->getKey())
            ->whereNotNull('autorizada_en')
            ->exists();

        if ($yaFirmo) {
            return 'Ya firmaste otro nivel de esta beca. Falta que la firme alguien más.';
        }

        return null;
    }

    /**
     * Firma un nivel. Cuando ya no falta ninguno, la beca se ACTIVA y sus
     * cargos pendientes se recalculan con el descuento.
     *
     * Va en transacción y la firma se escribe con un `update` condicionado a
     * que siga vacía: dos personas pulsando a la vez firmarían las dos, y la
     * segunda pisaría a la primera dejando en el acta a quien no cerró el
     * nivel.
     *
     * @return array{firmada: bool, activada: bool, cargos: int, motivo: ?string}
     */
    public function firmar(
        Usuario $usuario,
        BecaAlumnoAutorizacion $autorizacion,
        ?string $motivo,
        GeneradorAdeudos $generador,
        EvaluadorBecas $evaluador,
    ): array {
        $impedimento = $this->motivoParaNoFirmar($usuario, $autorizacion);

        if ($impedimento !== null) {
            return ['firmada' => false, 'activada' => false, 'cargos' => 0, 'motivo' => $impedimento];
        }

        return DB::transaction(function () use ($usuario, $autorizacion, $motivo, $generador, $evaluador) {
            $tomada = BecaAlumnoAutorizacion::query()
                ->whereKey($autorizacion->getKey())
                ->whereNull('autorizada_en')
                ->update([
                    'usuario_id' => $usuario->getKey(),
                    'autorizada_en' => now(),
                    'motivo' => $motivo,
                    'updated_by' => $usuario->getKey(),
                    'updated_at' => now(),
                ]);

            if ($tomada === 0) {
                return ['firmada' => false, 'activada' => false, 'cargos' => 0, 'motivo' => 'Ese nivel ya está firmado.'];
            }

            $becaAlumno = BecaAlumno::with('matricula')->findOrFail($autorizacion->beca_alumno_id);
            $nivel = $autorizacion->nivel?->nombre ?? 'un nivel';

            $evaluador->registrar($becaAlumno, BecaAlumnoMovimiento::AUTORIZADA, "Firmó {$nivel}.".($motivo !== null ? " {$motivo}" : ''));

            if ($this->faltanFirmas($becaAlumno)) {
                return ['firmada' => true, 'activada' => false, 'cargos' => 0, 'motivo' => null];
            }

            // La última firma es la que enciende la beca. Antes de este momento
            // no descontaba nada, así que aquí es donde hay que recomponer los
            // cargos que siguen pendientes.
            $becaAlumno->update(['estatus' => BecaAlumno::ACTIVA]);
            $evaluador->registrar($becaAlumno, BecaAlumnoMovimiento::EN_VIGOR, 'Ya no falta ninguna firma.');

            $cargos = $becaAlumno->matricula !== null
                ? $generador->recalcularPendientes($becaAlumno->matricula)
                : 0;

            return ['firmada' => true, 'activada' => true, 'cargos' => $cargos, 'motivo' => null];
        });
    }

    /**
     * La cola de firmas de una persona: lo que ESTÁ esperando su rol.
     *
     * Se excluye lo que ella misma ya firmó en otro nivel —no puede firmarlo—
     * porque una cola con renglones que no se pueden atender enseña a ignorar
     * la cola.
     */
    public function pendientesDe(Usuario $usuario): Collection
    {
        $roles = $usuario->rolesDisponibles()->pluck('id')->all();

        if ($roles === []) {
            return collect();
        }

        $yaFirmadas = BecaAlumnoAutorizacion::query()
            ->where('usuario_id', $usuario->getKey())
            ->whereNotNull('autorizada_en')
            ->select('beca_alumno_id');

        return BecaAlumnoAutorizacion::query()
            ->whereNull('autorizada_en')
            ->whereIn('nivel_id', NivelAutorizacionBeca::query()->whereIn('rol_id', $roles)->select('id'))
            ->whereNotIn('beca_alumno_id', $yaFirmadas)
            ->with([
                'nivel.rol:id,name,nombre',
                'becaAlumno.beca:id,clave,nombre,modo,valor',
                'becaAlumno.ciclo:id,nombre',
                'becaAlumno.matricula.persona:id,nombre,primer_apellido,segundo_apellido',
                'becaAlumno.matricula.oferta:id,campus_id,programa_academico_id',
                'becaAlumno.matricula.oferta.programaAcademico:id,nombre',
            ])
            ->get()
            ->sortBy('id')
            ->values();
    }

    /**
     * Un nivel se APAGA, no se borra: las firmas ya dadas lo nombran, y las
     * pendientes que lo esperan se quedan esperándolo —apagarlo cambia a quién
     * se le pedirá firma de aquí en adelante, no deshace lo que ya se pidió—.
     */
    public function motivoParaNoApagar(NivelAutorizacionBeca $nivel): ?string
    {
        $pendientes = BecaAlumnoAutorizacion::query()
            ->where('nivel_id', $nivel->id)
            ->whereNull('autorizada_en')
            ->count();

        return $pendientes > 0
            ? "Hay {$pendientes} beca(s) esperando la firma de este nivel. Fírmalas o revócalas antes de apagarlo."
            : null;
    }
}
