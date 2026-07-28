<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import draggable from 'vuedraggable';
import AppLayout from '@/Layouts/AppLayout.vue';
import MenuNodo from './MenuNodo.vue';
import { construirParaEditor, type NodoNav } from '@/menu/construir';

interface Rol {
    id: number;
    nombre: string;
    ambito: string;
    permisos: string[];
    estructura: { clave: string; hijos: any[] }[] | null;
    ocultos: string[];
}

const props = defineProps<{ roles: Rol[] }>();

const rolId = ref<number | null>(props.roles[0]?.id ?? null);
const arbol = ref<NodoNav[]>([]);
const ocultos = ref<NodoNav[]>([]);
const guardando = ref(false);

function cargarArbol(): void {
    const rol = props.roles.find((r) => r.id === rolId.value);
    if (!rol) {
        arbol.value = [];
        ocultos.value = [];
        return;
    }
    // El editor solo muestra lo que ESE rol podría ver (su ámbito y permisos);
    // los ocultos se separan en su propio cajón para poder devolverlos.
    const { visible, ocultos: bin } = construirParaEditor(rol.estructura ?? null, rol.ocultos ?? [], rol.permisos, rol.ambito);
    arbol.value = visible;
    ocultos.value = bin;
}

watch(rolId, cargarArbol, { immediate: true });

// --- Guardar / restablecer ---
function aEstructura(nodos: NodoNav[]): { clave: string; hijos: any[] }[] {
    return nodos.map((n) => ({ clave: n.clave, hijos: aEstructura(n.hijos) }));
}

function guardar(): void {
    if (rolId.value === null) {
        return;
    }
    guardando.value = true;
    router.put(`/plataforma/menu/${rolId.value}`, {
        estructura: aEstructura(arbol.value),
        ocultos: ocultos.value.map((n) => n.clave),
    }, {
        preserveScroll: true,
        onFinish: () => (guardando.value = false),
    });
}

function restablecer(): void {
    if (rolId.value === null || !confirm('¿Restablecer el menú de este rol al orden por defecto?')) {
        return;
    }
    router.delete(`/plataforma/menu/${rolId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            const rol = props.roles.find((r) => r.id === rolId.value);
            if (rol) {
                rol.estructura = null;
                rol.ocultos = [];
            }
            cargarArbol();
        },
    });
}
</script>

<template>
    <Head title="Menú por rol" />

    <AppLayout titulo="Menú por rol">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Ordena el menú de cada rol</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Arrastra los grupos y opciones para reordenarlos o anidarlos (hasta 3 niveles): p. ej.
                        sube <strong>Admisiones</strong>, o mete <strong>Docentes</strong> dentro de
                        <strong>Control escolar</strong>. Solo se muestra lo que ese rol puede ver. Para
                        <strong>ocultar</strong> algo, arrástralo a «Ocultos». Ordenar u ocultar no cambia
                        permisos, solo la barra.
                    </p>
                </div>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Rol</span>
                    <select
                        v-model.number="rolId"
                        class="w-56 rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                    </select>
                </label>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Árbol visible -->
            <div class="tarjeta p-6 lg:col-span-2">
                <h3 class="mb-3 text-sm font-semibold">Menú visible</h3>
                <draggable
                    :list="arbol"
                    group="menu-arrastrable"
                    item-key="clave"
                    :animation="150"
                    handle=".asa"
                    ghost-class="fantasma-arrastre"
                    class="flex min-h-[3rem] flex-col gap-2"
                >
                    <template #item="{ element }">
                        <div class="rounded-lg border" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)' }">
                            <div class="flex items-center gap-2 px-2 py-2">
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
                            <div
                                v-if="element.esGrupo"
                                class="ml-4 border-l-2 py-1 pl-2 pr-1"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                <MenuNodo :nodos="element.hijos" :nivel="2" />
                                <p v-if="!element.hijos.length" class="px-2 py-1 text-xs italic" :style="{ color: 'var(--color-suave)' }">
                                    Suelta aquí para anidar…
                                </p>
                            </div>
                        </div>
                    </template>
                </draggable>
            </div>

            <!-- Cajón de ocultos -->
            <div class="tarjeta p-6">
                <h3 class="mb-1 text-sm font-semibold">Ocultos</h3>
                <p class="mb-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Arrastra aquí lo que este rol no debe ver en la barra. Puedes devolverlo cuando quieras.
                </p>
                <draggable
                    :list="ocultos"
                    group="menu-arrastrable"
                    item-key="clave"
                    :animation="150"
                    handle=".asa"
                    ghost-class="fantasma-arrastre"
                    class="flex min-h-[6rem] flex-col gap-1.5 rounded-lg border border-dashed p-2"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <template #item="{ element }">
                        <div class="flex items-center gap-2 rounded-lg border px-2 py-1.5" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo)' }">
                            <span class="asa cursor-grab select-none text-base leading-none" :style="{ color: 'var(--color-suave)' }" title="Arrastrar">⠿</span>
                            <span class="text-sm" :style="{ color: 'var(--color-suave)' }">{{ element.etiqueta }}</span>
                            <span class="ml-auto text-[10px] uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">{{ element.esGrupo ? 'Grupo' : 'Opción' }}</span>
                        </div>
                    </template>
                </draggable>
                <p v-if="!ocultos.length" class="mt-2 text-center text-xs italic" :style="{ color: 'var(--color-suave)' }">
                    Nada oculto.
                </p>
            </div>
        </div>

        <div class="tarjeta flex items-center gap-3 p-4">
            <button
                type="button"
                :disabled="guardando"
                class="rounded-lg px-5 py-2.5 text-sm font-medium disabled:opacity-60"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="guardar"
            >
                {{ guardando ? 'Guardando…' : 'Guardar menú' }}
            </button>
            <button
                type="button"
                class="rounded-lg border px-4 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                @click="restablecer"
            >
                Restablecer al default
            </button>
        </div>
    </AppLayout>
</template>

<style scoped>
.fantasma-arrastre {
    opacity: 0.4;
}
</style>
