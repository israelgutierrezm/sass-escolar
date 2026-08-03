<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaClima from '@/Components/TarjetaClima.vue';
import AgendaLateral from '@/Components/AgendaLateral.vue';
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
        <!-- Cabecera de bienvenida: saludo, quién eres y con qué rol operas. -->
        <section
            class="animar-entrada mb-4 overflow-hidden rounded-2xl p-6 text-white shadow-lg"
            :style="{ background: 'linear-gradient(120deg, var(--color-acento), color-mix(in srgb, var(--color-acento) 55%, #000))' }"
        >
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-sm opacity-80">{{ saludo }},</p>
                    <h1 class="truncate text-2xl font-bold">{{ usuario?.nombre_completo ?? usuario?.usuario }}</h1>
                    <p v-if="usuario?.rol_activo" class="mt-1 text-sm opacity-90">
                        Operas como <strong>{{ usuario.rol_activo.nombre }}</strong>
                    </p>
                </div>
                <button
                    v-if="rolesDisponibles.length > 1"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl bg-superficie/15 px-4 py-2.5 text-sm font-medium backdrop-blur transition hover:bg-superficie/25"
                    @click="mostrarRoles = !mostrarRoles"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" /></svg>
                    Cambiar de rol
                </button>
            </div>

            <!-- Conmutador de rol (se despliega desde el botón). -->
            <div v-if="mostrarRoles && rolesDisponibles.length" class="mt-5 grid gap-2 border-t border-white/20 pt-4 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="rol in rolesDisponibles"
                    :key="`${rol.id}-${rol.campus_id ?? 'global'}`"
                    type="button"
                    class="rounded-xl px-4 py-3 text-left text-sm transition"
                    :class="esActivo(rol.id) ? 'bg-superficie text-contenido shadow' : 'bg-superficie/10 hover:bg-superficie/20'"
                    @click="conmutar(rol.id)"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="font-medium">{{ rol.nombre }}</span>
                        <span v-if="esActivo(rol.id)" class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :style="{ backgroundColor: 'var(--color-acento)', color: '#fff' }">Activo</span>
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
            <section v-if="props.tarjetas.length" class="grid items-start gap-4 sm:grid-cols-4">
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

            <section v-else class="tarjeta px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Tu rol activo todavía no tiene nada que mostrar aquí. Las tarjetas del panel aparecen según
                los permisos que tenga.
            </section>

            <!-- El contexto: qué día es, qué viene, cómo está afuera. -->
            <aside class="space-y-4 xl:sticky xl:top-4">
                <AgendaLateral
                    :mes="agenda.mes"
                    :proximos="agenda.proximos"
                    :marcados="agenda.marcados"
                    :hoy="agenda.hoy"
                    :efemerides="agenda.efemerides"
                />
                <TarjetaClima />
            </aside>
        </div>
    </AppLayout>
</template>

<style scoped>
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
