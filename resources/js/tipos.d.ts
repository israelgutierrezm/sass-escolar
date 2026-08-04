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
    /**
     * Ámbito canónico (administrativo/docente/alumno/aspirante/tutor/padre).
     * Es con lo que el menú decide qué secciones mostrar. Solo viaja en el rol
     * ACTIVO; los disponibles no lo necesitan.
     */
    ambito?: string;
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

/**
 * Un aviso tal como le llega a quien lo recibe.
 *
 * `bloquea` es lo que separa al crítico del resto: no se puede quitar de en
 * medio sin confirmar que se leyó.
 */
export interface AvisoRecibido {
    id: number;
    titulo: string;
    cuerpo: string;
    prioridad: 'informativo' | 'importante' | 'critico';
    prioridad_etiqueta: string;
    color: string;
    bloquea: boolean;
    publicado_desde: string | null;
    vigente_hasta: string | null;
}

export interface PropsCompartidas {
    auth: {
        usuario: UsuarioAutenticado | null;
    };
    escuela: Escuela | null;
    flash: Flash;
    /** Usuario REAL cuando la sesion es una suplantacion; null si no. */
    suplantacion: { usuario: string; nombre: string | null } | null;
    avisos: {
        /** Lo que tiene que salirle al paso: crítico o importante sin confirmar. */
        pendientes: AvisoRecibido[];
        /** Vigentes que nunca se le han puesto delante, para la campana. */
        sin_leer: number;
    };
    [key: string]: unknown;
}
