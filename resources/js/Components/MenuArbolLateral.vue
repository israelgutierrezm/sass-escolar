<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { NodoNav } from '@/menu/construir';

/**
 * Sub-árbol del menú lateral (niveles 2 y 3), recursivo. Una HOJA es un enlace;
 * un SUBGRUPO es un encabezado plegable que contiene más hoja(s) o subgrupo(s).
 * El estado de abierto/cerrado se comparte por clave con la barra (`abiertos`).
 */
const props = defineProps<{
    nodos: NodoNav[];
    abiertos: Record<string, boolean>;
    esActiva: (prefijo: string) => boolean;
}>();

const emit = defineEmits<{ alternar: [clave: string] }>();

/**
 * Un subgrupo está activo si la ruta cae en ÉL o en cualquiera de sus hijos.
 *
 * Comparar sólo contra su prefijo alcanzaba mientras cada subgrupo tuviera una
 * única raíz de URL. Dejó de alcanzar con «Académico → Configuración», que junta
 * `/academico/ofertas`, `/academico/plantillas` y `/academico/catalogos`:
 * estando en cualquiera de las dos últimas el subgrupo se abría —eso lo resuelve
 * `prefijosActivos`— pero no se pintaba como activo.
 *
 * Es la misma corrección que ya lleva la barra para las secciones de nivel 1.
 */
function nodoActivo(nodo: NodoNav): boolean {
    if (!nodo.esGrupo) {
        return props.esActiva(nodo.url!);
    }

    return props.esActiva(nodo.prefijo) || nodo.hijos.some((h) => nodoActivo(h));
}
</script>

<template>
    <div class="space-y-0.5">
        <template v-for="nodo in nodos" :key="nodo.clave">
            <!-- Hoja -->
            <Link
                v-if="!nodo.esGrupo"
                :href="nodo.url!"
                class="relative flex items-center rounded-lg py-2 pl-5 pr-3 text-[13px] transition-all duration-200"
                :class="esActiva(nodo.url!) ? 'font-medium text-white' : 'opacity-80 hover:bg-superficie/5 hover:opacity-100'"
            >
                <span
                    class="absolute left-0 h-1.5 w-1.5 rounded-full transition-all duration-200"
                    :style="{
                        backgroundColor: esActiva(nodo.url!) ? 'var(--color-barra-lateral-activo)' : 'currentColor',
                        opacity: esActiva(nodo.url!) ? 1 : 0.4,
                    }"
                />
                {{ nodo.etiqueta }}
            </Link>

            <!-- Subgrupo plegable -->
            <div v-else>
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-lg py-2 pl-3 pr-3 text-[13px] transition-all duration-200"
                    :class="nodoActivo(nodo) ? 'text-white' : 'opacity-80 hover:bg-superficie/5 hover:opacity-100'"
                    @click="emit('alternar', nodo.clave)"
                >
                    <svg
                        class="h-3.5 w-3.5 shrink-0 transition-transform duration-300"
                        :class="abiertos[nodo.clave] ? 'rotate-90' : ''"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="2"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                    </svg>
                    <span class="flex-1 truncate text-left font-medium">{{ nodo.etiqueta }}</span>
                </button>

                <Transition
                    enter-active-class="transition-all duration-300 ease-out overflow-hidden"
                    enter-from-class="max-h-0 opacity-0"
                    enter-to-class="max-h-96 opacity-100"
                    leave-active-class="transition-all duration-200 ease-in overflow-hidden"
                    leave-from-class="max-h-96 opacity-100"
                    leave-to-class="max-h-0 opacity-0"
                >
                    <MenuArbolLateral
                        v-if="abiertos[nodo.clave]"
                        :nodos="nodo.hijos"
                        :abiertos="abiertos"
                        :es-activa="esActiva"
                        class="ml-3 mt-0.5 border-l border-white/10 pl-2"
                        @alternar="emit('alternar', $event)"
                    />
                </Transition>
            </div>
        </template>
    </div>
</template>
