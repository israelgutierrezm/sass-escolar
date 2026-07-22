<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { PropsCompartidas } from '@/tipos';

interface JerarquiaRol {
    faceta: string;
    heredados: string[];
    propios: string[];
}

interface Tarjeta {
    clave: string;
    titulo: string;
    tipo: 'metrica' | 'lista' | 'barras' | 'columnas' | 'accesos';
    ancho: number;
    icono: string;
    datos: Record<string, any>;
}

const props = defineProps<{
    tarjetas: Tarjeta[];
    jerarquiaRol: JerarquiaRol | null;
    campusDelRol: number[];
}>();

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
        <section v-if="props.tarjetas.length" class="grid items-start gap-4 sm:grid-cols-4">
            <div
                v-for="tarjeta in props.tarjetas"
                :key="tarjeta.clave"
                class="tarjeta p-5"
                :class="{
                    'sm:col-span-1': tarjeta.ancho === 1,
                    'sm:col-span-2': tarjeta.ancho === 2,
                    'sm:col-span-3': tarjeta.ancho === 3,
                    'sm:col-span-4': tarjeta.ancho === 4,
                }"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2.5">
                        <!--
                            El icono lo declara la tarjeta, no la pantalla: quien
                            agregue una nueva no debería editar este archivo para
                            que se vea como las demás.
                        -->
                        <span
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)' }"
                        >
                            <svg
                                class="h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="1.7"
                                :stroke="'var(--color-acento)'"
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
                        :style="{ color: 'var(--color-acento)' }"
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
                    Accesos: mosaico con icono. Antes eran rectángulos con solo
                    texto y había que leer los once para encontrar uno — y estos
                    botones existen justamente para no tener que leer.
                -->
                <template v-else-if="tarjeta.tipo === 'accesos'">
                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                        <a
                            v-for="acceso in tarjeta.datos.accesos"
                            :key="acceso.enlace"
                            :href="acceso.enlace"
                            class="group flex flex-col items-center gap-2 rounded-xl border px-2 py-3 text-center transition hover:-translate-y-0.5"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-xl transition"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)' }"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" :stroke="'var(--color-acento)'">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="acceso.icono" />
                                </svg>
                            </span>
                            <span class="text-xs font-medium leading-tight">{{ acceso.etiqueta }}</span>
                        </a>
                    </div>
                </template>
            </div>
        </section>

        <section v-else class="tarjeta px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            Tu rol activo todavía no tiene nada que mostrar aquí. Las tarjetas del panel aparecen según
            los permisos que tenga.
        </section>

        <!-- Conmutador de rol -->
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-semibold text-slate-800">Cambiar de rol</h2>
            <p class="mt-1 text-sm text-slate-500">
                Una misma persona puede tener varios roles. El rol activo define qué permisos aplican y
                qué información ves.
            </p>

            <div v-if="rolesDisponibles.length" class="mt-4 grid gap-3 sm:grid-cols-2">
                <button
                    v-for="rol in rolesDisponibles"
                    :key="`${rol.id}-${rol.campus_id ?? 'global'}`"
                    type="button"
                    class="rounded-lg border px-4 py-3 text-left transition"
                    :class="
                        esActivo(rol.id)
                            ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                            : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                    "
                    @click="conmutar(rol.id)"
                >
                    <span class="flex items-center justify-between">
                        <span class="font-medium text-slate-800">{{ rol.nombre }}</span>
                        <span
                            v-if="esActivo(rol.id)"
                            class="rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-medium text-white"
                        >
                            Activo
                        </span>
                    </span>
                    <span class="mt-1 block text-xs text-slate-500">
                        {{ rol.campus_nombre ? `Acotado a ${rol.campus_nombre}` : 'Alcance global' }}
                    </span>
                </button>
            </div>
            <p v-else class="mt-4 text-sm text-slate-500">
                No tienes roles activos asignados. Contacta a un administrador.
            </p>
        </section>

        <!-- Qué te concede el rol activo -->
        <section v-if="props.jerarquiaRol" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-base font-semibold text-slate-800">Permisos del rol activo</h2>
            <p class="mt-1 text-sm text-slate-500">
                Pertenece a la faceta
                <span class="font-medium text-slate-700">{{ props.jerarquiaRol.faceta }}</span
                >, de la que hereda permisos.
            </p>

            <div class="mt-4 grid gap-6 sm:grid-cols-2">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Heredados ({{ props.jerarquiaRol.heredados.length }})
                    </h3>
                    <ul v-if="props.jerarquiaRol.heredados.length" class="mt-2 space-y-1">
                        <li
                            v-for="permiso in props.jerarquiaRol.heredados"
                            :key="permiso"
                            class="text-sm text-slate-600"
                        >
                            {{ permiso }}
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-400">Ninguno</p>
                </div>

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Propios ({{ props.jerarquiaRol.propios.length }})
                    </h3>
                    <ul v-if="props.jerarquiaRol.propios.length" class="mt-2 space-y-1">
                        <li
                            v-for="permiso in props.jerarquiaRol.propios"
                            :key="permiso"
                            class="text-sm text-slate-600"
                        >
                            {{ permiso }}
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-slate-400">Ninguno</p>
                </div>
            </div>

            <div class="mt-6 border-t border-slate-100 pt-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Efectivos ({{ permisos.length }})
                </h3>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <span
                        v-for="permiso in permisos"
                        :key="permiso"
                        class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700"
                    >
                        {{ permiso }}
                    </span>
                </div>
                <p v-if="props.campusDelRol.length" class="mt-4 text-sm text-slate-500">
                    Este rol está acotado a {{ props.campusDelRol.length }} campus; fuera de ellos no aplica.
                </p>
            </div>
        </section>
    </AppLayout>
</template>
