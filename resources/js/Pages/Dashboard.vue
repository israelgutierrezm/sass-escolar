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
    embudo: '#7C3AED',
    'por-contactar': '#DB2777',
    'comisiones-por-pagar': '#D97706',
    'actividad-por-hora': '#0891B2',
    'mi-avance': '#2563EB',
    'mi-saldo': '#059669',
    'mis-materias': '#4F46E5',
    accesos: '#475569',
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
            <section v-if="props.tarjetas.length" class="grid min-w-0 grid-flow-dense gap-4 sm:grid-cols-4">
            <!--
                Sólo `--tono` viaja en línea; `--color-tarjeta` lo pone el CSS.

                Es a propósito: dentro de una destacada, `--color-tarjeta` pasa a
                ser BLANCO, y así el icono, el trazo del SVG y el enlace «Ver»
                —que ya lo usaban— salen legibles sobre el bloque de color sin
                duplicar una sola línea de marcado. Si `--color-tarjeta` se
                pusiera en línea le ganaría a esa regla y no habría manera.
            -->
            <div
                v-for="(tarjeta, i) in props.tarjetas"
                :key="tarjeta.clave"
                class="tarjeta tarjeta-panel animar-entrada p-5"
                :class="{
                    'sm:col-span-1': tarjeta.ancho === 1,
                    'sm:col-span-2': tarjeta.ancho === 2,
                    'sm:col-span-3': tarjeta.ancho === 3,
                    'sm:col-span-4': tarjeta.ancho === 4,
                    'tarjeta-destacada': esDestacada(tarjeta),
                }"
                :style="{ '--tono': tonoTarjeta(tarjeta), animationDelay: `${i * 45}ms` }"
            >
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

                    El rojo de la alerta sólo se usa sobre fondo claro. En la
                    destacada la tarjeta ENTERA ya es roja —ver `tonoTarjeta`—,
                    así que el número va en blanco como los demás: un rojo sobre
                    rojo no se leería, y el aviso está dado por el bloque.
                -->
                <template v-if="tarjeta.tipo === 'metrica'">
                    <p
                        class="mt-3 text-3xl font-semibold tracking-tight tabular-nums"
                        :class="tarjeta.datos.alerta && !esDestacada(tarjeta) ? 'text-red-600' : ''"
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
 * La tarjeta de un solo número, vestida de su color.
 *
 * ── Por qué el degradado arranca oscurecido ────────────────────────────────
 * Sobre el color a tope el blanco no contrasta lo suficiente: medido, el ámbar
 * de las comisiones daba 3.19:1 y el verde de la cartera 3.77, por debajo del
 * 4.5:1 que necesita el texto chico para ser legible.
 *
 * El 80% mezclado con negro deja el PEOR tono de la paleta en 4.73:1 —el ámbar
 * otra vez, que manda— y de ahí para arriba todos los demás. El 85% se quedaba
 * en 4.28 y el 82% justo en 4.52; se toma el 80 para tener margen si algún día
 * se agrega un color claro a la paleta. La diferencia no se nota a la vista;
 * el texto ilegible sí.
 */
.tarjeta-destacada {
    /* Dentro del bloque, «el color de la tarjeta» es el blanco: lo heredan el
       icono, el trazo del SVG y el enlace «Ver» sin tocar el marcado. */
    --color-tarjeta: #ffffff;
    /* Y lo «suave» pasa a ser un blanco apagado, para el pie de la cifra. */
    --color-suave: rgb(255 255 255 / 0.78);

    background: linear-gradient(
        145deg,
        color-mix(in srgb, var(--tono) 80%, #000),
        color-mix(in srgb, var(--tono) 52%, #000)
    );
    border-color: transparent;
    color: #ffffff;
    position: relative;
    overflow: hidden;
}

/*
 * El círculo de luz de la esquina. Puramente decorativo —de ahí que no sea un
 * elemento— y recortado por el `overflow` de la tarjeta.
 */
.tarjeta-destacada::after {
    content: "";
    position: absolute;
    right: -3.5rem;
    top: -3.5rem;
    width: 11rem;
    height: 11rem;
    border-radius: 999px;
    background: rgb(255 255 255 / 0.09);
    pointer-events: none;
}

/* Por encima del círculo, que va en absoluto. */
.tarjeta-destacada > * {
    position: relative;
    z-index: 1;
}

.tarjeta-destacada:hover {
    box-shadow: 0 14px 28px -10px color-mix(in srgb, var(--tono) 75%, transparent);
}
</style>
