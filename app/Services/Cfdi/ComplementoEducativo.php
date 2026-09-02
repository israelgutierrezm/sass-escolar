<?php

declare(strict_types=1);

namespace App\Services\Cfdi;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Finanzas\ConceptoPago;
use Illuminate\Support\Collection;

/**
 * Decide si una factura lleva el complemento IEDU, y lo arma.
 *
 * ── Por qué es un servicio y no cuatro líneas en el emisor ─────────────────
 * Lo preguntan DOS caminos: la pantalla, ANTES de emitir —para que quien
 * factura vea que va a salir sin complemento y pueda arreglarlo—, y el emisor,
 * al crear la factura. Escrito dos veces, el día que cambie una regla la
 * pantalla prometería una cosa y el comprobante diría otra; y el aviso previo
 * es justamente lo que evita una cancelación ante el SAT.
 *
 * ── El complemento es TODO O NADA ──────────────────────────────────────────
 * El IEDU no marca renglones: ampara el comprobante entero como pago por
 * servicios educativos. Una factura que mezcle colegiatura con una credencial
 * de reposición declararía como enseñanza algo que no lo es. Se niega y se
 * nombra la salida —facturarlos por separado—, en vez de mandarlo con un
 * importe inflado.
 *
 * ── Un pago sin concepto tampoco pasa ──────────────────────────────────────
 * `EmisorFactura` factura un pago no aplicado como «servicios educativos»
 * genéricos, que es lo que ampara. Pero ese texto es un respaldo para que el
 * renglón tenga descripción, no una afirmación de que sea enseñanza deducible:
 * darlo por bueno aquí metería un anticipo dentro de una deducción.
 */
final class ComplementoEducativo
{
    /**
     * @param  Collection<int, ConceptoPago|null>  $conceptos  uno por pago; null = pago sin aplicar
     */
    public function decidir(MatriculaOferta $matricula, Collection $conceptos): DecisionIedu
    {
        $deducibles = $conceptos->filter(fn (?ConceptoPago $c) => (bool) $c?->deducible_iedu);

        // Nada de enseñanza deducible: el complemento no viene al caso.
        if ($deducibles->isEmpty()) {
            return DecisionIedu::noAplica();
        }

        if ($deducibles->count() !== $conceptos->count()) {
            $otros = $conceptos
                ->reject(fn (?ConceptoPago $c) => (bool) $c?->deducible_iedu)
                ->map(fn (?ConceptoPago $c) => $c?->nombre ?? 'un pago sin aplicar')
                ->unique()->values();

            return DecisionIedu::falta(
                'La factura mezcla enseñanza con otros cobros ('.$otros->implode(', ').'), '
                .'y el complemento educativo ampara el comprobante entero. Factúralos por separado.'
            );
        }

        $impedimentos = $this->impedimentos($matricula);

        if ($impedimentos !== []) {
            return DecisionIedu::falta('No se puede armar el complemento educativo: '.implode('; ', $impedimentos).'.');
        }

        return DecisionIedu::lleva($this->datosDe($matricula));
    }

    /**
     * Qué le falta a esta matrícula para poder llevar complemento educativo.
     *
     * Se expone porque la PANTALLA lo pregunta antes de emitir, sin saber
     * todavía qué pagos se van a facturar: es un estado de la matrícula, no de
     * la selección. Reimplementarlo allá haría que la pantalla prometiera una
     * cosa y el comprobante dijera otra, y aquí eso se paga cancelando ante el
     * SAT.
     *
     * Se devuelven TODOS los faltantes y no el primero: quien captura los
     * arregla en una vuelta, en vez de descubrir el siguiente hueco al intentar
     * emitir otra vez.
     *
     * @return array<int, string>
     */
    public function impedimentos(MatriculaOferta $matricula): array
    {
        $this->cargar($matricula);

        $nivel = $matricula->oferta?->programaAcademico?->nivelEstudios;
        $faltan = [];

        if ($nivel === null || blank($nivel->nivel_iedu)) {
            $faltan[] = $nivel === null
                ? 'el programa no tiene nivel de estudios'
                : 'el nivel «'.$nivel->nombre.'» no está mapeado a un nivel del complemento '
                    .'(se configura en Plataforma › Facturación)';
        }

        if (blank($matricula->persona?->curp)) {
            $faltan[] = 'la alumna o el alumno no tiene CURP capturada, y el complemento la exige';
        }

        if (blank($matricula->oferta?->plan?->rvoe)) {
            $faltan[] = 'el plan de estudios no tiene RVOE capturado';
        }

        return $faltan;
    }

    /**
     * El nivel que viaja es el del SAT (`nivel_iedu`), NO el nombre con el que
     * la escuela llama a ese nivel. Son cinco literales fijos del complemento;
     * mandar «Preparatoria» o «Bachillerato General» produce un XML que el PAC
     * rechaza, y el error aparece a kilómetros de donde se capturó.
     *
     * @return array{nombre_alumno: string, curp: string, nivel_educativo: string, aut_rvoe: string}
     */
    private function datosDe(MatriculaOferta $matricula): array
    {
        $this->cargar($matricula);

        return [
            'nombre_alumno' => $matricula->persona->nombreCompleto(),
            'curp' => (string) $matricula->persona->curp,
            'nivel_educativo' => (string) $matricula->oferta->programaAcademico->nivelEstudios->nivel_iedu,
            'aut_rvoe' => (string) $matricula->oferta->plan->rvoe,
        ];
    }

    private function cargar(MatriculaOferta $matricula): void
    {
        $matricula->loadMissing([
            'persona',
            'oferta.plan',
            'oferta.programaAcademico.nivelEstudios',
        ]);
    }
}
