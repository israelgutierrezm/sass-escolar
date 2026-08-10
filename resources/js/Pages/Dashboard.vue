<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CieloDecorado from '@/Components/CieloDecorado.vue';
import ClimaEnCabecera from '@/Components/ClimaEnCabecera.vue';
import AgendaLateral from '@/Components/AgendaLateral.vue';
import { usaClima } from '@/utils/clima';
import { usaJergaAdministrativa } from '@/ambito';
import type { PropsCompartidas } from '@/tipos';

interface Tarjeta {
    clave: string;
    titulo: string;
    tipo: 'metrica' | 'lista' | 'barras' | 'columnas' | 'accesos' | 'encuestas';
    ancho: number;
    icono: string;
    datos: Record<string, any>;
}

interface PuntoAgenda {
    tipo: string;
    clase: string;
    etiqueta: string;
    color: string;
    titulo: string;
    detalle: string | null;
    fecha: string;
    dia: string;
    hora: string | null;
    termina: string | null;
    no_laborable: boolean;
    enlace: string | null;
}

const props = defineProps<{
    tarjetas: Tarjeta[];
    campusDelRol: number[];
    /*
     * Sólo viene cuando el despachador está caído Y quien mira puede hacer algo
     * al respecto. El servidor ya decidió las dos cosas; aquí sólo se pinta.
     */
    despachador: { nunca: boolean; ultimo: string | null; hace_minutos: number | null } | null;
    /** Lo que viene: calendario de la escuela + lo que vence de sus materias. */
    agenda: {
        mes: string;
        proximos: PuntoAgenda[];
        marcados: Record<string, string>;
        hoy: string;
        efemerides: { titulo: string; descripcion: string | null; color: string; aniversario: number | null }[];
    };
}>();

// Un color propio por tarjeta (según su clave) para que el panel sea más vistoso
// sin perder la sobriedad: solo se usa en el icono y un acento, no en el fondo.
const COLORES_TARJETA: Record<string, string> = {
    cartera: '#059669',
    // Dorado. Es el más claro de su familia que todavía se lee sobre blanco: a
    // 4.92:1 pasa, mientras que el oro de verdad —el amarillo puro— se queda en
    // 2.94, que sobre una superficie clara es un color que no se ve; y los que
    // contrastan de sobra ya no son dorados, se vuelven café. Importa porque el
    // mismo valor pinta el enlace «Ver» y el trazo del icono, no sólo el fondo.
    embudo: '#A16207',
    // Turquesa oscuro, y no el cian de «Actividad por día y hora»: cuando las
    // dos caen juntas en la cuadrícula, dos cianes contiguos se leen como un
    // error de copiado. Éste comparte el verde-azul pero desde el verde —175°
    // contra 192°— y con casi la mitad de luminancia, así que a simple vista
    // son colores distintos. También pasa el 4.5:1, con 5.47.
    indicadores: '#0F766E',
    'por-contactar': '#DB2777',
    'comisiones-por-pagar': '#D97706',
    'actividad-por-hora': '#0891B2',
    'mi-avance': '#2563EB',
    'mi-saldo': '#059669',
    'mis-materias': '#4F46E5',
    accesos: '#475569',
    /*
     * Las dos del portal del alumno.
     *
     * Sin entrada aquí caían las dos al acento del tema —`colorTarjeta` lo usa
     * de respaldo— y salían del MISMO color, una al lado de la otra en la misma
     * fila: exactamente lo que se corrigió con el embudo y su vecina cian. Se
     * vio entrando como alumno; con la sesión de dirección esas tarjetas ni
     * aparecen, así que la paleta no se prueba sola.
     *
     * Morado y ámbar quemado: separados entre sí y de sus vecinas de fila —azul
     * en «Mi avance», verde en «Mi estado de cuenta», pizarra en los atajos—.
     * Los dos pasan el 4.5:1 sobre blanco (5.70 y 5.02), que hace falta porque
     * el mismo valor pinta la cifra y el enlace «Ver».
     */
    biblioteca: '#7C3AED',
    'mis-solicitudes': '#B45309',
};

function colorTarjeta(clave: string): string {
    return COLORES_TARJETA[clave] ?? 'var(--color-acento)';
}

/** El rojo del sistema, para la tarjeta que trae una alerta. */
const ROJO_ALERTA = '#dc2626';

/**
 * Qué tarjetas se visten de bloque de color.
 *
 * Sólo las de UN número. Es donde el color no le quita nada a nadie: no hay
 * texto que leer, así que el fondo puede ser sólido y la cifra se lee mejor en
 * blanco y grande que en negro sobre blanco. Una lista de veinte renglones o
 * una gráfica sobre color saturado sería lo contrario: cansa y esconde.
 */
function esDestacada(tarjeta: { tipo: string }): boolean {
    return tarjeta.tipo === 'metrica';
}

/**
 * El color de la tarjeta, y el rojo cuando algo va mal.
 *
 * En una tarjeta blanca la alerta se decía con el número en rojo. Sobre un
 * bloque de color ese rojo no se vería —o peor, se vería sucio—, así que la
 * alerta se lleva la tarjeta ENTERA: una cartera vencida se pone roja y se ve
 * desde el otro lado de la pantalla, que es de lo que se trataba.
 */
function tonoTarjeta(tarjeta: { clave: string; tipo: string; datos: Record<string, any> }): string {
    return esDestacada(tarjeta) && tarjeta.datos.alerta
        ? ROJO_ALERTA
        : colorTarjeta(tarjeta.clave);
}

/**
 * Cuáles llevan su icono repetido de marca de agua en el fondo.
 *
 * Va por clave y no por tipo: no es una manera de dibujar los datos —lo que
 * decide el tipo— sino un realce, y se pone donde el icono dice algo del asunto
 * de la tarjeta. Se declara aquí para que agregar una sea una línea.
 */
const CON_MARCA_DE_AGUA = new Set(['embudo', 'indicadores']);

/**
 * El aro de arriba a la izquierda queda RESERVADO para las tarjetas con marca
 * de agua; las demás se reparten los otros tres.
 *
 * Es lo que convierte «aro arriba a la izquierda + icono abajo a la derecha» en
 * un estilo reconocible en vez de una coincidencia: al ver el aro se sabe que
 * esa tarjeta trae fondo. Y de paso, ninguna tarjeta SIN marca puede sacarlo
 * por sorteo y quedar pegada a una que sí la tiene con el mismo adorno.
 */
const ADORNO_CON_MARCA = 'adorno-2';
const ADORNOS_LIBRES = ['adorno-1', 'adorno-3', 'adorno-4'];

/**
 * Qué adorno lleva cada tarjeta, en el orden en que salen.
 *
 * Se reparte por POSICIÓN y no por clave: lo que se busca es que dos tarjetas
 * vecinas no lleven el mismo adorno, y eso depende de dónde caen en la
 * cuadrícula, no de cómo se llaman. Con una tabla de clave → adorno, dos
 * cualesquiera podrían coincidir y quedar pegadas una junto a la otra.
 *
 * El contador avanza sólo con las que entran al reparto. Si las que llevan
 * adorno fijo consumieran turno, dejarían huecos en la vuelta y dos vecinas
 * podrían acabar con el mismo.
 *
 * A cambio, el adorno de una tarjeta cambia si el panel se reordena. Es lo de
 * menos: son círculos de fondo, no información.
 */
const adornos = computed<string[]>(() => {
    let turno = 0;

    return tarjetasEnPantalla.value.map((tarjeta) =>
        CON_MARCA_DE_AGUA.has(tarjeta.clave)
            ? ADORNO_CON_MARCA
            : ADORNOS_LIBRES[turno++ % ADORNOS_LIBRES.length],
    );
});

// ── Acomodar el panel ───────────────────────────────────────────────────────

/** Los dos únicos tamaños, en columnas de las cuatro que tiene el panel. */
const ANCHO_NORMAL = 2;
const ANCHO_DOBLE = 4;

/**
 * El acomodo en curso, o `null` cuando no se está acomodando.
 *
 * Es una COPIA. Mientras se arrastra no se toca `props.tarjetas`: si se editara
 * directamente, cancelar no tendría a qué volver y cualquier recarga parcial de
 * Inertia pisaría el trabajo a medias. Al guardar, el servidor devuelve el panel
 * ya ordenado y el borrador se descarta.
 */
const borrador = ref<Tarjeta[] | null>(null);
const arrastrando = ref<number | null>(null);

const acomodando = computed(() => borrador.value !== null);
const tarjetasEnPantalla = computed(() => borrador.value ?? props.tarjetas);

function acomodar(): void {
    // Copia de un nivel: se reordena la lista y se cambia `ancho`, nunca los
    // datos de dentro, así que no hace falta clonar en profundidad.
    borrador.value = props.tarjetas.map((t) => ({ ...t }));
}

function cancelarAcomodo(): void {
    borrador.value = null;
    arrastrando.value = null;
}

/** Alterna entre el tamaño normal y el doble. El alto no se toca nunca. */
function alternarAncho(i: number): void {
    const tarjeta = borrador.value?.[i];

    if (tarjeta) {
        tarjeta.ancho = tarjeta.ancho === ANCHO_DOBLE ? ANCHO_NORMAL : ANCHO_DOBLE;
    }
}

function soltarSobre(destino: number): void {
    const origen = arrastrando.value;

    if (borrador.value === null || origen === null || origen === destino) {
        return;
    }

    const [movida] = borrador.value.splice(origen, 1);
    borrador.value.splice(destino, 0, movida);
    arrastrando.value = destino;
}

/**
 * Mover con el teclado, que es la única manera de acomodar sin ratón.
 *
 * El arrastre nativo del navegador no tiene equivalente con teclado, así que
 * sin esto la pantalla quedaba fuera del alcance de quien no puede arrastrar
 * —y de cualquiera en una tableta con teclado—.
 */
function moverConTeclado(i: number, hacia: number): void {
    const destino = i + hacia;

    if (borrador.value === null || destino < 0 || destino >= borrador.value.length) {
        return;
    }

    arrastrando.value = i;
    soltarSobre(destino);
}

function guardarAcomodo(): void {
    if (borrador.value === null) {
        return;
    }

    router.put(
        '/panel/disposicion',
        { tarjetas: borrador.value.map((t) => ({ clave: t.clave, ancho: t.ancho })) },
        { preserveScroll: true, onSuccess: cancelarAcomodo },
    );
}

function restablecerAcomodo(): void {
    router.delete('/panel/disposicion', { preserveScroll: true, onSuccess: cancelarAcomodo });
}

// ── La matriz de día contra hora ────────────────────────────────────────────

/** El punto del riel: lo que se ve donde no hubo nada. */
const PUNTO_MINIMO = 6;
const PUNTO_MAXIMO = 20;

/**
 * El diámetro del punto de una celda, en píxeles.
 *
 * Escala con la RAÍZ del valor y no con el valor. Un punto se compara por su
 * área, no por su diámetro, así que creciendo en línea recta una celda con el
 * doble de entradas se ve con el cuádruple de mancha y la hora punta aplasta
 * visualmente a todo lo demás. Con la raíz, el área sí queda proporcional.
 */
function tamanoPunto(valor: number, maximo: number): number {
    if (valor <= 0 || maximo <= 0) {
        return PUNTO_MINIMO;
    }

    return PUNTO_MINIMO + (PUNTO_MAXIMO - PUNTO_MINIMO) * Math.sqrt(valor / maximo);
}

/**
 * Cuánto tono lleva el punto, en porcentaje.
 *
 * El tamaño solo no basta: en pantallas chicas la diferencia entre 8 y 11 px es
 * casi nada, y el color la sostiene. Arranca en 35% para que la celda con una
 * sola entrada no se confunda con el riel vacío.
 */
function fuerzaPunto(valor: number, maximo: number): number {
    if (valor <= 0 || maximo <= 0) {
        return 0;
    }

    return 35 + 65 * (valor / maximo);
}

/**
 * Cuánto tono lleva la barra de la etapa número `i`, en porcentaje.
 *
 * Baja doce puntos por etapa y se planta en 40: por debajo de ahí la barra se
 * confunde con el riel y la última etapa —la que interesa, la de los que ya se
 * inscribieron— desaparecería justo por ser la última. El embudo se degrada,
 * no se apaga.
 */
function fuerzaEtapa(i: number): number {
    return Math.max(40, 100 - i * 12);
}

const mostrarRoles = ref(false);

const saludo = computed(() => {
    const h = new Date().getHours();
    return h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches';
});

const { clima, esDeNoche, puedeUbicar, ubicando, conMiUbicacion } = usaClima();

/**
 * El cielo, teñido por la hora del CAMPUS.
 *
 * Sigue siendo el acento de la escuela —es su identidad, no se cambia por un
 * adorno—, pero de noche se hunde hacia el azul profundo. Así la banda dice la
 * hora antes de que uno lea la temperatura, que es lo que la vuelve de doble
 * uso y no un saludo con un número pegado.
 *
 * Arranca justo en el acento y se va oscureciendo, así que por la izquierda se
 * funde con el relleno plano de la banda sin que se note dónde acaba uno y
 * empieza el otro; lo que se ve es la mitad derecha ensombreciéndose, no un
 * recuadro pegado encima.
 */
const cielo = computed(() =>
    esDeNoche.value
        ? 'linear-gradient(115deg, color-mix(in srgb, var(--color-acento) 40%, #0b1220), color-mix(in srgb, var(--color-acento) 16%, #060911))'
        : 'linear-gradient(115deg, var(--color-acento), color-mix(in srgb, var(--color-acento) 58%, #000))',
);

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

function formatear(valor: number, formato?: string): string {
    return formato === 'moneda' ? pesos.format(valor) : String(valor);
}

// La barra se mide contra el MAYOR de la serie, no contra el total: en un
// embudo que arranca con 200 y termina con 3, medir contra el total deja las
// últimas etapas invisibles — que son justo las que interesan.
function ancho(serie: { valor: number }[], valor: number): string {
    const mayor = Math.max(1, ...serie.map((s) => s.valor));

    return Math.round((valor / mayor) * 100) + '%';
}

/**
 * Alto de una columna, con mínimo visible.
 *
 * Una hora con actividad 1 sobre un máximo de 200 daría medio píxel y se vería
 * igual que una hora en cero. El mínimo de 6% es la diferencia entre "casi
 * nadie" y "nadie", que no es lo mismo.
 */
function alto(serie: { valor: number }[], valor: number): string {
    if (valor === 0) return '0%';

    const mayor = Math.max(1, ...serie.map((s) => s.valor));

    return Math.max(6, Math.round((valor / mayor) * 100)) + '%';
}

// Con 24 columnas no caben 24 etiquetas: se rotula cada tercera y las demás
// quedan como referencia muda. Poner todas las volvería ilegibles a las dos.
function rotula(i: number, total: number): boolean {
    return total <= 8 || i % 3 === 0;
}

const page = usePage<PropsCompartidas>();

// Si le podemos hablar de roles y permisos, o hay que decirlo en llano.
const jergaOk = usaJergaAdministrativa();

const usuario = computed(() => page.props.auth.usuario);
const rolesDisponibles = computed(() => usuario.value?.roles_disponibles ?? []);
const permisos = computed(() => usuario.value?.permisos ?? []);

function esActivo(rolId: number): boolean {
    return usuario.value?.rol_activo?.id === rolId;
}

function conmutar(rolId: number): void {
    if (esActivo(rolId)) {
        return;
    }

    router.put('/rol-activo', { rol_id: rolId }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Panel" />

    <AppLayout titulo="Panel">
        <!--
            Lo que está roto va ARRIBA del saludo.

            Un aviso de que las tareas programadas no corren compite mal con una
            tarjeta de clima: si aparece entre el panel, se lee como una más. Y
            se comprueba al cargar la pantalla, no con una tarea programada, por
            una razón elemental: si el despachador está caído, una tarea que
            avise de eso tampoco correría.
        -->
        <section
            v-if="despachador"
            class="tarjeta animar-entrada mb-4 border-l-4 p-5"
            :style="{ borderLeftColor: '#dc2626' }"
        >
            <div class="flex flex-wrap items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>

                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold text-red-700">
                        Las tareas automáticas no se están ejecutando
                    </h2>

                    <p class="mt-1 text-sm text-contenido">
                        <template v-if="despachador.nunca">
                            El despachador no ha corrido nunca en este servidor.
                        </template>
                        <template v-else>
                            Lleva <strong>{{ despachador.hace_minutos }} minutos</strong> sin dar señales
                            (última: {{ despachador.ultimo }}).
                        </template>
                    </p>

                    <!--
                        Se dice QUÉ deja de pasar, no sólo que algo falla: «el
                        cron está caído» no le dice a nadie qué va a doler.
                    -->
                    <p class="mt-2 text-sm text-suave">
                        Mientras siga así no se aplican las becas por atraso, no se recalculan los
                        recargos, no se actualiza quién está moroso y no se purgan los registros
                        antiguos. Nada se pierde: al restablecerse, la siguiente corrida se pone al día.
                    </p>

                    <p class="mt-2 text-xs text-suave">
                        Es un problema del servidor, no de la escuela. Pásaselo a quien lo administra:
                        la instalación está documentada en <code>docs/scheduler.md</code>.
                    </p>
                </div>
            </div>
        </section>

        <!--
            La banda de bienvenida, de doble uso: quién eres y cómo está afuera.

            Ocupaba el ancho completo de la pantalla para decir un saludo y un
            nombre, y con un solo rol ni siquiera llevaba el botón de cambiarlo:
            media banda de color liso. Ahora el clima vive aquí —donde ya había
            sitio— y de paso el fondo cambia con la hora del campus.
        -->
        <section
            class="animar-entrada relative mb-4 overflow-hidden rounded-2xl text-white shadow-lg"
            :style="{ backgroundColor: 'var(--color-acento)' }"
        >
            <!--
                El relleno es el acento de la escuela, plano. Lo que se veía
                estirado no era el color sino el dibujo del cielo, que se
                escalaba a lo ancho; eso ya está resuelto en {@see CieloDecorado}
                y el color puede quedarse como estaba.

                Encima, sólo en la mitad derecha, el cielo del clima: se
                desvanece hacia la izquierda en vez de cortarse, así que el
                acento y el cielo se encuentran sin que se vea la juntura.
            -->
            <div class="flex flex-col sm:flex-row sm:items-stretch">
                <div class="min-w-0 flex-1 p-6">
                    <p class="text-sm opacity-80">{{ saludo }},</p>
                    <h1 class="truncate text-2xl font-bold">
                        {{ usuario?.nombre_completo ?? usuario?.usuario }}
                    </h1>
                    <!--
                        Sólo cuando hay de dónde elegir. A quien tiene un único
                        rol, «Operas como Alumno» no le informa de nada: no es
                        una elección, es lo único que puede ser.
                    -->
                    <p v-if="usuario?.rol_activo && rolesDisponibles.length > 1" class="mt-1 text-sm opacity-90">
                        Estás entrando como <strong>{{ usuario.rol_activo.nombre }}</strong>
                    </p>

                    <button
                        v-if="rolesDisponibles.length > 1"
                        type="button"
                        class="mt-3 inline-flex items-center gap-2 rounded-xl bg-white/15 px-3.5 py-2 text-sm font-medium backdrop-blur transition hover:bg-white/25"
                        @click="mostrarRoles = !mostrarRoles"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
                        Cambiar de rol
                    </button>
                </div>

                <!--
                    El cielo se ajusta a lo que ocupe el clima, no al revés: si
                    la pantalla no da para los próximos días, el trozo de color
                    encoge con ellos en vez de quedarse medio vacío.
                -->
                <div
                    v-if="clima"
                    class="franja-cielo relative shrink-0 sm:max-w-[72%]"
                    :style="{ background: cielo }"
                >
                    <CieloDecorado :noche="esDeNoche" />

                    <!-- `relative`: por encima del cielo, que va en absoluto. -->
                    <div class="relative px-6 py-5">
                        <ClimaEnCabecera
                            :clima="clima"
                            :puede-ubicar="puedeUbicar"
                            :ubicando="ubicando"
                            @ubicar="conMiUbicacion"
                        />
                    </div>
                </div>
            </div>

            <!-- Conmutador de rol (se despliega desde el botón). -->
            <div
                v-if="mostrarRoles && rolesDisponibles.length"
                class="relative grid gap-2 border-t border-white/20 p-6 sm:grid-cols-2 lg:grid-cols-3"
            >
                <button
                    v-for="rol in rolesDisponibles"
                    :key="`${rol.id}-${rol.campus_id ?? 'global'}`"
                    type="button"
                    class="rounded-xl px-4 py-3 text-left text-sm transition"
                    :class="esActivo(rol.id) ? 'bg-superficie text-contenido shadow' : 'bg-white/10 hover:bg-white/20'"
                    @click="conmutar(rol.id)"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="font-medium">{{ rol.nombre }}</span>
                        <span
                            v-if="esActivo(rol.id)"
                            class="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white"
                            :style="{ backgroundColor: 'var(--color-acento)' }"
                        >
                            Activo
                        </span>
                    </span>
                    <!--
                        El campus al que está acotado, si lo hay. «Alcance
                        global» sólo se le dice a quien administra: es la
                        contraparte de «acotado a un campus» y sabe lo que
                        significa. Los roles de un alumno o de un padre nunca
                        llevan campus, así que a ellos les salía siempre esa
                        frase sin querer decirles nada.
                    -->
                    <span
                        v-if="rol.campus_nombre || jergaOk"
                        class="mt-0.5 block text-xs opacity-80"
                    >
                        {{ rol.campus_nombre ? `Acotado a ${rol.campus_nombre}` : 'Alcance global' }}
                    </span>
                </button>
            </div>
        </section>

        <!--
            El panel NO tiene ramas por rol: el backend entrega las tarjetas que
            esta persona puede ver, y aquí solo se saben pintar cuatro formas.
            Una tarjeta nueva que use una de ellas no toca este archivo.
        -->
        <!--
            Las tarjetas de una misma fila miden lo mismo.

            Antes iban con `items-start` para que cada una midiera lo suyo: se
            prefería denso e irregular a alineado y vacío. En la práctica el
            resultado fue lo contrario de lo buscado —una fila con una tarjeta
            de 150px junto a otra de 200 deja un escalón, y el ojo lee el hueco
            como algo roto—, así que ahora estiran.

            Lo que aquel comentario temía —una métrica de un número con el 60%
            en blanco— se resuelve repartiendo el contenido, no encogiendo la
            tarjeta: el cuerpo es una columna flex y el número se centra en el
            espacio que le toque (ver `.tarjeta-panel`).
        -->
        <!--
            Dos columnas: el trabajo a la izquierda, el CONTEXTO a la derecha.

            A la derecha va lo que uno consulta —qué día es, qué viene, cómo
            está el clima—; a la izquierda, lo que reclama acción. Mezclarlos en
            una sola rejilla obligaba a barrer toda la pantalla para encontrar
            lo que había que hacer hoy, que es a lo que se entra al panel.

            La agenda se queda pegada al desplazarse (`sticky`): con seis
            tarjetas, al bajar a mirar una métrica uno perdía de vista lo que
            vence mañana.
        -->
        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
            <!--
                `grid-flow-dense`: las tarjetas chicas se meten en los huecos
                que dejan las anchas en vez de esperar su turno. Sin él, una
                tarjeta de 3 columnas dejaba la cuarta vacía toda la fila
                aunque la siguiente cupiera ahí — el panel quedaba con agujeros.
            -->
            <!--
                `min-w-0` en las dos columnas.

                Un hijo de grid trae `min-width: auto`, o sea que no encoge por
                debajo del ancho natural de su contenido. Bastaba con un evento
                de título largo en la agenda para que su columna reclamara 412px
                en una pantalla de 390 y TODO el panel se pudiera arrastrar de
                lado, tarjetas incluidas. Con esto vuelven a mandar los
                `truncate` que ya tenía cada texto.
            -->
            <div v-if="props.tarjetas.length" class="min-w-0">
            <!--
                La barra de acomodo.

                Fuera del modo, un solo botón discreto; dentro, las tres
                acciones. «Restablecer» sólo aparece acomodando porque es
                destructivo y no tiene por qué estar a un clic de distancia
                cuando nadie pidió tocar nada.
            -->
            <div class="mb-3 flex flex-wrap items-center justify-end gap-2">
                <p v-if="acomodando" class="mr-auto text-xs" :style="{ color: 'var(--color-suave)' }">
                    Arrastra las tarjetas para ordenarlas, o muévelas con
                    <kbd class="rounded border px-1">←</kbd>
                    <kbd class="rounded border px-1">→</kbd>. El botón de cada
                    una cambia entre el ancho normal y el doble.
                </p>
                <button
                    v-if="!acomodando"
                    type="button"
                    class="rounded-full border px-3 py-1 text-xs font-medium transition"
                    :style="{ color: 'var(--color-suave)', borderColor: 'var(--color-borde)' }"
                    @click="acomodar"
                >
                    Acomodar
                </button>
                <template v-else>
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition"
                        :style="{ color: 'var(--color-suave)', borderColor: 'var(--color-borde)' }"
                        @click="restablecerAcomodo"
                    >
                        Restablecer
                    </button>
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium transition"
                        :style="{ color: 'var(--color-suave)', borderColor: 'var(--color-borde)' }"
                        @click="cancelarAcomodo"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-3 py-1 text-xs font-semibold text-white transition"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        @click="guardarAcomodo"
                    >
                        Guardar
                    </button>
                </template>
            </div>

            <section class="grid min-w-0 grid-flow-dense gap-4 sm:grid-cols-4">
            <!--
                Sólo `--tono` viaja en línea; `--color-tarjeta` lo pone el CSS.

                Es a propósito: dentro de una destacada, `--color-tarjeta` pasa a
                ser el tono ENSOMBRECIDO, y así el icono, el trazo del SVG y el
                enlace «Ver» —que ya lo usaban— salen legibles sobre el fondo
                teñido sin duplicar una sola línea de marcado. Si
                `--color-tarjeta` se pusiera en línea le ganaría a esa regla y
                no habría manera.
            -->
            <div
                v-for="(tarjeta, i) in tarjetasEnPantalla"
                :key="tarjeta.clave"
                class="tarjeta tarjeta-panel animar-entrada p-5"
                :class="[
                    adornos[i],
                    {
                        'sm:col-span-1': tarjeta.ancho === 1,
                        'sm:col-span-2': tarjeta.ancho === 2,
                        'sm:col-span-3': tarjeta.ancho === 3,
                        'sm:col-span-4': tarjeta.ancho === 4,
                        'tarjeta-destacada': esDestacada(tarjeta),
                        'tarjeta-acomodando': acomodando,
                        'tarjeta-en-vuelo': arrastrando === i,
                    },
                ]"
                :style="{ '--tono': tonoTarjeta(tarjeta), animationDelay: `${i * 45}ms` }"
                :draggable="acomodando"
                @dragstart="arrastrando = i"
                @dragover.prevent="soltarSobre(i)"
                @dragend="arrastrando = null"
            >
                <!--
                    Los mandos de acomodo, sólo mientras se acomoda.

                    Van encima del contenido de la tarjeta y no en una barra
                    aparte: el tamaño y la posición son de ESTA tarjeta, y
                    tenerlos donde se mira es lo que hace que el cambio se
                    entienda sin explicación.
                -->
                <div v-if="acomodando" class="mandos-acomodo">
                    <button
                        type="button"
                        class="mando"
                        :title="`Mover «${tarjeta.titulo}» hacia atrás`"
                        @click="moverConTeclado(i, -1)"
                    >
                        ←
                    </button>
                    <button
                        type="button"
                        class="mando px-2"
                        :title="
                            tarjeta.ancho === 4
                                ? `Devolver «${tarjeta.titulo}» al ancho normal`
                                : `Poner «${tarjeta.titulo}» al ancho doble`
                        "
                        @click="alternarAncho(i)"
                    >
                        {{ tarjeta.ancho === 4 ? 'Ancho doble' : 'Ancho normal' }}
                    </button>
                    <button
                        type="button"
                        class="mando"
                        :title="`Mover «${tarjeta.titulo}» hacia adelante`"
                        @click="moverConTeclado(i, 1)"
                    >
                        →
                    </button>
                </div>

                <!--
                    El icono, otra vez y en grande, de marca de agua.

                    Es el MISMO `tarjeta.icono` que va en el círculo del
                    encabezado: un solo dato de la tarjeta sirviendo para las dos
                    cosas. Sale cortado por el borde a propósito —se ve un
                    pedazo, no la figura completa—, que es lo que lo mantiene
                    como fondo y no como un segundo icono compitiendo con el
                    primero.

                    Convive con el aro porque cada uno tiene su esquina, y en
                    diagonal: el aro entra por arriba a la izquierda y la marca
                    sale por abajo a la derecha. Encimados se estorbarían.
                -->
                <svg
                    v-if="CON_MARCA_DE_AGUA.has(tarjeta.clave)"
                    class="marca-agua"
                    aria-hidden="true"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="0.8"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" :d="tarjeta.icono" />
                </svg>

                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2.5">
                        <!--
                            El icono lo declara la tarjeta, no la pantalla: quien
                            agregue una nueva no debería editar este archivo para
                            que se vea como las demás. Cada tarjeta lleva su color.
                        -->
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-tarjeta) 14%, transparent)' }"
                        >
                            <svg
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="1.7"
                                :stroke="'var(--color-tarjeta)'"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" :d="tarjeta.icono" />
                            </svg>
                        </span>
                        <h2 class="text-sm font-semibold">{{ tarjeta.titulo }}</h2>
                    </div>
                    <a
                        v-if="tarjeta.datos.enlace"
                        :href="tarjeta.datos.enlace"
                        class="shrink-0 text-xs font-medium"
                        :style="{ color: 'var(--color-tarjeta)' }"
                    >
                        Ver
                    </a>
                </div>

                <!--
                    Métrica: un número grande y su contexto.

                    El rojo de Tailwind sólo se usa sobre fondo neutro. En la
                    destacada la tarjeta ENTERA ya está teñida de rojo —ver
                    `tonoTarjeta`—, así que la cifra toma `--color-tarjeta`: es
                    el mismo rojo pero ensombrecido contra el texto del tema, y
                    ése sí está medido contra el tinte. El `text-red-600` de
                    Tailwind, que no sabe nada del fondo, quedaría flojo encima.
                -->
                <template v-if="tarjeta.tipo === 'metrica'">
                    <p
                        class="mt-3 text-3xl font-semibold tracking-tight tabular-nums"
                        :class="tarjeta.datos.alerta && !esDestacada(tarjeta) ? 'text-red-600' : ''"
                        :style="esDestacada(tarjeta) ? { color: 'var(--color-tarjeta)' } : {}"
                    >
                        {{ formatear(tarjeta.datos.valor, tarjeta.datos.formato) }}
                    </p>
                    <p
                        class="mt-0.5 text-xs"
                        :class="tarjeta.datos.alerta && !esDestacada(tarjeta) ? 'font-medium text-red-600' : ''"
                        :style="tarjeta.datos.alerta && !esDestacada(tarjeta) ? {} : { color: 'var(--color-suave)' }"
                    >
                        {{ tarjeta.datos.pie }}
                    </p>
                </template>

                <!--
                    Matriz: día de la semana contra hora, un punto por celda.

                    Se dibuja con puntos y no con barras porque lo que se lee
                    aquí son DOS ejes a la vez. Con 168 valores en una gráfica de
                    barras habría que elegir cuál de los dos manda —24 barras por
                    día, o 7 por hora— y el otro se perdería. El punto no ordena
                    nada: crece donde hay actividad, y la forma de la semana
                    aparece sola.

                    Las 24 horas se salen en pantalla estrecha, así que la
                    cuadrícula lleva su propio desplazamiento horizontal. Que la
                    tarjeta se pueda arrastrar de lado no debe arrastrar el panel
                    entero con ella.
                -->
                <template v-else-if="tarjeta.tipo === 'matriz'">
                    <div class="matriz-envoltura mt-3">
                        <div class="matriz">
                            <template v-for="fila in tarjeta.datos.filas" :key="fila.etiqueta">
                                <span class="matriz-dia">{{ fila.etiqueta }}</span>
                                <!--
                                    El riel gris de fondo va POR CELDA y no como
                                    una línea detrás de todas: así el punto de
                                    valor cero es el propio riel y no hace falta
                                    dibujar dos cosas distintas según haya o no
                                    actividad.
                                -->
                                <span
                                    v-for="(valor, hora) in fila.horas"
                                    :key="hora"
                                    class="matriz-celda"
                                    :title="`${fila.etiqueta} a las ${String(hora).padStart(2, '0')}:00 — ${valor}`"
                                >
                                    <span
                                        class="matriz-punto"
                                        :style="{
                                            '--tamano': `${tamanoPunto(valor, tarjeta.datos.maximo)}px`,
                                            '--fuerza': `${fuerzaPunto(valor, tarjeta.datos.maximo)}%`,
                                        }"
                                    ></span>
                                </span>
                                <span class="matriz-total tabular-nums">{{ fila.total }}</span>
                            </template>

                            <!-- El eje de horas, alineado con las columnas de arriba. -->
                            <span></span>
                            <span v-for="hora in 24" :key="`h${hora}`" class="matriz-hora">
                                {{ String(hora - 1).padStart(2, '0') }}
                            </span>
                            <span></span>
                        </div>
                    </div>
                    <p v-if="tarjeta.datos.pie" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ tarjeta.datos.pie }}
                    </p>
                </template>

                <!-- Lista: renglones con su valor a la derecha. -->
                <template v-else-if="tarjeta.tipo === 'lista'">
                    <ul class="mt-3 space-y-3">
                        <li v-for="(renglon, i) in tarjeta.datos.renglones" :key="i" class="text-sm">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <component
                                    :is="renglon.enlace ? 'a' : 'span'"
                                    :href="renglon.enlace"
                                    class="font-medium"
                                    :style="renglon.enlace ? { color: 'var(--color-acento)' } : {}"
                                >
                                    {{ renglon.etiqueta }}
                                </component>
                                <span
                                    class="text-xs tabular-nums"
                                    :class="renglon.alerta ? 'font-semibold text-red-600' : ''"
                                    :style="renglon.alerta ? {} : { color: 'var(--color-suave)' }"
                                >
                                    {{ renglon.valor }}
                                </span>
                            </div>
                            <p v-if="renglon.detalle" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ renglon.detalle }}
                            </p>
                            <div
                                v-if="renglon.progreso !== null && renglon.progreso !== undefined"
                                class="mt-1 h-1.5 w-full rounded-full"
                                :style="{ backgroundColor: 'var(--color-borde)' }"
                            >
                                <div
                                    class="h-1.5 rounded-full"
                                    :style="{ width: renglon.progreso + '%', backgroundColor: 'var(--color-acento)' }"
                                ></div>
                            </div>
                            <p v-if="renglon.pie" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ renglon.pie }}
                            </p>
                        </li>
                    </ul>
                    <p v-if="tarjeta.datos.pie" class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ tarjeta.datos.pie }}
                    </p>
                </template>

                <!--
                    Embudo: las etapas de un mismo recorrido, en orden.

                    La barra se mide contra el TOTAL del embudo y no contra la
                    etapa más poblada, así que su largo dice cuánta gente hay
                    parada ahí de verdad. El porcentaje viene calculado del
                    servidor —ver `EmbudoDeAdmision`—: aquí sólo se dibuja.

                    Y el tono se va apagando etapa tras etapa. Es lo que hace
                    que el embudo se lea como un recorrido y no como cinco
                    barras sueltas: se ve de un vistazo dónde se junta la gente
                    y cómo se adelgaza hacia el final.
                -->
                <template v-else-if="tarjeta.tipo === 'embudo'">
                    <ul class="mt-3 space-y-0.5">
                        <li v-for="(punto, i) in tarjeta.datos.series" :key="i">
                            <component
                                :is="punto.enlace ? 'a' : 'div'"
                                :href="punto.enlace"
                                class="paso-embudo -mx-2 block rounded-lg px-2 py-1.5"
                                :style="{ '--paso': i, '--parte': `${punto.parte}%`, '--fuerza': `${fuerzaEtapa(i)}%` }"
                            >
                                <div class="flex items-baseline justify-between gap-2 text-xs">
                                    <span class="truncate font-medium">{{ punto.etiqueta }}</span>
                                    <span class="shrink-0 tabular-nums">
                                        <span class="font-semibold">{{ punto.valor }}</span>
                                        <span class="ml-1 text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                            {{ punto.parte }}%
                                        </span>
                                    </span>
                                </div>
                                <div class="riel-embudo mt-1">
                                    <span class="relleno-embudo"></span>
                                </div>
                            </component>
                        </li>
                    </ul>
                    <p v-if="tarjeta.datos.pie" class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ tarjeta.datos.pie }}
                    </p>
                </template>

                <!-- Barras: una serie con etiqueta. CSS puro, sin lib de charts. -->
                <template v-else-if="tarjeta.tipo === 'barras'">
                    <ul class="mt-3 space-y-2">
                        <li v-for="(punto, i) in tarjeta.datos.series" :key="i">
                            <component :is="punto.enlace ? 'a' : 'div'" :href="punto.enlace" class="block">
                                <div class="flex items-center justify-between text-xs">
                                    <span>{{ punto.etiqueta }}</span>
                                    <span class="tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                        {{ punto.valor }}
                                    </span>
                                </div>
                                <div class="mt-0.5 h-1.5 w-full rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                                    <div
                                        class="h-1.5 rounded-full"
                                        :style="{
                                            width: ancho(tarjeta.datos.series, punto.valor),
                                            backgroundColor: 'var(--color-acento)',
                                        }"
                                    ></div>
                                </div>
                            </component>
                        </li>
                    </ul>
                    <p v-if="tarjeta.datos.pie" class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ tarjeta.datos.pie }}
                    </p>
                </template>

                <!--
                    Columnas: una serie larga —las 24 horas— es naturalmente
                    ancha y BAJA. Como barras horizontales apiladas ocupaba
                    media pantalla de alto y tapaba el resto del panel.
                -->
                <template v-else-if="tarjeta.tipo === 'columnas'">
                    <div class="mt-4 flex h-28 items-end gap-[3px]">
                        <div
                            v-for="(punto, i) in tarjeta.datos.series"
                            :key="i"
                            class="group relative flex-1"
                            :title="`${punto.etiqueta}: ${punto.valor}`"
                        >
                            <div class="flex h-28 items-end">
                                <div
                                    class="w-full rounded-t transition-all group-hover:opacity-80"
                                    :style="{
                                        height: alto(tarjeta.datos.series, punto.valor),
                                        backgroundColor:
                                            punto.valor === 0
                                                ? 'var(--color-borde)'
                                                : 'var(--color-acento)',
                                        minHeight: punto.valor === 0 ? '2px' : undefined,
                                    }"
                                ></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-1.5 flex gap-[3px]">
                        <span
                            v-for="(punto, i) in tarjeta.datos.series"
                            :key="i"
                            class="flex-1 text-center text-[10px] leading-none"
                            :style="{ color: 'var(--color-suave)' }"
                        >
                            {{ rotula(i, tarjeta.datos.series.length) ? punto.etiqueta.replace('h', '') : '' }}
                        </span>
                    </div>

                    <p v-if="tarjeta.datos.pie" class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ tarjeta.datos.pie }}
                    </p>
                </template>

                <!--
                    Accesos: agrupados por oficio y con la cifra de lo que
                    espera detrás.

                    Antes eran doce recuadros idénticos, que es lo mismo que
                    ofrece el menú lateral. Lo que los vuelve útiles es el
                    número: «Aspirantes» es navegación, «Aspirantes · 12 sin
                    contactar» es una razón para entrar. Lo que tiene pendientes
                    se ve distinto —fondo teñido y cifra a la derecha— para que
                    la vista caiga ahí sola.
                -->
                <!--
                    Encuestas abiertas: sólo la participación.

                    Es la pregunta que se hace quien lanzó una encuesta —¿está
                    contestando la gente?— y hay que poder contestarla mientras
                    sigue abierta, que es cuando todavía se puede insistir. Los
                    promedios NO salen aquí: un número leído de pasada, sin
                    cuánta gente contestó ni el umbral de anonimato, se
                    malinterpreta.
                -->
                <template v-else-if="tarjeta.tipo === 'encuestas'">
                    <ul class="mt-4 space-y-3">
                        <li v-for="e in tarjeta.datos.encuestas" :key="e.id">
                            <a :href="`/encuestas/aplicaciones/${e.id}`" class="block rounded-xl border p-3 transition hover:shadow-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ e.titulo }}</span>

                                    <!-- Los días que quedan deciden si insistir
                                         todavía sirve; en rojo cuando queda poco. -->
                                    <span
                                        v-if="e.dias !== null"
                                        class="shrink-0 text-xs"
                                        :style="{ color: e.dias <= 3 ? '#dc2626' : 'var(--color-suave)' }"
                                    >
                                        {{ e.dias === 0 ? 'cierra hoy' : `${e.dias} d` }}
                                    </span>
                                </div>

                                <div v-if="e.porcentaje !== null" class="mt-2">
                                    <div class="h-1.5 overflow-hidden rounded-full" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 15%, transparent)' }">
                                        <div
                                            class="h-full rounded-full"
                                            :style="{
                                                width: `${Math.min(100, e.porcentaje)}%`,
                                                backgroundColor: e.porcentaje < 30 ? '#dc2626' : e.porcentaje < 60 ? '#d97706' : '#16a34a',
                                            }"
                                        />
                                    </div>
                                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                        {{ e.respuestas }} de {{ e.esperadas }} · {{ e.porcentaje }}% de participación
                                    </p>
                                </div>

                                <p v-else class="mt-1.5 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ e.respuestas }} {{ e.respuestas === 1 ? 'respuesta' : 'respuestas' }}
                                </p>
                            </a>
                        </li>
                    </ul>
                </template>

                <template v-else-if="tarjeta.tipo === 'accesos'">
                    <div class="mt-4 space-y-4">
                        <div v-for="grupo in tarjeta.datos.grupos" :key="grupo.nombre">
                            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider" :style="{ color: 'var(--color-suave)' }">
                                {{ grupo.nombre }}
                            </p>

                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                <a
                                    v-for="acceso in grupo.accesos"
                                    :key="acceso.enlace"
                                    :href="acceso.enlace"
                                    class="acceso-directo flex items-center gap-3 rounded-xl border px-3 py-2.5 transition"
                                    :style="acceso.pendiente
                                        ? { borderColor: acceso.pendiente.urgente ? '#dc2626' : 'var(--color-acento)', backgroundColor: `color-mix(in srgb, ${acceso.pendiente.urgente ? '#dc2626' : 'var(--color-acento)'} 6%, transparent)` }
                                        : { borderColor: 'var(--color-borde)' }"
                                >
                                    <span
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                        :style="{ backgroundColor: `color-mix(in srgb, ${acceso.pendiente?.urgente ? '#dc2626' : 'var(--color-acento)'} 12%, transparent)` }"
                                    >
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" :stroke="acceso.pendiente?.urgente ? '#dc2626' : 'var(--color-acento)'">
                                            <path stroke-linecap="round" stroke-linejoin="round" :d="acceso.icono" />
                                        </svg>
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium leading-tight">{{ acceso.etiqueta }}</span>
                                        <!-- Sólo si hay algo que decir: un «0
                                             por calificar» ocupa el mismo sitio
                                             que un dato útil. -->
                                        <span
                                            v-if="acceso.pendiente"
                                            class="block truncate text-xs"
                                            :style="{ color: acceso.pendiente.urgente ? '#dc2626' : 'var(--color-acento)' }"
                                        >
                                            {{ acceso.pendiente.cantidad }} {{ acceso.pendiente.texto }}
                                        </span>
                                    </span>

                                    <span
                                        v-if="acceso.pendiente"
                                        class="grid h-6 min-w-6 shrink-0 place-items-center rounded-full px-1.5 text-[11px] font-semibold text-white"
                                        :style="{ backgroundColor: acceso.pendiente.urgente ? '#dc2626' : 'var(--color-acento)' }"
                                    >
                                        {{ acceso.pendiente.cantidad }}
                                    </span>
                                </a>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            </section>
            </div>

            <!--
                El panel vacío, dicho de dos maneras.
                A quien administra le sirve saber que las tarjetas dependen de
                los permisos del rol activo: sabe dónde tocarlos. A un padre de
                familia esa frase le habla de una maquinaria que no conoce y no
                le dice qué hacer.
            -->
            <section v-else class="tarjeta min-w-0 px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                <template v-if="jergaOk">
                    Tu rol activo todavía no tiene nada que mostrar aquí. Las tarjetas del panel aparecen según
                    los permisos que tenga.
                </template>
                <template v-else>
                    Todavía no hay nada que mostrarte aquí. Usa el menú de la izquierda para ver tu información.
                </template>
            </section>

            <!-- El contexto: qué día es y qué viene. El clima subió a la banda. -->
            <aside class="min-w-0 space-y-4 xl:sticky xl:top-4">
                <AgendaLateral
                    :mes="agenda.mes"
                    :proximos="agenda.proximos"
                    :marcados="agenda.marcados"
                    :hoy="agenda.hoy"
                    :efemerides="agenda.efemerides"
                />
            </aside>
        </div>
    </AppLayout>
</template>

<style scoped>
/*
 * El cielo se desvanece en vez de cortarse.
 *
 * Sin esto había una línea vertical dura a media banda, y el ojo la leía como
 * dos tarjetas pegadas. La máscara vuelve transparente el borde por donde el
 * cielo se encuentra con el fondo claro, así que el color aparece sin que se
 * vea dónde empieza.
 *
 * En vertical cuando la banda se apila —en un teléfono el clima cae debajo del
 * nombre, no al lado, y el desvanecido tiene que seguirlo—.
 */
.franja-cielo {
    /*
     * Una sola medida manda las dos cosas: hasta dónde llega el desvanecido y
     * cuánto respiro deja el texto. Con porcentajes no cuadraban —el 42% de una
     * franja que se ajusta a su contenido cae en un sitio distinto según haya
     * tres días de pronóstico o ninguno—, y la temperatura terminaba escrita en
     * blanco sobre la parte donde el cielo ya casi no existe.
     */
    --fundido: 2.75rem;

    padding-top: var(--fundido);
    -webkit-mask-image: linear-gradient(to bottom, transparent, #000 var(--fundido));
    mask-image: linear-gradient(to bottom, transparent, #000 var(--fundido));
}

@media (min-width: 640px) {
    .franja-cielo {
        --fundido: 7rem;

        padding-top: 0;
        padding-left: var(--fundido);
        -webkit-mask-image: linear-gradient(to right, transparent, #000 var(--fundido));
        mask-image: linear-gradient(to right, transparent, #000 var(--fundido));
    }
}

/* Cada tarjeta lleva un acento superior de su color y una elevación al pasar el
   cursor teñida del mismo color: vistoso pero sobrio (el fondo sigue neutro). */
/* El atajo se levanta al pasar el cursor, como el resto de lo clicable. */
.acceso-directo:hover {
    border-color: var(--color-acento);
    transform: translateY(-1px);
}

.tarjeta-panel {
    /*
     * El color propio de la tarjeta, para todo lo que ya lo usaba. Se declara
     * aquí y no en línea para que `.tarjeta-destacada` pueda cambiarlo.
     */
    --color-tarjeta: var(--tono);

    border-top: 3px solid var(--color-tarjeta);
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;

    /*
     * Columna flex para que el contenido se REPARTA en el alto que le tocó.
     *
     * Al estirarse todas las tarjetas de una fila al alto de la más alta, una
     * métrica de un solo número se quedaría con su número arriba y el resto en
     * blanco. Con esto, lo que va después del encabezado ocupa el hueco en vez
     * de dejarlo: el número queda centrado en su espacio y la tarjeta se ve
     * llena, que es de lo que se trataba.
     */
    display: flex;
    flex-direction: column;

    /* Marco de referencia de las burbujas, que van en absoluto y se recortan. */
    position: relative;
    overflow: hidden;
}

/*
 * El adorno de la esquina.
 *
 * Dos círculos por tarjeta, decorativos —de ahí que sean pseudoelementos y no
 * marcado— y recortados por el `overflow`. Van en TODAS, teñidos del tono
 * propio de cada una, así que la de lista y la de barras tienen la misma gracia
 * sin volverse un bloque de color (que sobre veinte renglones o una gráfica
 * cansaría de leer).
 *
 * ── Dos formas, cuatro esquinas ────────────────────────────────────────────
 * El mismo par en la misma esquina en las cinco tarjetas se leía como una
 * plantilla repetida, pero seis formas distintas era el otro extremo: parecían
 * seis diseños en la misma pantalla. Quedan las DOS que funcionaron —el par de
 * discos y el anillo grueso con su disco suelto— y lo que alterna es por dónde
 * entran y con cuánta fuerza.
 *
 * Cada tarjeta toma la que le toca por posición —ver `adorno()`—, y la lista
 * está ordenada para que al recorrerla cambien las dos cosas a la vez: la forma
 * y el lado. Dos tarjetas vecinas nunca comparten ninguna.
 *
 * Los tonos van en variables y no repetidos en cada regla: los adornos definen
 * FORMA Y SITIO, nunca color. Así, cambiar cuánto se nota el adorno en todo el
 * panel es cambiar estas dos líneas.
 *
 * El relleno se queda bajo a propósito: al 9% sobre la superficie apenas se
 * adivina, que es lo que se busca —textura de fondo, no una mancha que compita
 * con el dato.
 */
.tarjeta-panel {
    --relleno: color-mix(in srgb, var(--tono) 9%, transparent);
    --relleno-tenue: color-mix(in srgb, var(--tono) 6%, transparent);
}

.tarjeta-panel::before,
.tarjeta-panel::after {
    content: "";
    position: absolute;
    border-radius: 999px;
    pointer-events: none;
}

/*
 * ── Las cuatro entran por arriba, y por qué ────────────────────────────────
 * Se probaron por las cuatro esquinas y midiendo qué texto quedaba debajo salió
 * clarísimo: por abajo el adorno cae encima del contenido. La lista termina en
 * renglones, la gráfica en las etiquetas de sus barras y los atajos en su
 * última fila —todo texto, y todo pegado al borde inferior.
 *
 * Arriba no: ahí sólo está el encabezado, que es un renglón corto con el título
 * a la izquierda, el enlace «Ver» a la derecha y hueco en medio. Es la única
 * franja de la tarjeta que se ve igual en las cuatro formas.
 *
 * Así que lo que alterna es el LADO por el que entran —izquierda o derecha— y
 * no la esquina. Ninguna baja de los 130 px, que es donde termina el encabezado
 * con su margen; de ahí para abajo la tarjeta se queda limpia.
 */

/* 1 · El par de discos, entrando por arriba a la derecha. */
.adorno-1::after {
    right: -3.5rem;
    top: -3.5rem;
    width: 11rem;
    height: 11rem;
    background: var(--relleno);
}
.adorno-1::before {
    right: 1.75rem;
    top: -5.25rem;
    width: 8rem;
    height: 8rem;
    background: var(--relleno-tenue);
}

/* 2 · El anillo grueso, arriba a la izquierda, con su disco suelto. */
.adorno-2::after {
    left: -4rem;
    top: -4rem;
    width: 12rem;
    height: 12rem;
    border: 2rem solid var(--relleno);
}
.adorno-2::before {
    left: 6rem;
    top: 1.5rem;
    width: 2.5rem;
    height: 2.5rem;
    background: var(--relleno-tenue);
}

/*
 * Las dos que siguen son las mismas formas por el lado contrario, y van todas
 * en el tinte flojo. Es lo que hace que la repetición se lea como variación:
 * al volver a aparecer la forma, aparece más apagada.
 */

/* 3 · El par de discos, entrando por arriba a la izquierda. */
.adorno-3::after {
    left: -3.5rem;
    top: -3.5rem;
    width: 11rem;
    height: 11rem;
    background: var(--relleno-tenue);
}
.adorno-3::before {
    left: 1.75rem;
    top: -5.25rem;
    width: 8rem;
    height: 8rem;
    background: var(--relleno-tenue);
}

/* 4 · El anillo grueso, arriba a la derecha. Su disco suelto se queda en el
   hueco del encabezado, entre el título y el enlace «Ver». */
.adorno-4::after {
    right: -4rem;
    top: -4rem;
    width: 12rem;
    height: 12rem;
    border: 2rem solid var(--relleno-tenue);
}
.adorno-4::before {
    right: 6rem;
    top: 1.5rem;
    width: 2.5rem;
    height: 2.5rem;
    background: var(--relleno-tenue);
}

/* Por encima de las burbujas, que van en absoluto. */
.tarjeta-panel > * {
    position: relative;
    z-index: 1;
}

/* ── La matriz de día contra hora ───────────────────────────────────────── */

/*
 * El desplazamiento se queda DENTRO de la tarjeta.
 *
 * Con 24 columnas la cuadrícula pide unos 640 px de ancho mínimo, y en un
 * teléfono no los hay. Sin este envoltorio la tarjeta empujaba la cuadrícula del
 * panel y se podía arrastrar la pantalla entera de lado —el mismo problema que
 * ya obligó a poner `min-w-0` en las columnas del panel.
 */
.matriz-envoltura {
    overflow-x: auto;
    margin-inline: -0.25rem;
    padding-inline: 0.25rem;
}

.matriz {
    display: grid;
    /* Día, las 24 horas, y el total de la fila. Las horas se reparten lo que
       sobra en partes iguales, con un mínimo para que los puntos grandes no
       lleguen a tocarse. */
    grid-template-columns: auto repeat(24, minmax(1.15rem, 1fr)) auto;
    align-items: center;
    gap: 0.15rem 0;
    min-width: 34rem;
}

.matriz-dia {
    padding-right: 0.6rem;
    text-align: right;
    font-size: 0.7rem;
    white-space: nowrap;
    color: var(--color-suave);
}

.matriz-total {
    padding-left: 0.6rem;
    text-align: right;
    font-size: 0.7rem;
    font-weight: 600;
    color: color-mix(in srgb, var(--tono) 55%, var(--color-contenido));
}

.matriz-hora {
    padding-top: 0.35rem;
    text-align: center;
    font-size: 0.6rem;
    color: color-mix(in srgb, var(--color-suave) 75%, transparent);
}

/*
 * La celda dibuja el riel de su tramo con un borde de un píxel, y el punto va
 * centrado encima. Así la línea que une las horas del día sale sola, sin un
 * elemento aparte que haya que mantener alineado con las columnas.
 */
.matriz-celda {
    display: grid;
    place-items: center;
    height: 1.5rem;
    border-top: 1px solid color-mix(in srgb, var(--color-borde) 70%, transparent);
}

.matriz-punto {
    width: var(--tamano);
    height: var(--tamano);
    border-radius: 999px;
    /* Al 0% de fuerza queda el gris del riel: la celda vacía no necesita otra
       regla, es el mismo punto sin tono. */
    background: color-mix(
        in srgb,
        var(--tono) var(--fuerza),
        color-mix(in srgb, var(--color-borde) 75%, transparent)
    );
    transition: transform 0.15s ease;
}

.matriz-celda:hover .matriz-punto {
    transform: scale(1.25);
}

/* ── Modo acomodar ──────────────────────────────────────────────────────── */

.tarjeta-acomodando {
    cursor: grab;
    /* Se marca el borde con el tono para que se vea de un golpe QUÉ se puede
       mover. Sin señal, el modo se activaba y la pantalla parecía la misma. */
    border-color: color-mix(in srgb, var(--tono) 45%, var(--color-borde));
}

/* La que va en la mano: se apaga para que se siga el hueco, no la tarjeta. */
.tarjeta-en-vuelo {
    opacity: 0.45;
    cursor: grabbing;
}

/*
 * Los mandos, flotando sobre la esquina superior derecha de la tarjeta.
 *
 * En absoluto y no en el flujo a propósito: si ocuparan sitio, entrar al modo
 * de acomodo cambiaría el alto de las tarjetas y toda la cuadrícula daría un
 * salto justo cuando se está intentando acomodarla.
 */
.mandos-acomodo {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    z-index: 2;
    display: flex;
    gap: 0.25rem;
    border-radius: 999px;
    padding: 0.15rem;
    background: color-mix(in srgb, var(--color-superficie) 88%, var(--tono));
    box-shadow: 0 2px 8px -2px rgb(0 0 0 / 0.18);
}

.mando {
    min-width: 1.5rem;
    border-radius: 999px;
    padding: 0.1rem 0.35rem;
    font-size: 0.7rem;
    font-weight: 600;
    line-height: 1.4;
    color: color-mix(in srgb, var(--tono) 55%, var(--color-contenido));
    transition: background-color 0.15s ease;
}

.mando:hover {
    background: color-mix(in srgb, var(--tono) 16%, transparent);
}

/*
 * La marca de agua: el icono de la tarjeta, en grande y cortado por el borde.
 *
 * Va DESPUÉS de la regla de arriba y con la misma especificidad para poder
 * ganarle el `position: relative` —es hija directa de la tarjeta como cualquier
 * otra—. Y se queda en `z-index: 0` para pasar por debajo de sus hermanas, que
 * están todas en 1.
 *
 * `stroke` y no `fill` porque el icono es de trazo: relleno se convertiría en
 * una mancha ilegible. Al 14% el contorno se adivina sin discutirle nada al
 * texto que le pasa por encima.
 */
.marca-agua {
    /* Absoluta, y por eso NO puede cambiar el tamaño de la tarjeta: está fuera
       del flujo, no ocupa sitio y lo que se sale lo recorta el `overflow` de la
       tarjeta. Da igual de qué tamaño se ponga: la fila mide lo que mida su
       contenido, no su fondo. */
    position: absolute;
    z-index: 0;

    /* Abajo a la DERECHA, en la esquina diagonalmente opuesta al aro, que entra
       por arriba a la izquierda —ver `ADORNO_CON_MARCA`—. Las dos decoraciones
       se reparten la diagonal en vez de encimarse: no comparten ni el lado ni
       la mitad.

       Grande y muy salida: de los 15 rem sólo asoma la esquina superior
       izquierda del icono. Que se vea un pedazo y no la figura entera es lo que
       lo mantiene como textura de fondo —si se reconociera el dibujo completo
       sería un segundo icono discutiéndole el sitio al del encabezado. */
    right: -6rem;
    bottom: -6.5rem;
    width: 15rem;
    height: 15rem;
    stroke: color-mix(in srgb, var(--tono) 14%, transparent);
    pointer-events: none;
}

/* ── El embudo ──────────────────────────────────────────────────────────── */

.paso-embudo {
    /* El tono de ESTA etapa. Sube a tope al pasar el cursor, y como es un
       `background-color` calculado, la transición lo interpola solo. */
    --color-paso: color-mix(in srgb, var(--tono) var(--fuerza), var(--color-superficie));

    transition: background-color 0.2s ease;
}

.paso-embudo:hover {
    background-color: color-mix(in srgb, var(--tono) 7%, transparent);
}

.paso-embudo:hover .relleno-embudo {
    background-color: var(--tono);
}

.riel-embudo {
    height: 0.5rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--color-borde) 55%, transparent);
    overflow: hidden;
}

/*
 * El relleno crece con `scaleX` y no con `width`.
 *
 * Animar el ancho recalcula el diseño de la tarjeta en cada cuadro y con cinco
 * barras a la vez se nota; `transform` lo resuelve la GPU sin tocar el diseño.
 * De ahí que el ancho final sea fijo —`var(--parte)`— y lo que se anime sea la
 * escala, anclada a la izquierda para que salga del origen del riel.
 */
.relleno-embudo {
    display: block;
    width: var(--parte);
    height: 100%;
    border-radius: 999px;
    background-color: var(--color-paso);
    transform-origin: left center;
    animation: llenar-embudo 0.55s cubic-bezier(0.16, 1, 0.3, 1) both;
    animation-delay: calc(var(--paso) * 70ms);
    transition: background-color 0.2s ease;
}

@keyframes llenar-embudo {
    from {
        transform: scaleX(0);
    }
    to {
        transform: scaleX(1);
    }
}

@media (prefers-reduced-motion: reduce) {
    .relleno-embudo {
        animation: none;
    }
}

/*
 * Todo lo que va después del encabezado crece.
 *
 * `> :not(:first-child)` en vez de una clase en cada bloque: las cuatro formas
 * de tarjeta —métrica, lista, barras, atajos— ponen su contenido como hermanos
 * del encabezado, y así ninguna tiene que acordarse de nada.
 */
.tarjeta-panel > :not(:first-child) {
    flex: 1 1 auto;
}

/* La métrica centra su número en el espacio que le quede. */
.tarjeta-panel > p:not(:first-child) {
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.tarjeta-panel:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px -8px color-mix(in srgb, var(--tono) 45%, transparent);
}

/*
 * La tarjeta de un solo número: el color va en lo que se mira, no en el fondo.
 *
 * ── Por qué se fue el fondo teñido ─────────────────────────────────────────
 * Un fondo de color, por flojo que sea, convierte a esa tarjeta en otra clase
 * de objeto: en una cuadrícula de cinco, la teñida se lee como si fuera de otro
 * sistema. El aviso se dice igual de bien poniendo el tono en el adorno, el
 * icono, la cifra y el borde —que es donde el ojo ya lo busca— y la tarjeta
 * conserva la misma superficie que sus vecinas.
 *
 * Sigue cambiando de color con el estado: `tonoTarjeta` pasa el rojo cuando la
 * cartera trae vencido, y ese rojo llega ahora a la cifra y al borde en vez de
 * a un rectángulo entero.
 *
 * ── Por qué se mezcla contra los tokens del tema y no contra blanco ────────
 * Hay temas de superficie oscura («Medianoche» trae #1E293B). Mezclando el
 * texto contra `--color-contenido`, el resultado se oscurece sobre los temas
 * claros y se aclara sobre los oscuros, sin escribir dos versiones. Con blanco
 * fijo, «Medianoche» se rompería.
 *
 * ── Los números ────────────────────────────────────────────────────────────
 * Sin el tinte, el fondo vuelve a ser la superficie del tema, así que todo
 * contrasta MÁS que antes: el peor caso del icono, el enlace y la cifra pasa de
 * 5.15:1 a 5.53:1 —rojo alerta sobre «Medianoche»— y sobre los temas claros no
 * baja de 6.83. El pie recupera el `--color-suave` del tema, que ya está medido
 * contra esa superficie —4.99 en «Océano», 5.71 en «Medianoche»—; era el tinte
 * lo que lo dejaba en 4.01 y obligaba a reconstruirlo.
 */
.tarjeta-destacada {
    /* «El color de la tarjeta» es el tono ensombrecido: lo heredan el icono, el
       trazo del SVG y el enlace «Ver» sin tocar el marcado, y es también el
       color de la cifra. */
    --color-tarjeta: color-mix(in srgb, var(--tono) 55%, var(--color-contenido));

    /* Los tres lados que no son el acento superior, teñidos. Es lo que hace que
       la tarjeta se lea como avisada sin cambiar de superficie. */
    border-color: color-mix(in srgb, var(--tono) 32%, var(--color-superficie));

    /* Y el acento de arriba se queda a tono pleno, como en todas las demás:
       `border-color` es atajo de los cuatro lados y se comía el de
       `.tarjeta-panel`, que va antes en el archivo. */
    border-top-color: var(--tono);
}

.tarjeta-destacada:hover {
    box-shadow: 0 12px 24px -8px color-mix(in srgb, var(--tono) 55%, transparent);
}
</style>
