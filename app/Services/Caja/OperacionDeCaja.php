<?php

declare(strict_types=1);

namespace App\Services\Caja;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Finanzas\Caja;
use App\Models\Finanzas\CuentaBancaria;
use App\Models\Finanzas\DepositoCaja;
use App\Models\Finanzas\DevolucionCaja;
use App\Models\Finanzas\MetodoPago;
use App\Models\Finanzas\Pago;
use App\Models\Finanzas\SesionCaja;
use App\Models\Identidad\Usuario;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            /*
             * Lo que de verdad ENTRÓ al cajón, que no es lo mismo que lo que
             * hoy sigue siendo un cobro válido.
             *
             * `pendiente` no entra: es una promesa, y contarla haría salir
             * faltante todo turno que reciba una transferencia sin confirmar.
             * `fallido` tampoco: nunca fue dinero.
             *
             * `reembolsado` SÍ, y es la parte que se piensa al revés: ese
             * dinero entró y luego salió, y la salida se registra aparte como
             * devolución. Dejándolo fuera aquí, una devolución del mismo día
             * restaría dos veces.
             */
            ->whereIn('estatus', [Pago::ESTATUS_COMPLETADO, Pago::ESTATUS_REEMBOLSADO])
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

    /** Lo que salió del cajón en este turno. */
    public function devuelto(SesionCaja $sesion): float
    {
        return round((float) DevolucionCaja::query()
            ->where('sesion_caja_id', $sesion->id)
            ->sum('monto'), 2);
    }

    /**
     * Lo que debería haber en el cajón.
     *
     * Fondo, más lo que entró en efectivo, menos lo que se devolvió. Las tres
     * partes hacen falta: sin el fondo sale sobrante siempre por el mismo
     * importe, y sin las devoluciones sale faltante cada vez que se reembolsa
     * un pago de otro día.
     */
    public function efectivoEsperado(SesionCaja $sesion): float
    {
        return round(
            (float) $sesion->fondo_inicial
            + $this->totales($sesion)['efectivo']
            - $this->devuelto($sesion),
            2
        );
    }

    /**
     * Anota que salió dinero del cajón por devolver un pago.
     *
     * La llama `RegistradorPago` al reembolsar, igual que resuelve el turno al
     * cobrar: si hubiera que acordarse de invocarla, el camino que lo olvidara
     * dejaría la caja corta sin explicación.
     *
     * Devuelve null cuando no hay nada que anotar —el pago no era de cajón, o
     * no hay turno abierto—, que NO es un error: una transferencia devuelta no
     * saca billetes de ningún lado.
     */
    public function registrarDevolucion(Pago $pago, ?Usuario $usuario, ?string $motivo = null): ?DevolucionCaja
    {
        $pago->loadMissing('metodoPago');

        if ($pago->metodoPago?->afecta_caja !== true) {
            return null;
        }

        $sesion = $this->sesionDe($usuario);

        if ($sesion === null) {
            return null;
        }

        return DevolucionCaja::firstOrCreate(
            // Por el PAGO y no por (pago, turno): un pago se devuelve una vez, y
            // el único de la base lo sostiene contra dos peticiones a la vez.
            ['pago_id' => $pago->id],
            ['sesion_caja_id' => $sesion->id, 'monto' => (float) $pago->monto, 'motivo' => $motivo],
        );
    }

    /**
     * Por qué no se puede devolver este pago, o null si se puede.
     *
     * Misma regla que al cobrar: sacar billetes de un cajón que no está abierto
     * es dinero que no aparecerá en ningún corte.
     */
    public function motivoParaNoDevolver(Pago $pago, ?Usuario $usuario): ?string
    {
        $pago->loadMissing('metodoPago');

        if ($pago->metodoPago?->afecta_caja !== true) {
            return null;
        }

        if (! $this->ajustes->bool(CatalogoAjustes::CAJA_EXIGE_SESION)) {
            return null;
        }

        if ($this->sesionDe($usuario) !== null) {
            return null;
        }

        return 'No hay un turno de caja abierto a tu nombre, y devolver este pago saca efectivo del '
            .'cajón: sin turno, esa salida no aparecería en ningún corte. Abre tu caja en Finanzas › Caja.';
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

    /**
     * Lo que de un turno cerrado toca llevar al banco.
     *
     * Lo contado MENOS el fondo: el fondo se queda en el cajón para el turno de
     * mañana. Llevándoselo también, cada mañana habría que reponerlo y la caja
     * abriría en cero sin que nadie lo decidiera.
     */
    public function porDepositar(SesionCaja $sesion): float
    {
        return round((float) $sesion->efectivo_contado - (float) $sesion->fondo_inicial, 2);
    }

    /**
     * Los turnos cerrados cuyo efectivo todavía no llegó al banco.
     *
     * @param  array<int, int>|null  $campus  null = sin acotar
     */
    public function sesionesPorDepositar(?array $campus = null)
    {
        return SesionCaja::query()
            ->with(['caja.campus:id,nombre', 'usuario.persona:id,nombre,primer_apellido,segundo_apellido'])
            // Sólo lo CERRADO: el efectivo de un turno abierto todavía se está
            // moviendo y no se ha contado.
            ->whereNotNull('cerrada_en')
            ->whereNull('deposito_caja_id')
            ->when($campus !== null, fn ($q) => $q->whereHas('caja', fn ($c) => $c->whereIn('campus_id', $campus)))
            ->orderBy('cerrada_en')
            ->get();
    }

    /**
     * Registra que el efectivo de unos turnos se llevó al banco.
     *
     * @param  array<int, int>  $sesionIds
     */
    public function depositar(
        array $sesionIds,
        CuentaBancaria $cuenta,
        float $monto,
        string $fecha,
        ?string $referencia = null,
        ?string $notas = null,
        ?array $campus = null,
    ): DepositoCaja {
        if ($monto <= 0) {
            throw new RuntimeException('El importe del depósito debe ser mayor que cero.');
        }

        $sesiones = SesionCaja::query()->whereIn('id', $sesionIds)->with('caja')->get();

        if ($sesiones->isEmpty()) {
            throw new RuntimeException('Hay que elegir al menos un turno.');
        }

        foreach ($sesiones as $sesion) {
            if ($sesion->estaAbierta()) {
                throw new RuntimeException('Un turno abierto no se deposita: su efectivo todavía no se ha contado.');
            }

            // Un turno se deposita UNA vez: sin esto, dos capturas mandarían el
            // mismo dinero al banco dos veces sobre el papel.
            if ($sesion->deposito_caja_id !== null) {
                throw new RuntimeException('Alguno de esos turnos ya se depositó.');
            }

            // Los ids viajan en la petición: sin comprobarlo, quien está acotado
            // a un campus depositaría el efectivo de otro plantel.
            if ($campus !== null && ! in_array($sesion->caja?->campus_id, $campus, true)) {
                throw new RuntimeException('Alguno de esos turnos es de un campus que no alcanzas.');
            }
        }

        return DB::transaction(function () use ($sesiones, $cuenta, $monto, $fecha, $referencia, $notas) {
            $deposito = DepositoCaja::create([
                'cuenta_bancaria_id' => $cuenta->id,
                'monto' => $monto,
                'fecha' => $fecha,
                'referencia' => $referencia,
                'notas' => $notas,
            ]);

            SesionCaja::query()
                ->whereIn('id', $sesiones->pluck('id'))
                ->update(['deposito_caja_id' => $deposito->id]);

            return $deposito;
        });
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
