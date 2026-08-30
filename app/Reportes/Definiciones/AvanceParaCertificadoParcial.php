<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * Quién puede pedir un certificado PARCIAL.
 *
 * Avanzó y todavía no cerró: es el papel que necesita quien se cambia de
 * escuela o pide una beca a media programa académico. Sin este reporte hay que abrir el
 * expediente de cada alumno para saber si tiene algo que acreditar.
 */
class AvanceParaCertificadoParcial extends DefinicionReporte
{
    public function clave(): string
    {
        return 'avance-certificado-parcial';
    }

    public function titulo(): string
    {
        return 'Avance para certificado parcial';
    }

    public function descripcion(): string
    {
        return 'Matrículas con materias aprobadas que TODAVÍA no cierran su plan: son las del '
            .'certificado PARCIAL. Quien ya cerró no sale aquí —le toca el total— y quien no ha '
            .'aprobado nada tampoco, porque no hay nada que acreditar.';
    }

    public function fuente(): string
    {
        return 'certificables';
    }

    public function areaSugerida(): string
    {
        return 'certificacion';
    }

    public function filtrosFijos(): array
    {
        return ['con_avance_sin_cerrar' => true, 'sin_tramite' => true, 'expide_documentos' => true];
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'campus', 'aprobadas', 'meta', 'generacion'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['aprobadas', 'desc'];
    }
}
