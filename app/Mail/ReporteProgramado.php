<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El correo de un reporte programado, con su archivo adjunto.
 *
 * ── Dice CON EL ALCANCE DE QUIÉN salió, y no es un adorno ────────────────
 * El archivo lo produce el rol guardado en la programación, así que a un
 * destinatario puede llegarle más —o menos— de lo que vería entrando él mismo.
 * Un archivo que trae otra cosa de la que su lector espera, sin decirlo, se
 * reenvía creyendo que es lo que pidió.
 *
 * ── Y dice CUÁNTAS FILAS trae ────────────────────────────────────────────
 * Un adjunto de cero filas y uno de novecientas se ven igual en la bandeja. Con
 * la cifra en el cuerpo, quien lo recibe sabe si abrirlo antes de abrirlo — y si
 * un lunes llegan cero donde siempre llegan cuarenta, se nota.
 *
 * ── Se manda SIN cola ────────────────────────────────────────────────────
 * `QUEUE_CONNECTION=database` y este repositorio no declara ningún trabajador,
 * así que encolarlo lo dejaría esperando para siempre y sin avisar. Va síncrono
 * dentro del comando, que corre de madrugada y no tiene a nadie esperando.
 */
class ReporteProgramado extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $titulo,
        public string $vista,
        public string $cuando,
        public string $alcanceDe,
        public string $rol,
        public int $filas,
        public string $archivo,
        public string $nombreArchivo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->titulo.' · '.$this->vista,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reporte-programado');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->archivo, $this->nombreArchivo),
        ];
    }
}
