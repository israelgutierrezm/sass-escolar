<?php

declare(strict_types=1);

namespace App\Services\Caja;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Finanzas\Caja;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\SesionCaja;
use App\Models\Identidad\Usuario;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Abrir el turno, cobrar dentro de él y cerrarlo cuadrando el efectivo.
 *
 * ── Qué contesta el corte ──────────────────────────────────────────────────
 * Una sola pregunta: ¿lo que hay en el cajón es lo que debería? Todo lo demás
 * —los totales por método, el fondo, la autorización— existe para poder
 * contestarla y para saber a quién preguntarle cuando la respuesta es que no.
 *
 * ── Lo que se cuenta NO es todo lo cobrado ─────────────────────────────────
 * En el mismo turno entran tarjetas y transferencias, y ese dinero no pasa por
 * el cajón. El arqueo compara los billetes contra lo que declara
 * `metodos_pago.afecta_caja`; el resto sale en los totales del corte, que es
 * donde sí hay que verlo.
 */
class OperacionDeCaja
{
    public function __construct(private readonly Ajustes $ajustes) {}

    /**
     * La sesión abierta de una persona, si tiene alguna.
     *
     * Es lo que consulta `RegistradorPago` para atar el cobro a su turno SIN
     * que quien registra tenga que acordarse de pasarlo. Pedirlo como parámetro
     * habría bastado con que un solo camino lo olvidara para dejar efectivo
     * fuera del conteo, en silencio y para siempre.
     */
    public function sesionDe(?Usuario $usuario): ?SesionCaja
    {
        if ($usuario === null) {
            return null;
        }

        return SesionCaja::query()->abiertas()->where('usuario_id', $usuario->id)->first();
    }

    public function abrir(Caja $caja, Usuario $usuario, float $fondoInicial): SesionCaja
    {
        if (! $caja->activa) {
            throw new RuntimeException('Esa caja está apagada.');
        }

        if ($fondoInicial < 0) {
            throw new RuntimeException('El fondo inicial no puede ser negativo.');
        }

        try {
            return SesionCaja::create([
                'caja_id' => $caja->id,
                'usuario_id' => $usuario->id,
                'abierta_en' => Carbon::now(),
                'fondo_inicial' => $fondoInicial,
                'estatus' => SesionCaja::ABIERTA,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Los dos únicos de la base son lo que de verdad lo impide: un
            // `SELECT` previo lo pasan dos peticiones simultáneas y quedarían
            // dos turnos abiertos, con el cobro siguiente sin saber a cuál
            // pertenece. Aquí sólo se traduce a un mensaje que se entiende.
            throw new RuntimeException(
                $this->sesionDe($usuario) !== null
                    ? 'Ya tienes un turno de caja abierto. Ciérralo antes de abrir otro.'
                    : 'Esa caja ya tiene un turno abierto, de otra persona.'
            );
        }
    }

    /**
     * Lo que el turno lleva cobrado, por método.
     *
     * @return array{efectivo: float, otros: float, por_metodo: array<int, array{metodo: string, afecta_caja: bool, total: float}>}
     */
    public function totales(SesionCaja $sesion): array
    {
        $filas = Pago::query()
            ->where('sesion_caja_id', $sesion->id)
            // Sólo el dinero que de verdad entró: un pago pendiente de
            // confirmar es una promesa, y contarlo haría salir faltante todos
            // los turnos que reciban una transferencia sin confirmar.
            ->cobrados()
            ->join('metodos_pago', 'metodos_pago.id', '=', 'pagos.metodo_pago_id')
            ->groupBy('metodos_pago.id', 'metodos_pago.nombre', 'metodos_pago.afecta_caja')
            ->selectRaw('metodos_pago.nombre as metodo, metodos_pago.afecta_caja, sum(pagos.monto) as total')
            ->get();

        $porMetodo = $filas->map(fn ($f) => [
            'metodo' => (string) $f->metodo,
            'afecta_caja' => (bool) $f->afecta_caja,
            'total' => round((float) $f->total, 2),
        ])->values()->all();

        return [
            'efectivo' => round(array_sum(array_map(
                fn (array $f) => $f['afecta_caja'] ? $f['total'] : 0.0, $porMetodo
            )), 2),
            'otros' => round(array_sum(array_map(
                fn (array $f) => $f['afecta_caja'] ? 0.0 : $f['total'], $porMetodo
            )), 2),
            'por_metodo' => $porMetodo,
        ];
    }

    /** Lo que debería haber en el cajón: el fondo más lo cobrado en efectivo. */
    public function efectivoEsperado(SesionCaja $sesion): float
    {
        return round((float) $sesion->fondo_inicial + $this->totales($sesion)['efectivo'], 2);
    }

    public function cerrar(SesionCaja $sesion, Usuario $usuario, float $contado, ?string $notas = null): SesionCaja
    {
        if (! $sesion->estaAbierta()) {
            throw new RuntimeException('Ese turno ya está cerrado.');
        }

        if ($contado < 0) {
            throw new RuntimeException('El efectivo contado no puede ser negativo.');
        }

        $esperado = $this->efectivoEsperado($sesion);
        $diferencia = round($contado - $esperado, 2);
        $tolerancia = (float) $this->ajustes->entero(CatalogoAjustes::CAJA_TOLERANCIA);

        $sesion->fill([
            'cerrada_en' => Carbon::now(),
            'cerrada_por_usuario_id' => $usuario->id,
            // Congelados: recalcular el esperado al mirarlo haría que un corte
            // de hace un mes cambiara solo —basta con que alguien confirme una
            // transferencia vieja— y la diferencia dejaría de ser un hecho.
            'efectivo_esperado' => $esperado,
            'efectivo_contado' => $contado,
            'diferencia' => $diferencia,
            'notas' => $notas,
            // El cajero cierra siempre; lo que la diferencia decide es si el
            // corte queda pendiente de que alguien la explique.
            'estatus' => abs($diferencia) > $tolerancia
                ? SesionCaja::POR_AUTORIZAR
                : SesionCaja::CERRADA,
        ])->save();

        return $sesion;
    }

    public function autorizar(SesionCaja $sesion, Usuario $usuario, string $motivo): SesionCaja
    {
        if ($sesion->estatus !== SesionCaja::POR_AUTORIZAR) {
            throw new RuntimeException('Ese corte no está esperando autorización.');
        }

        // El motivo es obligatorio: una diferencia autorizada sin explicación es
        // dinero que apareció o desapareció y que nadie tuvo que justificar, que
        // es exactamente lo que el corte existe para impedir.
        $sesion->fill([
            'estatus' => SesionCaja::CERRADA,
            'motivo_diferencia' => $motivo,
            'autorizada_por_usuario_id' => $usuario->id,
            'autorizada_en' => Carbon::now(),
        ])->save();

        return $sesion;
    }

    /**
     * Por qué este cobro no se puede registrar, o null si se puede.
     *
     * Lo pregunta `RegistradorPago` antes de crear nada. La regla vive aquí y
     * no allá porque es una regla de CAJA: recibir efectivo sin un turno abierto
     * es recibir dinero que no va a entrar en ningún arqueo.
     */
    public function motivoParaNoCobrar(MetodoPago $metodo, ?Usuario $usuario): ?string
    {
        if (! $metodo->afecta_caja) {
            return null;
        }

        if (! $this->ajustes->bool(CatalogoAjustes::CAJA_EXIGE_SESION)) {
            return null;
        }

        if ($this->sesionDe($usuario) !== null) {
            return null;
        }

        return 'No hay un turno de caja abierto a tu nombre, y este cobro entra al cajón: '
            .'sin turno, ese dinero no aparecería en ningún corte. Abre tu caja en Finanzas › Caja.';
    }

    /**
     * Los cortes de los últimos turnos, para la pantalla.
     *
     * @param  array<int, int>|null  $campus  null = sin acotar
     */
    public function cortes(?array $campus = null, int $limite = 50)
    {
        return SesionCaja::query()
            ->with(['caja.campus:id,nombre', 'usuario.persona:id,nombre,primer_apellido,segundo_apellido'])
            ->when($campus !== null, fn ($q) => $q->whereHas('caja', fn ($c) => $c->whereIn('campus_id', $campus)))
            ->orderByDesc('abierta_en')
            ->limit($limite)
            ->get();
    }

    /** Cuántos cortes esperan que alguien explique su diferencia. */
    public function porAutorizar(?array $campus = null): int
    {
        return SesionCaja::query()
            ->porAutorizar()
            ->when($campus !== null, fn ($q) => $q->whereHas('caja', fn ($c) => $c->whereIn('campus_id', $campus)))
            ->count();
    }
}
