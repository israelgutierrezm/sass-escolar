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
    /** Permiso exigido ADEMÁS del anterior (el `can:` de sección de su grupo de rutas). */
    y?: string;
    facetas?: string[] | null;
    /** Módulo de la escuela que enciende la sección; ausente = universal. */
    modulo?: string | null;
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
            modulo: base.modulo ?? null,
            hijos: hijos.map((h) => resolver(h.clave, h.hijos)).filter((n): n is NodoNav => n !== null),
        };
    }

    // Subgrupo (nivel 2/3): una opción con hijos es un encabezado plegable, sin
    // URL propia. Si el arreglo no trae su anidamiento, se toma del catálogo.
    if (base.hijos && base.hijos.length > 0) {
        const arreglo = hijos.length > 0 ? hijos : base.hijos.map((h) => ({ clave: h.clave }));

        return {
            clave,
            etiqueta: base.etiqueta,
            esGrupo: true,
            prefijo: base.prefijo ?? '',
            url: base.prefijo,
            facetas: null, // hereda el ámbito del grupo padre
            // Un subgrupo SÍ puede depender de un módulo (Movilidad, Disciplina
            // y Bolsa cuelgan de «Alumnos» y cada uno tiene el suyo): sin
            // copiarlo aquí, `filtrar` no tendría con qué ocultarlo.
            modulo: base.modulo ?? null,
            hijos: arreglo.map((h) => resolver(h.clave, h.hijos)).filter((n): n is NodoNav => n !== null),
        };
    }

    // Hoja.
    return {
        clave,
        etiqueta: base.etiqueta,
        esGrupo: false,
        url: base.url,
        prefijo: base.url ?? '',
        permiso: base.permiso,
        o: base.o,
        y: base.y,
        modulo: base.modulo ?? null,
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

/**
 * Agrega grupos/opciones del catálogo que falten en una disposición vieja,
 * salvo las claves ocultas por el editor.
 *
 * ── Dos reglas que el primer intento no tenía, y las dos mordieron al
 *    agrupar Finanzas ────────────────────────────────────────────────────
 *
 * 1. **Nada se agrega DOS veces.** Al plegar veintidós entradas sueltas en
 *    seis subgrupos, una escuela que ya hubiera ordenado su menú tenía esas
 *    hojas guardadas al primer nivel. El subgrupo nuevo no estaba en su
 *    disposición, así que entraba entero —y `resolver` le cuelga los hijos del
 *    catálogo—, dejando cada hoja repetida: una donde la escuela la puso y
 *    otra dentro del subgrupo. No falla; sólo sale el menú duplicado.
 *    Se poda contra lo ya presente, y un subgrupo que se queda sin hijos no se
 *    agrega: si la escuela ya colocó todas sus hojas a mano, esa decisión es
 *    suya y se respeta.
 *
 * 2. **Se BAJA a los subgrupos que ya existen.** Antes sólo se miraba el
 *    primer nivel, así que una hoja nueva dentro de un subgrupo que la
 *    disposición ya tenía no se agregaba NUNCA: quedaba inaccesible para ese
 *    rol, que es justo lo que esta función existe para impedir.
 */
function fusionarFaltantes(base: NodoNav[], ocultos: Set<string>): NodoNav[] {
    const presentes = new Set<string>();
    recorrer(base, presentes);

    /** El nodo sin lo que ya está colocado en otra parte; null si no queda nada. */
    function sinRepetir(nodo: NodoNav): NodoNav | null {
        if (ocultos.has(nodo.clave) || presentes.has(nodo.clave)) {
            return null;
        }

        const hijos = nodo.hijos
            .map(sinRepetir)
            .filter((n): n is NodoNav => n !== null);

        // Un subgrupo al que no le quedó ningún hijo no aporta nada: sus hojas
        // ya están donde la escuela decidió ponerlas.
        if (nodo.hijos.length > 0 && hijos.length === 0) {
            return null;
        }

        presentes.add(nodo.clave);

        return { ...nodo, hijos };
    }

    function agregarFaltantes(catalogo: OpcionMenu[], destino: NodoNav): void {
        for (const h of catalogo) {
            if (ocultos.has(h.clave)) {
                continue; // opción oculta: ni se agrega
            }

            const existente = buscar(base, h.clave);

            if (existente) {
                // Ya está en la disposición: se respeta dónde, y se baja a ver
                // si le falta algo dentro.
                if (h.hijos && h.hijos.length > 0) {
                    agregarFaltantes(h.hijos, existente);
                }

                continue;
            }

            const nodo = sinRepetir(resolver(h.clave, []) as NodoNav);

            if (nodo) {
                destino.hijos.push(nodo);
            }
        }
    }

    for (const g of CATALOGO_MENU) {
        if (ocultos.has(g.clave)) {
            continue; // grupo oculto: ni se agrega
        }

        let grupo = buscar(base, g.clave);

        if (!grupo) {
            grupo = resolver(g.clave, []) as NodoNav;
            base.push(grupo);
            presentes.add(g.clave);
        }

        agregarFaltantes(g.hijos, grupo);
    }

    return base;
}

function hojaVisible(nodo: NodoNav, permisos: string[]): boolean {
    // `o` es un O —las puertas derivadas—; `y` es un Y —el `can:` de sección
    // que su grupo de rutas exige encima—. Las rutas usan los dos, así que el
    // menú tiene que saber decir los dos o acaba ofreciendo lo que da 403.
    const suyo = nodo.permiso == null
        || permisos.includes(nodo.permiso)
        || (nodo.o != null && permisos.includes(nodo.o));

    return suyo && (nodo.y == null || permisos.includes(nodo.y));
}

function filtrar(
    nodos: NodoNav[],
    permisos: string[],
    ambito: string | null,
    ocultos: Set<string>,
    modulos: Set<string> | null,
): NodoNav[] {
    const resultado: NodoNav[] = [];

    for (const nodo of nodos) {
        if (ocultos.has(nodo.clave)) {
            continue; // oculto por el editor para este rol
        }

        if (!nodo.esGrupo) {
            // Una hoja de un módulo apagado se oculta igual que su sección: es
            // el caso de «Incidencias» del docente, que cuelga de `disciplina`
            // sin que su sección (Docencia) dependa del módulo.
            const moduloOk = nodo.modulo == null || modulos == null || modulos.has(nodo.modulo);

            if (moduloOk && hojaVisible(nodo, permisos)) {
                resultado.push({ ...nodo, hijos: [] });
            }
            continue;
        }

        // Grupo: primero el ámbito (una sección de docente no la ve un admin).
        const ambitoOk = nodo.facetas == null || (ambito != null && nodo.facetas.includes(ambito));
        if (!ambitoOk) {
            continue;
        }

        /*
         * Y su permiso, si lo declara.
         *
         * `hojaVisible()` sólo se llamaba para hojas, así que el `permiso` de un
         * GRUPO estaba escrito y no lo leía nadie. Hoy sólo lo declara
         * «Encuestas de evaluación», y sus dos hijas piden lo mismo, así que
         * esto no cambia nada visible — pero un campo que se declara y no se
         * aplica es una promesa falsa, y el siguiente que lo use creerá que
         * cierra la sección.
         */
        if (!hojaVisible(nodo, permisos)) {
            continue;
        }

        /*
         * El módulo de la escuela. `modulos == null` = no llegó la lista (página
         * cacheada tras un despliegue): se falla ABIERTO, mostrando la sección,
         * porque vaciar la barra por un prop ausente es peor que un enlace de
         * más. Con la lista presente, un módulo apagado oculta su sección: la
         * ruta ya devolvía 404, esto quita el enlace muerto que llevaba a él.
         */
        if (nodo.modulo != null && modulos != null && !modulos.has(nodo.modulo)) {
            continue;
        }

        // Grupo-enlace (Panel): se muestra como enlace directo, sin desplegar.
        if (GRUPOS_ENLACE.has(nodo.clave)) {
            resultado.push({ ...nodo, hijos: [] });
            continue;
        }

        const hijos = filtrar(nodo.hijos, permisos, ambito, ocultos, modulos);
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
    ocultos: string[] = [],
    modulos: string[] | null = null,
): NodoNav[] {
    const set = new Set(ocultos);
    const base = arreglo
        ? fusionarFaltantes(
            arreglo.map((n) => resolver(n.clave, n.hijos)).filter((n): n is NodoNav => n !== null),
            set,
        )
        : fusionarFaltantes(arbolPorDefecto(), set);

    const nav = filtrar(base, permisos, ambito, set, modulos == null ? null : new Set(modulos))
        .map((n) => ({ ...n, icono: n.icono ?? ICONO_GENERICO }));

    // «Panel» es la puerta de entrada de TODOS: va siempre primero, sin importar
    // la disposición que tenga el rol (nadie puede empujarlo hacia abajo).
    const iPanel = nav.findIndex((n) => n.clave === 'panel');
    if (iPanel > 0) {
        nav.unshift(nav.splice(iPanel, 1)[0]);
    }

    return nav;
}

/** Saca de un árbol las claves ocultas y las junta (con su subárbol) en `bin`. */
function podarOcultos(nodos: NodoNav[], ocultos: Set<string>, bin: NodoNav[]): NodoNav[] {
    const visibles: NodoNav[] = [];

    for (const nodo of nodos) {
        if (ocultos.has(nodo.clave)) {
            bin.push(nodo);
            continue;
        }
        nodo.hijos = podarOcultos(nodo.hijos, ocultos, bin);
        visibles.push(nodo);
    }

    return visibles;
}

/**
 * Para el EDITOR: el árbol del rol filtrado por su ámbito y permisos (así solo
 * ve lo que ese rol podría ver), separado en la parte VISIBLE (a ordenar) y la
 * de OCULTOS (el «cajón»). A diferencia de la barra, aquí los ocultos NO se
 * eliminan: se muestran aparte para poder devolverlos.
 */
export function construirParaEditor(
    arreglo: NodoArreglo[] | null,
    ocultos: string[],
    permisos: string[],
    ambito: string | null,
    modulos: string[] | null = null,
): { visible: NodoNav[]; ocultos: NodoNav[] } {
    const vacio = new Set<string>();
    const base = arreglo
        ? fusionarFaltantes(
            arreglo.map((n) => resolver(n.clave, n.hijos)).filter((n): n is NodoNav => n !== null),
            vacio,
        )
        : fusionarFaltantes(arbolPorDefecto(), vacio);

    const completo = filtrar(base, permisos, ambito, vacio, modulos == null ? null : new Set(modulos))
        .map((n) => ({ ...n, icono: n.icono ?? ICONO_GENERICO }));

    const bin: NodoNav[] = [];
    const visible = podarOcultos(completo, new Set(ocultos), bin);

    return { visible, ocultos: bin };
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

/**
 * Dónde está parada la pantalla actual: su sección, su subgrupo y las opciones
 * hermanas.
 *
 * ── Por qué se DERIVA del catálogo y no se escribe aparte ─────────────────
 * Porque la barra lateral y las pestañas de una sección son dos vistas de lo
 * mismo, y escritas por separado divergen: al mover Oferta, Evaluación y
 * Catálogos a «Configuración», la barra lateral lo respetó y las pestañas de
 * Académico siguieron mostrando las ocho opciones como si nada hubiera pasado.
 * Se veía el mismo módulo con dos organigramas distintos en la misma pantalla.
 *
 * Aquí se responde una sola vez y con el catálogo como fuente.
 */
export interface Ubicacion {
    /**
     * El grupo de nivel 1: «Académico», «Control escolar»…
     *
     * Viaja con su ICONO —el trazo del heroicon— para que el encabezado use el
     * mismo que la barra lateral. Copiarlo aparte los dejaría desincronizados a
     * la primera vez que alguien cambie uno de los dos.
     */
    seccion: { clave: string; etiqueta: string; icono?: string } | null;
    /** El subgrupo, si la ruta cae dentro de uno: «Configuración». */
    subgrupo: { clave: string; etiqueta: string } | null;
    /**
     * La OPCIÓN concreta abierta: «Catálogos», «Accesos».
     *
     * Es el último escalón de la miga de pan. Se resuelve por la URL más
     * específica que case, no por la primera: en una sección donde conviven
     * `/escolar/ciclos` y `/escolar/ciclos/{id}/ventanas`, quedarse con la
     * primera diría siempre «Ciclos».
     */
    hoja: { clave: string; etiqueta: string; url: string } | null;
    /**
     * Las opciones del nivel donde está parada: las hermanas de la actual.
     *
     * Dentro de un subgrupo son las suyas, no las de toda la sección: quien
     * entró a Configuración está trabajando en eso, y ofrecerle ahí las cinco
     * de arriba mezcla dos niveles en una sola fila de pestañas.
     */
    hermanas: { etiqueta: string; url: string }[];
}

/** Si esa ruta cae dentro de ese prefijo. */
function bajo(ruta: string, prefijo: string): boolean {
    return prefijo !== '' && (ruta === prefijo || ruta.startsWith(`${prefijo}/`));
}

/**
 * Si la ruta cae dentro de ese nodo o de CUALQUIER descendiente.
 *
 * Recursivo, y hizo falta: la primera versión miraba sólo un nivel, así que
 * `/escolar/reglas-horario` —que cuelga del subgrupo «Generación de horarios»—
 * no se reconocía como parte de Control escolar. El encabezado se caía al
 * título de la pantalla y las pestañas salían vacías, sin ningún error. Se vio
 * abriendo la pantalla, no leyendo la función.
 */
function contiene(nodo: NodoNav, ruta: string): boolean {
    if (!nodo.esGrupo) {
        return nodo.url ? bajo(ruta, nodo.url) : false;
    }

    return bajo(ruta, nodo.prefijo) || nodo.hijos.some((h) => contiene(h, ruta));
}

/**
 * La hoja más específica que case con la ruta, en todo el árbol de un nodo.
 *
 * «Más específica» = la de URL más larga: `/escolar/ciclos` y
 * `/escolar/ciclos/9/ventanas` casan las dos con la segunda ruta, y la que hay
 * que nombrar es la de abajo.
 */
function hojaMasEspecifica(nodos: NodoNav[], ruta: string): NodoNav | null {
    let mejor: NodoNav | null = null;

    for (const nodo of nodos) {
        if (nodo.esGrupo) {
            const dentro = hojaMasEspecifica(nodo.hijos, ruta);

            if (dentro && (!mejor || (dentro.url ?? '').length > (mejor.url ?? '').length)) {
                mejor = dentro;
            }

            continue;
        }

        if (nodo.url && bajo(ruta, nodo.url) && (!mejor || nodo.url.length > (mejor.url ?? '').length)) {
            mejor = nodo;
        }
    }

    return mejor;
}

/** Las hojas directas de un nodo, con su URL. */
function hojasDe(nodos: NodoNav[]): { etiqueta: string; url: string }[] {
    return nodos
        .filter((n) => !n.esGrupo && n.url)
        .map((n) => ({ etiqueta: n.etiqueta, url: n.url as string }));
}

/**
 * Resuelve la ubicación sobre el árbol YA FILTRADO por permisos y faceta.
 *
 * Sobre el filtrado y no sobre el catálogo crudo: así las pestañas nunca
 * ofrecen una pantalla a la que esa persona no entra, sin tener que volver a
 * comprobar el permiso aquí.
 */
export function ubicacionActual(nodos: NodoNav[], ruta: string): Ubicacion {
    for (const seccion of nodos) {
        if (!contiene(seccion, ruta)) {
            continue;
        }

        const identidad = { clave: seccion.clave, etiqueta: seccion.etiqueta, icono: seccion.icono };

        // ¿Cae dentro de alguno de sus subgrupos?
        for (const hijo of seccion.hijos) {
            if (!hijo.esGrupo) {
                continue;
            }

            if (contiene(hijo, ruta)) {
                const hoja = hojaMasEspecifica(hijo.hijos, ruta);

                return {
                    seccion: identidad,
                    subgrupo: { clave: hijo.clave, etiqueta: hijo.etiqueta },
                    hoja: hoja ? { clave: hoja.clave, etiqueta: hoja.etiqueta, url: hoja.url as string } : null,
                    hermanas: hojasDe(hijo.hijos),
                };
            }
        }

        const hoja = hojaMasEspecifica(seccion.hijos, ruta);

        return {
            seccion: identidad,
            subgrupo: null,
            hoja: hoja ? { clave: hoja.clave, etiqueta: hoja.etiqueta, url: hoja.url as string } : null,
            hermanas: hojasDe(seccion.hijos),
        };
    }

    return { seccion: null, subgrupo: null, hoja: null, hermanas: [] };
}
