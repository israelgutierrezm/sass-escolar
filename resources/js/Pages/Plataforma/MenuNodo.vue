<script setup lang="ts">
import draggable from 'vuedraggable';

/**
 * Nodo del editor de menú, recursivo. Cada nodo se puede arrastrar por su asa;
 * los GRUPOS tienen una zona donde soltar hijos (otro grupo u opciones), hasta
 * el nivel 3. Las opciones (hoja) no reciben hijos: navegan, no agrupan.
 */
interface Nodo {
    clave: string;
    etiqueta: string;
    esGrupo: boolean;
    hijos: Nodo[];
}

defineProps<{ nodos: Nodo[]; nivel: number }>();
</script>

<template>
    <draggable
        :list="nodos"
        group="menu-arrastrable"
        item-key="clave"
        :animation="150"
        handle=".asa"
        ghost-class="fantasma-arrastre"
        class="flex min-h-[0.75rem] flex-col gap-1.5"
    >
        <template #item="{ element }">
            <div class="rounded-lg border" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }">
                <div class="flex items-center gap-2 px-2 py-1.5">
                    <span class="asa cursor-grab select-none text-base leading-none" :style="{ color: 'var(--color-suave)' }" title="Arrastrar">⠿</span>
                    <span :class="element.esGrupo ? 'font-semibold' : ''" class="text-sm">{{ element.etiqueta }}</span>
                    <span
                        class="ml-auto rounded-full px-2 py-0.5 text-[10px] uppercase tracking-wide"
                        :style="element.esGrupo
                            ? { backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }
                            : { color: 'var(--color-suave)' }"
                    >
                        {{ element.esGrupo ? 'Grupo' : 'Opción' }}
                    </span>
                </div>

                <!-- Zona de anidamiento: solo los grupos reciben hijos, hasta nivel 3. -->
                <div
                    v-if="element.esGrupo && nivel < 3"
                    class="ml-4 border-l-2 py-1 pl-2 pr-1"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <MenuNodo :nodos="element.hijos" :nivel="nivel + 1" />
                    <p v-if="!element.hijos.length" class="px-2 py-1 text-xs italic" :style="{ color: 'var(--color-suave)' }">
                        Suelta aquí para anidar…
                    </p>
                </div>
            </div>
        </template>
    </draggable>
</template>

<style scoped>
.fantasma-arrastre {
    opacity: 0.4;
}
</style>
