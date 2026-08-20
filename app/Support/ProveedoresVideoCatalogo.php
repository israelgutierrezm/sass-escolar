<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Los proveedores de clase en línea que la escuela puede encender.
 *
 * Aquí vive QUÉ proveedores existen, QUÉ credenciales pide cada uno y —lo que
 * de verdad importa— CÓMO se comporta cada uno cuando hay varias clases a la
 * misma hora. La lógica de crear la reunión no vive aquí (eso es por proveedor,
 * en `App\Services\Videoconferencia`): esto es el registro que alimenta la
 * pantalla de configuración, igual que `PasarelasCatalogo` con los cobros.
 *
 * ── Zoom y Meet NO funcionan igual, y no se finge que sí ───────────────────
 * Es la decisión que gobierna todo lo demás:
 *
 * - En **Zoom**, una licencia de anfitrión sostiene UNA reunión a la vez. Si dos
 *   docentes dan clase a las 9:00, hacen falta dos licencias. Por eso la escuela
 *   carga tantas como clases simultáneas quiera, y por eso existe el reparto:
 *   al programar se busca una libre EN ESA VENTANA, y si no hay, se dice —en vez
 *   de crear una reunión que va a tirar a la otra clase—.
 *
 * - En **Google Meet** no hay tal límite: una cuenta de Workspace puede tener
 *   muchas reuniones a la vez, porque el enlace nace de un evento de Calendar y
 *   no de una licencia de anfitrión. Ahí la «cuenta» no es una licencia que se
 *   agote, es la identidad que organiza el evento.
 *
 * Tratarlos igual habría llevado a una de dos mentiras: pedirle a la escuela
 * comprar licencias de Meet que no existen, o dejar que Zoom sobrevenda una
 * licencia y que la segunda clase eche a la primera. `unaReunionPorCuenta` es
 * lo que separa los dos casos, y lo lee el asignador.
 *
 * ── Lo que Meet pide y Zoom no ─────────────────────────────────────────────
 * Meet no tiene API de reuniones: el enlace se obtiene creando un evento en
 * Google Calendar con `conferenceData`. Eso obliga a una cuenta de servicio con
 * delegación en todo el dominio y a un Workspace propio —con una cuenta de
 * Gmail personal no se puede—. Se dice en la ayuda del campo, porque es la
 * clase de requisito que se descubre a media configuración.
 */
class ProveedoresVideoCatalogo
{
    public const ZOOM = 'zoom';

    public const MEET = 'meet';

    /**
     * @return array<string, array{
     *     nombre: string,
     *     descripcion: string,
     *     color: string,
     *     unaReunionPorCuenta: bool,
     *     queEsUnaCuenta: string,
     *     campos: array<string, array{etiqueta: string, requerido: bool, ayuda?: string}>,
     *     campoCuenta: array{etiqueta: string, ayuda: string}
     * }>
     */
    public static function todos(): array
    {
        return [
            self::ZOOM => [
                'nombre' => 'Zoom',
                'descripcion' => 'El docente programa desde su materia y entra con un clic; al alumno le aparece el botón solo.',
                'color' => '#2D8CFF',
                /*
                 * Una licencia = una reunión a la vez. Es el hecho que obliga a
                 * repartir por ventana de horario.
                 */
                'unaReunionPorCuenta' => true,
                'queEsUnaCuenta' => 'Una licencia de anfitrión. Cada una sostiene UNA clase a la vez, '
                    .'así que hacen falta tantas como clases simultáneas quieras poder dar.',
                'campos' => [
                    'account_id' => [
                        'etiqueta' => 'Account ID',
                        'requerido' => true,
                        'ayuda' => 'De tu app «Server-to-Server OAuth» en el Marketplace de Zoom.',
                    ],
                    'client_id' => ['etiqueta' => 'Client ID', 'requerido' => true],
                    'client_secret' => ['etiqueta' => 'Client Secret', 'requerido' => true],
                    /*
                     * Opcional para dar clase, obligatorio para archivar: sin él
                     * no se puede comprobar que el aviso de grabación viene de
                     * Zoom, y un aviso sin firma se rechaza.
                     */
                    'webhook_secret' => [
                        'etiqueta' => 'Secret Token del webhook',
                        'requerido' => false,
                        'ayuda' => 'Sólo hace falta para guardar las grabaciones. Es el «Secret Token» de la app, '
                            .'y la URL que se registra en Zoom es la de abajo.',
                    ],
                ],
                'campoCuenta' => [
                    'etiqueta' => 'Correo de la licencia',
                    'ayuda' => 'El correo del usuario de Zoom con licencia. Tiene que estar dentro de tu cuenta de Zoom.',
                ],
            ],

            self::MEET => [
                'nombre' => 'Google Meet',
                'descripcion' => 'Mismo flujo que Zoom para el docente y el alumno. Requiere Google Workspace: '
                    .'con una cuenta de Gmail normal no se puede.',
                'color' => '#00832D',
                /*
                 * Sin límite por cuenta: el enlace sale de un evento de
                 * Calendar, no de una licencia de anfitrión. Una sola cuenta
                 * puede organizar veinte clases a la misma hora.
                 */
                'unaReunionPorCuenta' => false,
                'queEsUnaCuenta' => 'La cuenta de Workspace que organiza los eventos. NO es una licencia y no se '
                    .'agota: una sola basta para todas las clases, aunque sean simultáneas. '
                    .'Tener varias sirve para separar por campus o por escuela.',
                'campos' => [
                    'cuenta_servicio_json' => [
                        'etiqueta' => 'JSON de la cuenta de servicio',
                        'requerido' => true,
                        'ayuda' => 'El archivo que Google Cloud entrega al crear la cuenta de servicio, pegado completo. '
                            .'Necesita delegación en todo el dominio con el alcance de Calendar.',
                    ],
                    'dominio' => [
                        'etiqueta' => 'Dominio de Workspace',
                        'requerido' => true,
                        'ayuda' => 'Por ejemplo: escuela.edu.mx. Las cuentas que agregues abajo deben ser de este dominio.',
                    ],
                ],
                'campoCuenta' => [
                    'etiqueta' => 'Correo de Workspace',
                    'ayuda' => 'La cuenta a nombre de la cual se crea el evento. Debe ser del dominio de arriba.',
                ],
            ],
        ];
    }

    public static function existe(string $clave): bool
    {
        return array_key_exists($clave, self::todos());
    }

    /** @return array<string, mixed>|null */
    public static function uno(string $clave): ?array
    {
        return self::todos()[$clave] ?? null;
    }

    /** @return array<int, string> */
    public static function claves(): array
    {
        return array_keys(self::todos());
    }

    /**
     * Si en este proveedor una cuenta sólo aguanta una reunión a la vez.
     *
     * Lo pregunta el asignador para decidir si hay que buscar una libre o si
     * cualquiera sirve. Ante un proveedor desconocido responde `true`: es el
     * lado seguro —como mucho se dirá que no hay cuentas libres, en vez de
     * crear dos reuniones que se estorben—.
     */
    public static function unaReunionPorCuenta(string $clave): bool
    {
        return self::todos()[$clave]['unaReunionPorCuenta'] ?? true;
    }

    /** Los campos de credenciales que hay que llenar para poder encenderlo. */
    public static function camposRequeridos(string $clave): array
    {
        $campos = self::todos()[$clave]['campos'] ?? [];

        return array_keys(array_filter($campos, fn (array $c) => $c['requerido']));
    }
}
