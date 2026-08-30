<?php

declare(strict_types=1);

namespace App\Reportes\Definiciones;

use App\Reportes\DefinicionReporte;

/**
 * El padrón completo con su avance, para entender por qué alguien NO sale.
 *
 * ── Por qué existe además de los dos filtrados ───────────────────────────
 * Los otros dos contestan «a quién sí». Éste contesta «por qué a fulano no»,
 * que es la pregunta que llega a ventanilla. Con las cuatro condiciones a la
 * vista —cerró el plan, el programa académico expide papel, ya está en trámite y el
 * identificador del campus— el motivo se lee en el renglón en vez de
 * reconstruirse abriendo cuatro pantallas.
 */
class AvanceDeCertificacion extends DefinicionReporte
{
    public function clave(): string
    {
        return 'avance-de-certificacion';
    }

    public function titulo(): string
    {
        return 'Avance de certificación';
    }

    public function descripcion(): string
    {
        return 'Todas las matrículas con su avance y las condiciones que deciden si se pueden '
            .'certificar. Sirve para contestar por qué alguien NO aparece en «Listos para '
            .'certificar». NO dice si el XML pasará la validación de la SEP: eso se sabe al armar '
            .'el lote, y lo que más lo detiene son los identificadores oficiales.';
    }

    public function fuente(): string
    {
        return 'certificables';
    }

    public function areaSugerida(): string
    {
        return 'certificacion';
    }

    public function columnasPorOmision(): ?array
    {
        return ['matricula', 'alumno', 'programa_academico', 'aprobadas', 'meta', 'cerro_plan', 'emite_documentos', 'ya_en_lote'];
    }

    public function ordenPorOmision(): ?array
    {
        return ['aprobadas', 'desc'];
    }
}
