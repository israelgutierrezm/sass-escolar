import { CATALOGO_MENU, indiceCatalogo, type GrupoMenu, type OpcionMenu } from './catalogo';

/**
 * Construye el árbol de navegación EFECTIVO de la barra lateral a partir de:
 *  - la disposición guardada del rol activo (orden y anidamiento), o el orden
 *    por defecto del catálogo si no hay ninguna;
 *  - los permisos del usuario y el ámbito de su rol, que FILTRAN (ordenar no da
 *    acceso).
 * Además fusiona las opciones nuevas del catálogo que no estén en una disposición
 * vieja, para que nada quede inaccesible al agregar módulos.
 */
export interface NodoNav {
    clave: string;
    etiqueta: string;
    esGrupo: boolean;
    icono?: string;
    /** URL a la que navega una hoja (o el prefijo de un grupo-enlace como Panel). */
    url?: string;
    prefijo: string;
    permiso?: string | null;
    o?: string;
    facetas?: string[] | null;
    hijos: NodoNav[];
}

interface NodoArreglo {
    clave: string;
    hijos?: NodoArreglo[];
}

// Icono genérico para una hoja que se promovió a nivel 1 (sin icono propio).
const ICONO_GENERICO = 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z';

function esGrupoCat(base: GrupoMenu | OpcionMenu): base is GrupoMenu {
    return 'icono' in base;
}

/** Claves de grupos que son enlace directo (sin hijos en el catálogo): Panel. */
const GRUPOS_ENLACE = new Set(CATALOGO_MENU.filter((g) => g.hijos.length === 0).map((g) => g.clave));

function resolver(clave: string, hijos: NodoArreglo[] = []): NodoNav | null {
    const base = indiceCatalogo()[clave];
    if (!base) {
        return null; // clave que ya no existe en el catálogo
    }

    if (esGrupoCat(base)) {
        return {
            clave,
            etiqueta: base.etiqueta,
            esGrupo: true,
            icono: base.icono,
            prefijo: base.prefijo,
            url: base.prefijo,
            facetas: base.facetas,
            hijos: hijos.map((h) => resolver(h.clave, h.hijos)).filter((n): n is NodoNav => n !== null),
        };
    }

    return {
        clave,
        etiqueta: base.etiqueta,
        esGrupo: false,
        url: base.url,
        prefijo: base.url,
        permiso: base.permiso,
        o: base.o,
        hijos: [],
    };
}

function arbolPorDefecto(): NodoNav[] {
    return CATALOGO_MENU.map((g) => resolver(g.clave, g.hijos.map((h) => ({ clave: h.clave }))) as NodoNav);
}

function recorrer(nodos: NodoNav[], set: Set<string>): void {
    for (const n of nodos) {
        set.add(n.clave);
        recorrer(n.hijos, set);
    }
}

function buscar(nodos: NodoNav[], clave: string): NodoNav | null {
    for (const n of nodos) {
        if (n.clave === clave) {
            return n;
        }
        const enHijo = buscar(n.hijos, clave);
        if (enHijo) {
            return enHijo;
        }
    }
    return null;
}

/** Agrega grupos/opciones del catálogo que falten en una disposición vieja. */
function fusionarFaltantes(base: NodoNav[]): NodoNav[] {
    const presentes = new Set<string>();
    recorrer(base, presentes);

    for (const g of CATALOGO_MENU) {
        let grupo = buscar(base, g.clave);
        if (!grupo) {
            grupo = resolver(g.clave, []) as NodoNav;
            base.push(grupo);
            presentes.add(g.clave);
        }
        for (const h of g.hijos) {
            if (!presentes.has(h.clave)) {
                grupo.hijos.push(resolver(h.clave, []) as NodoNav);
                presentes.add(h.clave);
            }
        }
    }
    return base;
}

function hojaVisible(nodo: NodoNav, permisos: string[]): boolean {
    return nodo.permiso == null || permisos.includes(nodo.permiso) || (nodo.o != null && permisos.includes(nodo.o));
}

function filtrar(nodos: NodoNav[], permisos: string[], ambito: string | null): NodoNav[] {
    const resultado: NodoNav[] = [];

    for (const nodo of nodos) {
        if (!nodo.esGrupo) {
            if (hojaVisible(nodo, permisos)) {
                resultado.push({ ...nodo, hijos: [] });
            }
            continue;
        }

        // Grupo: primero el ámbito (una sección de docente no la ve un admin).
        const ambitoOk = nodo.facetas == null || (ambito != null && nodo.facetas.includes(ambito));
        if (!ambitoOk) {
            continue;
        }

        // Grupo-enlace (Panel): se muestra como enlace directo, sin desplegar.
        if (GRUPOS_ENLACE.has(nodo.clave)) {
            resultado.push({ ...nodo, hijos: [] });
            continue;
        }

        const hijos = filtrar(nodo.hijos, permisos, ambito);
        if (hijos.length > 0) {
            resultado.push({ ...nodo, hijos });
        }
    }

    return resultado;
}

export function construirNavegacion(
    arreglo: NodoArreglo[] | null,
    permisos: string[],
    ambito: string | null,
): NodoNav[] {
    const base = arreglo
        ? fusionarFaltantes(
            arreglo.map((n) => resolver(n.clave, n.hijos)).filter((n): n is NodoNav => n !== null),
        )
        : arbolPorDefecto();

    return filtrar(base, permisos, ambito).map((n) => ({ ...n, icono: n.icono ?? ICONO_GENERICO }));
}

/** Todas las claves de nodos activos por prefijo, para abrir sus ancestros. */
export function prefijosActivos(nodos: NodoNav[], esActiva: (p: string) => boolean): string[] {
    const abrir: string[] = [];

    function visitar(lista: NodoNav[]): boolean {
        let algunoActivo = false;
        for (const n of lista) {
            const hijoActivo = visitar(n.hijos);
            const propio = esActiva(n.prefijo);
            if (n.esGrupo && (hijoActivo || propio)) {
                abrir.push(n.clave);
            }
            algunoActivo = algunoActivo || hijoActivo || propio;
        }
        return algunoActivo;
    }

    visitar(nodos);
    return abrir;
}
