import { CATALOGO_MENU, indiceCatalogo } from './catalogo.ts';
import { construirNavegacion, ubicacionActual, type NodoNav } from './construir.ts';

let correctas = 0;
let fallidas = 0;

function verificar(etiqueta: string, ok: boolean, extra = ''): void {
    if (ok) {
        correctas++;
        console.log('  ok   ' + etiqueta);
    } else {
        fallidas++;
        console.log('  FALLA ' + etiqueta + (extra ? ' — ' + extra : ''));
    }
}

function claves(nodos: NodoNav[], salida: string[] = []): string[] {
    for (const n of nodos) {
        salida.push(n.clave);
        claves(n.hijos, salida);
    }
    return salida;
}

// Todos los permisos que el catálogo menciona: simula a dirección general.
const TODOS: string[] = [];
for (const g of CATALOGO_MENU) {
    const recoger = (o: any): void => {
        if (o.permiso) TODOS.push(o.permiso);
        if (o.o) TODOS.push(o.o);
        if (o.y) TODOS.push(o.y);
        for (const h of o.hijos ?? []) recoger(h);
    };
    if (g.permiso) TODOS.push(g.permiso);
    for (const h of g.hijos) recoger(h);
}

// Todos los modulos que el catalogo menciona, DERIVADOS y no escritos a mano:
// con una lista fija, agregar un modulo nuevo dejaba su seccion fuera de la
// revision y la suite lo reportaba como una hoja perdida. Lo que aqui se
// comprueba es la forma del arbol, no el apagado por modulo --eso lo vigila
// `filtrar` y se mira en el navegador--.
const MODULOS: string[] = [];
for (const g of CATALOGO_MENU) {
    const recoger = (o: any): void => {
        if (o.modulo) MODULOS.push(o.modulo);
        for (const h of o.hijos ?? []) recoger(h);
    };
    if (g.modulo) MODULOS.push(g.modulo);
    for (const h of g.hijos) recoger(h);
}

console.log('== Sin disposición guardada (el caso de una escuela nueva)');
const limpio = construirNavegacion(null, TODOS, 'administrativo', [], MODULOS);
const finanzas = limpio.find((n) => n.clave === 'finanzas')!;
verificar('Finanzas baja de 22 entradas de primer nivel a 7', finanzas.hijos.length === 7, String(finanzas.hijos.length));

const todas = claves(limpio);
const repetidas = todas.filter((c, i) => todas.indexOf(c) !== i);
verificar('ninguna clave se repite en el árbol', repetidas.length === 0, repetidas.join(', '));

// Sólo las HOJAS de las secciones administrativas: el árbol va filtrado por
// faceta, así que lo del alumno o el docente falta a propósito.
const hojasAdmin: string[] = [];
for (const g of CATALOGO_MENU) {
    if (g.facetas && !g.facetas.includes('administrativo')) continue;
    const recoger = (o: any): void => {
        if (o.hijos && o.hijos.length) { for (const h of o.hijos) recoger(h); } else { hojasAdmin.push(o.clave); }
    };
    for (const h of g.hijos) recoger(h);
}
const perdidas = hojasAdmin.filter((c) => !todas.includes(c));
verificar('no se perdió ninguna hoja administrativa', perdidas.length === 0, perdidas.join(', '));

console.log('\n== Con una disposición VIEJA que tiene las 22 hojas sueltas');
const viejas = [
    'cartera', 'facturas', 'planes-cobro', 'becas', 'presupuesto-becas', 'niveles-beca',
    'autorizaciones-beca', 'descuentos', 'conceptos', 'cuentas-bancarias', 'comprobantes',
    'conciliacion', 'convenios', 'convenios-descuento', 'cobranza', 'presupuesto', 'egresos',
    'emisores', 'caja', 'depositos', 'cajas', 'cierre-fiscal',
];
const disposicion = [
    { clave: 'panel', hijos: [] },
    { clave: 'finanzas', hijos: viejas.map((c) => ({ clave: c })) },
];
const conVieja = construirNavegacion(disposicion, TODOS, 'administrativo', [], MODULOS);
const fin2 = conVieja.find((n) => n.clave === 'finanzas')!;
const todas2 = claves(conVieja);
const repes2 = todas2.filter((c, i) => todas2.indexOf(c) !== i);

verificar('no se duplica ni una entrada', repes2.length === 0, repes2.join(', '));
verificar('se respeta que la escuela las dejó sueltas', fin2.hijos.length === 22, String(fin2.hijos.length));
const perdidas2 = hojasAdmin.filter((c) => !todas2.includes(c));
verificar('tampoco se pierde ninguna hoja', perdidas2.length === 0, perdidas2.join(', '));

console.log('\n== Una hoja NUEVA dentro de un subgrupo que la disposición ya tenía');
const conSubgrupo = [
    { clave: 'panel', hijos: [] },
    {
        clave: 'finanzas',
        hijos: [
            { clave: 'cartera' },
            // El subgrupo existe, pero le falta «Cajas» (como si se acabara de agregar).
            { clave: 'finanzas-caja', hijos: [{ clave: 'caja' }, { clave: 'depositos' }] },
        ],
    },
];
const conSub = construirNavegacion(conSubgrupo, TODOS, 'administrativo', [], MODULOS);
const todas3 = claves(conSub);
verificar('la hoja nueva entra en su subgrupo', todas3.includes('cajas'));
const repes3 = todas3.filter((c, i) => todas3.indexOf(c) !== i);
verificar('y sin duplicar nada', repes3.length === 0, repes3.join(', '));

console.log('\n== El alumno sigue viendo UNA sola entrada de Finanzas');
const alumno = construirNavegacion(null, ['ver-adeudos'], 'alumno', [], MODULOS);
const finAlumno = alumno.find((n) => n.clave === 'finanzas');
verificar('Finanzas aparece', finAlumno !== undefined);
verificar('con «Cartera» y nada más', finAlumno?.hijos.length === 1 && finAlumno.hijos[0].clave === 'cartera',
    JSON.stringify(finAlumno?.hijos.map((h) => h.clave)));

console.log('\n== Cada pantalla cae en su subgrupo y en uno solo');
const rutas: [string, string | null][] = [
    ['/finanzas', null],
    ['/finanzas/caja', 'finanzas-caja'],
    ['/finanzas/caja/depositos', 'finanzas-caja'],
    ['/finanzas/cajas', 'finanzas-caja'],
    ['/finanzas/cuentas-bancarias', 'finanzas-caja'],
    ['/finanzas/conciliacion', 'finanzas-caja'],
    ['/finanzas/comprobantes', 'finanzas-cobranza'],
    ['/finanzas/convenios', 'finanzas-cobranza'],
    ['/finanzas/cobranza', 'finanzas-cobranza'],
    ['/finanzas/becas', 'finanzas-becas'],
    ['/finanzas/becas/presupuesto', 'finanzas-becas'],
    ['/finanzas/becas/niveles', 'finanzas-becas'],
    ['/finanzas/becas/autorizaciones', 'finanzas-becas'],
    ['/finanzas/descuentos', 'finanzas-becas'],
    ['/finanzas/convenios-descuento', 'finanzas-becas'],
    ['/finanzas/facturas', 'finanzas-facturacion'],
    ['/finanzas/emisores', 'finanzas-facturacion'],
    ['/finanzas/cierre', 'finanzas-facturacion'],
    ['/finanzas/presupuesto', 'finanzas-egresos'],
    ['/finanzas/egresos', 'finanzas-egresos'],
    ['/finanzas/planes', 'finanzas-configuracion'],
    ['/finanzas/conceptos', 'finanzas-configuracion'],
    ['/aspirantes', null],
    ['/captacion', 'admisiones-captacion'],
    ['/captacion/comisiones', 'admisiones-captacion'],
    ['/captacion/asesores', 'admisiones-captacion'],
    ['/captacion/publicaciones', 'admisiones-captacion'],
    ['/documentos', 'admisiones-configuracion'],
    ['/formularios', 'admisiones-configuracion'],
    ['/admisiones/reglas-matricula', 'admisiones-configuracion'],
];

for (const [ruta, subgrupo] of rutas) {
    const u = ubicacionActual(limpio, ruta);
    verificar(
        `${ruta} → ${subgrupo ?? '(sin subgrupo)'}`,
        (u.subgrupo?.clave ?? null) === subgrupo && u.hoja !== null,
        `subgrupo=${u.subgrupo?.clave ?? 'null'} hoja=${u.hoja?.clave ?? 'null'}`,
    );
}

console.log(`\nResultado: ${correctas} correctas, ${fallidas} fallidas`);
process.exit(fallidas === 0 ? 0 : 1);
