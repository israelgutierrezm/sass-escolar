/**
 * Tipos compartidos de las props que Inertia comparte en todas las páginas
 * (ver App\Http\Middleware\HandleInertiaRequests).
 */

export interface Rol {
    id: number;
    clave: string;
    nombre: string;
    /** Faceta a la que pertenece: "Encargado de admisiones" → "Administrativo". */
    faceta: string;
}

export interface RolDisponible extends Rol {
    campus_id: number | null;
    campus_nombre: string | null;
}

export interface UsuarioAutenticado {
    id: number;
    usuario: string;
    email: string;
    nombre_completo: string;
    rol_activo: Rol | null;
    roles_disponibles: RolDisponible[];
    permisos: string[];
}

export interface Escuela {
    id: string;
    nombre: string;
}

export interface Flash {
    exito: string | null;
    error: string | null;
    /** La operación funcionó pero algo quedó fuera; se explica qué y por qué. */
    advertencia: string | null;
}

export interface PropsCompartidas {
    auth: {
        usuario: UsuarioAutenticado | null;
    };
    escuela: Escuela | null;
    flash: Flash;
    [key: string]: unknown;
}
