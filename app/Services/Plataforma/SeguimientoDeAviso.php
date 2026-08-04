<?php

declare(strict_types=1);

namespace App\Services\Plataforma;

use App\Enums\PrioridadAviso;
use App\Models\Identidad\PersonaRol;
use App\Models\Plataforma\Aviso;
use App\Models\Plataforma\AvisoLectura;
use Illuminate\Support\Facades\DB;

/**
 * Cómo va un aviso: a cuántos alcanzó, cuántos lo vieron y cuántos lo
 * confirmaron.
 *
 * ── Qué pregunta contesta ──────────────────────────────────────────────────
 * «Lo confirmaron doce» no dice nada por sí solo: doce de catorce es un aviso
 * que llegó y doce de trescientos es un aviso que nadie leyó. Por eso todo se
 * mide contra el universo de destinatarios, que resuelve
 * `DestinatariosDeAviso`.
 *
 * ── Y el desglose por rol ──────────────────────────────────────────────────
 * Un aviso «a toda la escuela» que confirmó el 60% puede ser un 95% entre
 * docentes y un 20% entre alumnos, y esas dos situaciones piden cosas
 * distintas. El total solo esconde justo el grupo al que hay que ir a buscar.
 */
class SeguimientoDeAviso
{
    public function __construct(private readonly DestinatariosDeAviso $destinatarios) {}

    /**
     * @return array<string, mixed>
     */
    public function de(Aviso $aviso): array
    {
        $personas = $this->destinatarios->de($aviso);
        $lecturas = $this->lecturasDe($aviso->id);

        // Sólo cuenta la lectura de quien es destinatario HOY. Alguien pudo
        // recibirlo siendo alumno y haber causado baja: su lectura ocurrió, pero
        // incluirla daría porcentajes por encima del 100%.
        $vistos = array_values(array_intersect(array_keys($lecturas), $personas));
        $confirmados = array_values(array_filter($vistos, fn (int $id) => $lecturas[$id]['confirmado'] !== null));

        return [
            'alcance' => count($personas),
            'vistos' => count($vistos),
            'confirmados' => count($confirmados),
            // Lo que falta por ver, que es a quién habría que ir a buscar.
            'sin_ver' => max(0, count($personas) - count($vistos)),
            // Dos cosas distintas: el crítico EXIGE confirmar —bloquea hasta
            // que lo hagan— y el importante la ADMITE, con su «entendido». El
            // informativo no pide nada, así que medirle confirmaciones sería
            // reprocharle no tener lo que nunca se le pidió.
            'exige_confirmacion' => $aviso->exigeConfirmacion(),
            'admite_confirmacion' => $aviso->prioridad !== PrioridadAviso::Informativo,
            'minutos_hasta_confirmar' => $this->minutosHastaConfirmar($aviso->id),
            'por_rol' => $this->porRol($personas, $lecturas),
            // Lecturas de gente que ya no es destinataria: se informa aparte en
            // vez de esconderlas, porque explican descuadres al comparar con la
            // lista de abajo.
            'fuera_de_alcance' => count(array_diff(array_keys($lecturas), $personas)),
        ];
    }

    /**
     * El desglose por rol: cuántos son, cuántos vieron y cuántos confirmaron.
     *
     * Quien tiene dos roles cuenta en los dos. La pregunta aquí es «¿llegó a
     * los docentes?», no «¿cómo se reparte el total?».
     *
     * @param  array<int, int>  $personas
     * @param  array<int, array{visto: ?string, confirmado: ?string}>  $lecturas
     * @return array<int, array<string, mixed>>
     */
    private function porRol(array $personas, array $lecturas): array
    {
        $filas = [];

        foreach ($this->destinatarios->porRol($personas) as $rol) {
            $delRol = $this->personasDelRol($rol['rol_id'], $personas);

            $vistos = array_filter($delRol, fn (int $id) => isset($lecturas[$id]));
            $confirmados = array_filter($vistos, fn (int $id) => $lecturas[$id]['confirmado'] !== null);

            $filas[] = [
                'rol' => $rol['rol'],
                'total' => $rol['total'],
                'vistos' => count($vistos),
                'confirmados' => count($confirmados),
                'sin_ver' => $rol['total'] - count($vistos),
            ];
        }

        return $filas;
    }

    /**
     * @param  array<int, int>  $personas
     * @return array<int, int>
     */
    private function personasDelRol(int $rolId, array $personas): array
    {
        return PersonaRol::query()
            ->where('rol_id', $rolId)
            ->where('activo', true)
            ->whereIn('persona_id', $personas)
            ->distinct()
            ->pluck('persona_id')
            ->all();
    }

    /**
     * Cuánto tarda la gente en confirmar, en minutos.
     *
     * Es la medida de si el aviso se atiende o se arrastra: media hora dice que
     * se leyó al entrar; tres días, que se confirmó cuando alguien fue a
     * reclamar. Null si nadie ha confirmado todavía.
     */
    private function minutosHastaConfirmar(int $avisoId): ?float
    {
        $expresion = DB::connection()->getDriverName() === 'sqlite'
            ? '(julianday(confirmado_en) - julianday(visto_en)) * 1440'
            : 'TIMESTAMPDIFF(SECOND, visto_en, confirmado_en) / 60';

        $promedio = AvisoLectura::query()
            ->where('aviso_id', $avisoId)
            ->whereNotNull('visto_en')
            ->whereNotNull('confirmado_en')
            ->avg(DB::raw($expresion));

        return $promedio === null ? null : round((float) $promedio, 1);
    }

    /**
     * @return array<int, array{visto: ?string, confirmado: ?string}>
     */
    private function lecturasDe(int $avisoId): array
    {
        return AvisoLectura::query()
            ->where('aviso_id', $avisoId)
            ->get(['persona_id', 'visto_en', 'confirmado_en'])
            ->mapWithKeys(fn (AvisoLectura $l) => [$l->persona_id => [
                'visto' => $l->visto_en?->toDateTimeString(),
                'confirmado' => $l->confirmado_en?->toDateTimeString(),
            ]])
            ->all();
    }
}
