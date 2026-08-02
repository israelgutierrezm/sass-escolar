<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { ICONOS } from '@/iconos';

/**
 * El índice del curso: todas las lecciones, agrupadas, con lo hecho palomeado.
 *
 * Es la pieza que convierte una lista de tareas en un CAMINO. Sin él, el alumno
 * sabe qué le falta pero no dónde está parado ni cuánto queda; con él, ve el
 * curso entero de un vistazo y puede saltar a cualquier punto.
 *
 * El estado de cada lección se dice de dos maneras a la vez —el círculo y el
 * texto de abajo—: el color solo dejaría fuera a quien no lo distingue.
 */
interface Leccion {
    id: number;
    numero: number;
    tipo: string;
    tipo_etiqueta: string;
    titulo: string;
    puntos: number;
    dias: number | null;
    completada: boolean;
    visitada: boolean;
    abierta: boolean;
    se_entrega: boolean;
}

interface Unidad {
    clave: number;
    nombre: string;
    lecciones: Leccion[];
    completadas: number;
    total: number;
}

const props = defineProps<{
    unidades: Unidad[];
    materiaId: number;
    activaId: number | null;
    progreso: { total: number; completadas: number; porcentaje: number; pendientes: number };
}>();

/*
 * Las unidades se pliegan, y arranca abierta la que contiene la lección en la
 * que se está. Un curso de cinco parciales con todo desplegado obliga a
 * desplazarse tres pantallas para encontrar dónde ibas.
 */
const abiertas = ref<Set<number>>(new Set());

function sincronizar(): void {
    const unidadActiva = props.unidades.find((u) => u.lecciones.some((l) => l.id === props.activaId));

    abiertas.value = new Set(unidadActiva ? [unidadActiva.clave] : props.unidades.slice(0, 1).map((u) => u.clave));
}

sincronizar();
watch(() => props.activaId, sincronizar);

function alternar(clave: number): void {
    const copia = new Set(abiertas.value);

    copia.has(clave) ? copia.delete(clave) : copia.add(clave);
    abiertas.value = copia;
}

/** Cada tipo con su ícono: se reconoce antes de leer la etiqueta. */
const iconoDe: Record<string, string> = {
    lectura: ICONOS.documentoTexto,
    actividad: ICONOS.tareaCheck,
    foro: ICONOS.burbujas,
    examen: ICONOS.escudo,
};

/** El anillo de progreso: la circunferencia de un radio 20. */
const perimetro = 2 * Math.PI * 20;

const avanceTrazo = computed(() => (props.progreso.porcentaje / 100) * perimetro);
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- Progreso: el número que uno viene a mirar cada vez que entra -->
        <header class="flex items-center gap-4 border-b border-borde px-5 py-4">
            <span class="relative shrink-0">
                <svg width="52" height="52" viewBox="0 0 48 48" class="-rotate-90">
                    <circle
                        cx="24" cy="24" r="20" fill="none" stroke-width="4"
                        :stroke="'color-mix(in srgb, var(--color-suave) 22%, transparent)'"
                    />
                    <circle
                        cx="24" cy="24" r="20" fill="none" stroke-width="4" stroke-linecap="round"
                        :stroke="'var(--color-acento)'"
                        :stroke-dasharray="`${avanceTrazo} ${perimetro}`"
                        class="transition-all duration-500"
                    />
                </svg>
                <span
                    class="absolute inset-0 grid place-items-center text-[11px] font-bold tabular-nums"
                    :style="{ color: 'var(--color-acento)' }"
                >
                    {{ progreso.porcentaje }}%
                </span>
            </span>

            <span class="min-w-0">
                <span class="block text-sm font-semibold text-contenido">Contenido del curso</span>
                <span class="block text-xs text-suave">
                    {{ progreso.completadas }} de {{ progreso.total }} completadas
                </span>
                <span v-if="progreso.pendientes" class="mt-0.5 block text-xs" :style="{ color: '#d97706' }">
                    Te faltan {{ progreso.pendientes }}
                </span>
            </span>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <section v-for="u in unidades" :key="u.clave" class="border-b border-borde last:border-b-0">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-5 py-3 text-left transition-colors hover:bg-[color-mix(in_srgb,var(--color-suave)_7%,transparent)]"
                    @click="alternar(u.clave)"
                >
                    <svg
                        class="h-3.5 w-3.5 shrink-0 text-suave transition-transform"
                        :class="abiertas.has(u.clave) ? 'rotate-90' : ''"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-contenido">{{ u.nombre }}</span>
                        <span class="block text-[11px] text-suave">{{ u.completadas }} / {{ u.total }} lecciones</span>
                    </span>
                    <!-- Unidad terminada: la palomita evita abrirla para comprobar -->
                    <svg
                        v-if="u.completadas === u.total"
                        class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none"
                        stroke="#16a34a" stroke-width="2"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONOS.checkCirculo" />
                    </svg>
                </button>

                <ul v-show="abiertas.has(u.clave)" class="pb-1">
                    <li v-for="l in u.lecciones" :key="l.id">
                        <Link
                            :href="`/mis-cursos/${materiaId}/aula/${l.id}`"
                            class="flex items-start gap-3 border-l-[3px] py-2.5 pl-4 pr-4 transition-colors"
                            :class="l.id === activaId
                                ? 'bg-[color-mix(in_srgb,var(--color-acento)_9%,transparent)]'
                                : 'hover:bg-[color-mix(in_srgb,var(--color-suave)_7%,transparent)]'"
                            :style="{ borderColor: l.id === activaId ? 'var(--color-acento)' : 'transparent' }"
                        >
                            <!-- Círculo de estado: hecho, empezado o sin tocar -->
                            <span
                                class="mt-0.5 grid h-[18px] w-[18px] shrink-0 place-items-center rounded-full border-2 text-[10px]"
                                :style="l.completada
                                    ? { backgroundColor: '#16a34a', borderColor: '#16a34a', color: '#fff' }
                                    : { borderColor: l.visitada ? 'var(--color-acento)' : 'color-mix(in srgb, var(--color-suave) 45%, transparent)' }"
                            >
                                <svg v-if="l.completada" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                                <span
                                    v-else-if="l.visitada"
                                    class="h-1.5 w-1.5 rounded-full"
                                    :style="{ backgroundColor: 'var(--color-acento)' }"
                                />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span
                                    class="block text-sm leading-snug"
                                    :class="l.id === activaId ? 'font-semibold text-contenido' : 'text-contenido'"
                                >
                                    {{ l.titulo }}
                                </span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-suave">
                                    <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" :d="iconoDe[l.tipo] ?? ICONOS.documento" />
                                    </svg>
                                    {{ l.tipo_etiqueta }}
                                    <span v-if="l.se_entrega">· {{ l.puntos }} pts</span>
                                    <span
                                        v-if="!l.completada && l.dias !== null && l.dias <= 3"
                                        class="font-semibold"
                                        :style="{ color: l.dias < 0 ? '#dc2626' : '#d97706' }"
                                    >
                                        · {{ l.dias < 0 ? 'vencida' : l.dias === 0 ? 'vence hoy' : l.dias === 1 ? 'vence mañana' : `${l.dias} días` }}
                                    </span>
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
