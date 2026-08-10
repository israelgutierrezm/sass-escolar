<?php

declare(strict_types=1);

namespace App\Services\Correo;

use App\Models\Correo\CorreoConfig;
use Illuminate\Support\Facades\Mail;

/**
 * Aplica y prueba la configuración de correo (SMTP/Gmail) de la escuela.
 *
 * `aplicar()` sobreescribe en caliente el mailer de Laravel con las credenciales
 * guardadas, para que la recuperación de contraseña, el envío de credenciales y
 * cualquier correo del tenant salgan por la cuenta de la escuela. Si no hay
 * configuración utilizable, no toca nada y el sistema sigue con su mailer por
 * defecto (en desarrollo, el log).
 *
 * La contraseña nunca se registra: solo se inyecta en la config del mailer.
 */
class CorreoService
{
    /**
     * Deja el mailer de Laravel apuntando a la cuenta de la escuela.
     *
     * @return bool si se aplicó una configuración utilizable
     */
    public function aplicar(): bool
    {
        $c = CorreoConfig::actual();

        if (! $c->utilizable()) {
            return false;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $c->host,
            'mail.mailers.smtp.port' => $c->puerto,
            'mail.mailers.smtp.encryption' => $c->cifrado,
            'mail.mailers.smtp.username' => $c->usuario,
            'mail.mailers.smtp.password' => $c->password,
            'mail.from.address' => $c->remitente_correo ?: $c->usuario,
            'mail.from.name' => $c->remitente_nombre ?: config('app.name'),
        ]);

        // El mailer ya resuelto se descarta para que tome la nueva config.
        Mail::purge('smtp');

        return true;
    }

    /**
     * Envía un correo de prueba al destino. Aplica la config primero.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public function probar(string $destino): array
    {
        if (! $this->aplicar()) {
            return ['ok' => false, 'mensaje' => 'Primero captura y activa la cuenta de correo.'];
        }

        try {
            Mail::raw(
                'Este es un correo de prueba de Acadion. Si lo recibiste, la configuración de correo de tu escuela funciona.',
                fn ($m) => $m->to($destino)->subject('Prueba de correo · Acadion'),
            );

            return ['ok' => true, 'mensaje' => "Correo de prueba enviado a {$destino}."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'No se pudo enviar: '.$e->getMessage()];
        }
    }

    /** Valores recomendados para Gmail, para prellenar la interfaz. */
    public static function presetGmail(): array
    {
        return ['host' => 'smtp.gmail.com', 'puerto' => 587, 'cifrado' => 'tls'];
    }
}
