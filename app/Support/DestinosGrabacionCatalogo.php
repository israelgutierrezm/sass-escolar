<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dónde se guardan las grabaciones de las clases en línea.
 *
 * Mismo papel que `PasarelasCatalogo` y `ProveedoresVideoCatalogo`: declara qué
 * destinos existen y qué credenciales pide cada uno. Subir el archivo es de cada
 * destino (`App\Services\Grabaciones`); esto es el registro que alimenta la
 * pantalla.
 *
 * ── Por qué esto hace falta si Zoom y Meet ya guardan ──────────────────────
 * Porque lo que guardan se les acaba y se les caduca:
 *
 * - **Zoom** da unos pocos GB de nube por licencia y, cuando se llena, deja de
 *   grabar o empieza a borrar lo viejo. Un semestre de clases no cabe.
 * - **Meet** las deja en el Drive de quien organizó, que es una cuenta de
 *   servicio o una cuenta genérica de la escuela: nadie las encuentra, y el día
 *   que esa cuenta se da de baja se van con ella.
 *
 * Traerlas a un sitio que la escuela controla —y dejar el enlace colgado de la
 * clase, que es donde alguien lo va a buscar— es lo que las vuelve consultables.
 *
 * ── UN destino a la vez ────────────────────────────────────────────────────
 * No varios. Con dos encendidos habría que decidir cuál enlace se le enseña al
 * alumno, se pagarían dos almacenamientos por el mismo archivo, y el día que uno
 * falle la mitad del semestre estaría en un sitio y la mitad en otro. Cambiar de
 * destino no mueve lo ya archivado: lo viejo se queda donde está, con su enlace,
 * y lo nuevo va al destino nuevo.
 */
class DestinosGrabacionCatalogo
{
    /** El almacenamiento de la propia escuela. Sin cuentas de nadie. */
    public const DISCO = 'disco';

    public const DRIVE = 'drive';

    public const DROPBOX = 'dropbox';

    /**
     * @return array<string, array{
     *     nombre: string,
     *     descripcion: string,
     *     color: string,
     *     necesitaCuenta: bool,
     *     campos: array<string, array{etiqueta: string, requerido: bool, ayuda?: string}>
     * }>
     */
    public static function todos(): array
    {
        return [
            self::DISCO => [
                'nombre' => 'Almacenamiento de la escuela',
                'descripcion' => 'El mismo disco privado donde ya viven los documentos y las entregas. '
                    .'No hace falta contratar ni conectar nada.',
                'color' => '#64748B',
                /*
                 * El único que no pide credenciales, y por eso es el que
                 * permite empezar a archivar hoy. Lo que cuesta es disco.
                 */
                'necesitaCuenta' => false,
                'campos' => [],
            ],

            self::DRIVE => [
                'nombre' => 'Google Drive',
                'descripcion' => 'Sube cada grabación a una carpeta de Drive de la escuela.',
                'color' => '#1FA463',
                'necesitaCuenta' => true,
                'campos' => [
                    'cuenta_servicio_json' => [
                        'etiqueta' => 'JSON de la cuenta de servicio',
                        'requerido' => true,
                        'ayuda' => 'El mismo tipo de archivo que usa Google Meet, con el alcance de Drive. '
                            .'Puede ser la misma cuenta de servicio si le agregas ese permiso.',
                    ],
                    'como_quien' => [
                        'etiqueta' => 'Actuar como',
                        'requerido' => true,
                        'ayuda' => 'Correo de Workspace a nombre del cual se suben los archivos. La carpeta debe '
                            .'ser suya o estar compartida con él.',
                    ],
                    'carpeta_id' => [
                        'etiqueta' => 'ID de la carpeta',
                        'requerido' => true,
                        'ayuda' => 'Se lee de la dirección de la carpeta en Drive: la parte después de /folders/.',
                    ],
                ],
            ],

            self::DROPBOX => [
                'nombre' => 'Dropbox',
                'descripcion' => 'Sube cada grabación a una carpeta de Dropbox de la escuela.',
                'color' => '#0061FF',
                'necesitaCuenta' => true,
                'campos' => [
                    'app_key' => ['etiqueta' => 'App key', 'requerido' => true, 'ayuda' => 'De tu app en la consola de Dropbox.'],
                    'app_secret' => ['etiqueta' => 'App secret', 'requerido' => true],
                    /*
                     * Refresh token y no access token: los de Dropbox caducan a
                     * las cuatro horas desde 2021. Guardar uno de acceso deja el
                     * archivado funcionando una tarde y roto para siempre
                     * después, sin que nadie entienda por qué dejó de andar.
                     */
                    'refresh_token' => [
                        'etiqueta' => 'Refresh token',
                        'requerido' => true,
                        'ayuda' => 'El token de REFRESCO, no el de acceso: los de acceso caducan a las cuatro horas '
                            .'y el archivado dejaría de funcionar esa misma tarde.',
                    ],
                    'carpeta' => [
                        'etiqueta' => 'Carpeta',
                        'requerido' => false,
                        'ayuda' => 'Por ejemplo /Clases. Si se deja vacío, van a la raíz de la app.',
                    ],
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

    /** @return array<int, string> */
    public static function camposRequeridos(string $clave): array
    {
        $campos = self::todos()[$clave]['campos'] ?? [];

        return array_keys(array_filter($campos, fn (array $c) => $c['requerido']));
    }
}
