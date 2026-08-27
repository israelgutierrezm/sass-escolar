<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\ControlEscolar\BibliotecaEnlace;
use App\Models\Identidad\Usuario;
use App\Panel\TarjetaDeModulo;
use App\Panel\TarjetaPanel;

/**
 * La biblioteca digital, en el panel del alumno.
 *
 * Es la ÚNICA puerta de entrada: la sección no cuelga del menú lateral, así que
 * si esta tarjeta no sale, el alumno no tiene por dónde llegar.
 *
 * Devuelve null —y por tanto no se dibuja— en tres casos, y los tres importan
 * por separado: la escuela cerró la sección, a este rol no le toca verla, o
 * todavía no hay nada publicado. Este último evita el peor resultado de todos:
 * una tarjeta que invita a entrar a una pantalla vacía.
 */
class BibliotecaDigital implements TarjetaDeModulo, TarjetaPanel
{
    /**
     * Se DECLARA en vez de inyectarse.
     *
     * Estas eran las dos únicas tarjetas que comprobaban su módulo por su
     * cuenta, y así funcionaban — pero con la comprobación repartida, la que se
     * olvide no falla: se pinta. Es lo que le pasó a «Postulantes en proceso».
     * Ahora lo mira `RegistroTarjetas::para()`, en un solo sitio.
     */
    public function modulo(): string
    {
        return 'biblioteca';
    }

    public function clave(): string
    {
        return 'biblioteca';
    }

    public function titulo(): string
    {
        return 'Biblioteca digital';
    }

    public function permiso(): ?string
    {
        return 'ver-biblioteca';
    }

    public function tipo(): string
    {
        return 'metrica';
    }

    public function ancho(): int
    {
        return 2;
    }

    public function icono(): string
    {
        return 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25';
    }

    public function datos(Usuario $usuario): ?array
    {
        $publicados = BibliotecaEnlace::query()->publicados()->count();

        if ($publicados === 0) {
            return null;
        }

        return [
            'valor' => $publicados,
            'formato' => 'entero',
            'pie' => $publicados === 1 ? 'recurso disponible' : 'recursos disponibles',
            'enlace' => '/biblioteca',
        ];
    }
}
