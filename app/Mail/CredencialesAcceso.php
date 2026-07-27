<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo con las credenciales de ingreso de una cuenta.
 *
 * Lo dispara un administrador a propósito (al crear la cuenta o al
 * restablecer su contraseña) cuando marca «enviar credenciales». Incluye el
 * correo de acceso, la contraseña recién asignada y la liga de entrada, con la
 * recomendación de cambiarla al entrar.
 */
class CredencialesAcceso extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $nombre,
        public string $correo,
        public string $password,
        public string $urlAcceso,
        public ?string $escuela = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tus datos de acceso'.($this->escuela !== null ? ' · '.$this->escuela : ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.credenciales',
        );
    }
}
