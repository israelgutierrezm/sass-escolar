<?php

declare(strict_types=1);

namespace App\Panel\Tarjetas;

use App\Models\Identidad\Usuario;
use App\Panel\TarjetaPanel;

/**
 * Los botones a lo que esta persona usa a diario.
 *
 * Cada acceso declara su permiso y se filtran igual que el menú. NO es una lista
 * por rol: un rol nuevo armado desde la pantalla de roles obtiene sus accesos
 * solo, según lo que le hayan palomeado.
 */
class AccesosDirectos implements TarjetaPanel
{
    /** @var array<int, array{0: string, 1: string, 2: ?string}> */
    private const ACCESOS = [
        ['Capturar calificaciones', '/captura', 'capturar-calificaciones'],
        ['Mis materias', '/docencia', 'ver-mis-materias'],
        ['Mi expediente', '/docencia/expediente', 'editar-mi-expediente'],
        ['Aspirantes', '/aspirantes', 'ver-aspirantes'],
        ['Promoción', '/promocion', 'ver-mis-prospectos'],
        ['Alumnos', '/escolar/alumnos', 'ver-alumnos'],
        ['Inscripciones', '/escolar/inscripciones', 'inscribir-alumnos'],
        ['Grupos', '/escolar/grupos', 'abrir-grupos'],
        ['Cartera', '/finanzas', 'registrar-pagos'],
        ['Facturas', '/finanzas/facturas', 'facturar'],
        ['Roles y permisos', '/plataforma/roles', 'gestionar-roles'],
    ];

    public function clave(): string
    {
        return 'accesos';
    }

    public function titulo(): string
    {
        return 'Accesos directos';
    }

    public function permiso(): ?string
    {
        return null; // cualquiera con sesión; el filtro va por acceso
    }

    public function tipo(): string
    {
        return 'accesos';
    }

    public function ancho(): int
    {
        return 4;
    }

    public function datos(Usuario $usuario): ?array
    {
        $accesos = [];

        foreach (self::ACCESOS as [$etiqueta, $url, $permiso]) {
            if ($permiso === null || $usuario->can($permiso)) {
                $accesos[] = ['etiqueta' => $etiqueta, 'enlace' => $url];
            }
        }

        return $accesos === [] ? null : ['accesos' => $accesos];
    }
}
