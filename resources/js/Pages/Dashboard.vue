<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CieloDecorado from '@/Components/CieloDecorado.vue';
import ClimaEnCabecera from '@/Components/ClimaEnCabecera.vue';
import AgendaLateral from '@/Components/AgendaLateral.vue';
import { usaClima } from '@/utils/clima';
import type { PropsCompartidas } from '@/tipos';

interface Tarjeta {
    clave: string;
    titulo: string;
    tipo: 'metrica' | 'lista' | 'barras' | 'columnas' | 'accesos';
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

const mostrarRoles = ref(false);

const saludo = computed(() => {
    const h = new Date().getHours();
    return h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches';
});

const { clima, esDeNoche } = usaClima();

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
                        Operas como <strong>{{ usuario.rol_activo.nombre }}</strong>
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
                        <ClimaEnCabecera :clima="clima" />
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
                    <span class="mt-0.5 block text-xs opacity-80">
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
            `items-start`: sin él, la fila del grid estira TODAS las tarjetas al
            alto de la más grande, y una métrica de un solo número quedaba de
            246px con el 60% en blanco. Un panel se lee mejor denso y algo
            irregular que alineado y vacío.
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
            <section v-if="props.tarjetas.length" class="grid min-w-0 grid-flow-dense items-start gap-4 sm:grid-cols-4">
            <div
                v-for="(tarjeta, i) in props.tarjetas"
                :key="tarjeta.clave"
                class="tarjeta tarjeta-panel animar-entrada p-5"
                :class="{
                    'sm:col-span-1': tarjeta.ancho === 1,
                    'sm:col-span-2': tarjeta.ancho === 2,
                    'sm:col-span-3': tarjeta.ancho === 3,
                    'sm:col-span-4': tarjeta.ancho === 4,
                }"
                :style="{ '--color-tarjeta': colorTarjeta(tarjeta.clave), animationDelay: `${i * 45}ms` }"
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

                <!-- Métrica: un número grande y su contexto. -->
                <template v-if="tarjeta.tipo === 'metrica'">
                    <p
                        class="mt-3 text-3xl font-semibold tracking-tight tabular-nums"
                        :class="tarjeta.datos.alerta ? 'text-red-600' : ''"
                    >
                        {{ formatear(tarjeta.datos.valor, tarjeta.datos.formato) }}
                    </p>
                    <p
                        class="mt-0.5 text-xs"
                        :class="tarjeta.datos.alerta ? 'font-medium text-red-600' : ''"
                        :style="tarjeta.datos.alerta ? {} : { color: 'var(--color-suave)' }"
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

            <section v-else class="tarjeta min-w-0 px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Tu rol activo todavía no tiene nada que mostrar aquí. Las tarjetas del panel aparecen según
                los permisos que tenga.
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
    border-top: 3px solid var(--color-tarjeta);
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}

.tarjeta-panel:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px -8px color-mix(in srgb, var(--color-tarjeta) 45%, transparent);
}
</style>
