<?php

declare(strict_types=1);

namespace App\Credencial;

use App\Models\Admisiones\MatriculaOferta;
use App\Models\Identidad\Credencial;
use App\Models\Identidad\Persona;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * El código QR de una credencial: a dónde apunta y cómo se dibuja.
 *
 * ── Qué lleva dentro ───────────────────────────────────────────────────────
 * Una dirección, no los datos. Meter el nombre y la matrícula en el propio
 * código parece ahorrar un viaje y hace lo contrario de verificar: cualquiera
 * genera un QR que diga lo que quiera. Apuntando a la escuela, lo que se lee
 * sale de la base de datos de la escuela —y una credencial dada de baja deja de
 * confirmar a nadie—.
 *
 * ── Por qué la corrección de errores va alta ───────────────────────────────
 * Porque esto se imprime y se mete en una cartera. Un código con el nivel bajo
 * deja de leerse con un doblez o un rayón; con el alto sobrevive perdiendo
 * hasta un tercio de su superficie. Se paga en densidad —el mismo dato ocupa
 * más módulos— y a este tamaño no se nota.
 */
class CodigoQr
{
    public function __construct(private readonly RegistroDeEmisiones $registro) {}

    /**
     * El PNG del código de esta credencial, emitiéndola si es la primera vez.
     *
     * Devuelve el binario y no una ruta porque el compositor pega imágenes en
     * memoria: guardar un archivo por credencial dejaría basura en el disco
     * cada vez que alguien mira la suya.
     */
    public function png(Persona $persona, int $rolId, ?MatriculaOferta $matricula, int $lado = 512): string
    {
        return $this->pngDe($this->direccion($this->registro->de($persona, $rolId, $matricula)), $lado);
    }

    /** El mismo dibujo, para cuando ya se tiene la emisión. */
    public function pngDe(string $datos, int $lado = 512): string
    {
        return (new Builder(
            writer: new PngWriter,
            data: $datos,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $lado,
            // Sin margen: el hueco alrededor lo da la caja donde se pega, y el
            // margen del paquete lo dejaría flotando en medio de su recuadro.
            margin: 0,
        ))->build()->getString();
    }

    /**
     * La dirección que se codifica.
     *
     * Absoluta y con el dominio de LA ESCUELA. Una ruta relativa no significa
     * nada fuera de un navegador ya parado en el sitio, y el QR se escanea
     * desde la cámara del teléfono de quien pide identificarse.
     */
    public function direccion(Credencial $credencial): string
    {
        return route('tenant.credencial.verificar', $credencial->uuid);
    }
}
