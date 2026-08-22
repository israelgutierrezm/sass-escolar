<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Admisiones\Aspirante;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;
use App\Services\ProgresoSolicitud;

/**
 * Cómo va la solicitud del interesado, paso por paso.
 *
 * ── Por qué existe ────────────────────────────────────────────────────────
 * Porque sin ella el aspirante entra al panel y no ve NADA. Medido: de los seis
 * roles base, el suyo era el que menos tarjetas alcanzaba —ninguna—, así que su
 * primera pantalla después de crear la cuenta estaba en blanco. Y es justo la
 * persona a la que más falta le hace saber qué sigue: acaba de llegar y no
 * conoce el sistema.
 *
 * ── El avance NO se recalcula aquí ────────────────────────────────────────
 * Lo da {@see ProgresoSolicitud}, que es el mismo que pinta `/mi-solicitud` y
 * el mismo que consulta `ConvertidorAspirante` para decidir si ya se puede
 * matricular. Un segundo cálculo sería la manera de que el panel diga «80 %» y
 * el portal «60 %» sin que nadie sepa cuál miente.
 *
 * Ojo con su forma: `para()` devuelve `['pasos' => [...], 'porcentaje' => ...]`,
 * no la lista de pasos. Indexar el resumen entero ya causó un bug que hizo
 * fallar ABIERTA una regla de seguridad, y está anotado en la bitácora.
 */
class MiSolicitudEnCurso implements TarjetaPanel
{
    /** Cuántos faltantes se nombran antes de resumir el resto. */
    private const FALTANTES_A_LA_VISTA = 2;

    public function __construct(private readonly ProgresoSolicitud $progreso) {}

    public function clave(): string
    {
        return 'mi-solicitud-avance';
    }

    public function titulo(): string
    {
        return 'Mi solicitud';
    }

    public function permiso(): ?string
    {
        return 'llenar-mi-solicitud';
    }

    public function tipo(): string
    {
        return 'lista';
    }

    public function ancho(): int
    {
        return 2;
    }

    /** El mismo trazo que la sección «Mi solicitud» en el menú: son lo mismo. */
    public function icono(): string
    {
        return 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z';
    }

    public function datos(Usuario $usuario): ?array
    {
        if ($usuario->persona_id === null) {
            return null;
        }

        /*
         * Quien conserva el rol de aspirante después de convertirse en alumno
         * tiene el permiso y ya no tiene solicitud. Ésa es la regla del null por
         * «no aplica a esta persona», no un caso raro: pasa en cada conversión.
         */
        $aspirante = Aspirante::query()
            ->where('persona_id', $usuario->persona_id)
            ->orderByDesc('id')
            ->first();

        if ($aspirante === null) {
            return null;
        }

        $progreso = $this->progreso->para($aspirante);

        return [
            'renglones' => $this->renglones($progreso['pasos']),
            'pie' => $this->pie((int) $progreso['porcentaje'], $progreso),
            'enlace' => '/mi-solicitud',
        ];
    }

    /**
     * Un renglón por paso que APLICA.
     *
     * El portal sí pinta los que no aplican, atenuados, porque allá son un
     * recorrido numerado y esconder el tercero renumeraría los de abajo. Aquí no
     * hay numeración y un renglón «Tu pago — No aplica» ocupa el sitio de algo
     * accionable. El paso de datos siempre aplica, así que la lista nunca queda
     * vacía y la tarjeta no se cae por este filtro.
     *
     * @param  array<int, array<string, mixed>>  $pasos
     * @return array<int, array<string, mixed>>
     */
    private function renglones(array $pasos): array
    {
        return collect($pasos)
            ->filter(fn (array $paso) => $paso['aplica'])
            ->map(fn (array $paso) => [
                'etiqueta' => $paso['titulo'],
                'valor' => $paso['completo'] ? 'Listo' : $paso['detalle'],
                'detalle' => $paso['completo'] ? null : $this->faltantes($paso['faltantes']),
                'pie' => null,
                /*
                 * Sin barra por renglón: un paso está hecho o no lo está, y una
                 * barra al 0 % o al 100 % finge una granularidad que el servicio
                 * no tiene. En este proyecto `progreso` siempre es una fracción
                 * real —créditos sobre el total del plan, por ejemplo—.
                 */
                'progreso' => null,
                /*
                 * Y sin alerta: es la lista de tareas del propio interesado, no
                 * una cola vencida. En rojo, una solicitud recién empezada
                 * parecería un problema en vez de un trámite en curso.
                 */
                'alerta' => null,
                'enlace' => null,
            ])
            ->values()
            ->all();
    }

    /** @param  array<int, string>  $faltantes */
    private function faltantes(array $faltantes): ?string
    {
        $lista = collect($faltantes);

        if ($lista->isEmpty()) {
            return null;
        }

        $muestra = $lista->take(self::FALTANTES_A_LA_VISTA)->implode(' · ');
        $resto = $lista->count() - self::FALTANTES_A_LA_VISTA;

        return $resto > 0 ? "{$muestra} y {$resto} más" : $muestra;
    }

    /** @param  array<string, mixed>  $progreso */
    private function pie(int $porcentaje, array $progreso): string
    {
        return $porcentaje === 100
            ? 'Completa. La escuela revisará lo que enviaste.'
            : "{$porcentaje} % · {$progreso['completos']} de {$progreso['total']} pasos";
    }
}
