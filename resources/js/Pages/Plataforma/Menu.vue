<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import draggable from 'vuedraggable';
import AppLayout from '@/Layouts/AppLayout.vue';
import MenuNodo from './MenuNodo.vue';
import { CATALOGO_MENU, indiceCatalogo } from '@/menu/catalogo';

interface Nodo {
    clave: string;
    etiqueta: string;
    esGrupo: boolean;
    hijos: Nodo[];
}

interface Rol {
    id: number;
    nombre: string;
    estructura: { clave: string; hijos: any[] }[] | null;
}

const props = defineProps<{ roles: Rol[] }>();

const rolId = ref<number | null>(props.roles[0]?.id ?? null);
const arbol = ref<Nodo[]>([]);
const guardando = ref(false);

const indice = indiceCatalogo();

// --- Construcción del árbol de trabajo ---
function nodoDesde(clave: string, hijos: any[] = []): Nodo | null {
    const base = indice[clave];
    if (!base) {
        return null; // clave que ya no existe en el catálogo: se descarta
    }
    const esGrupo = 'icono' in base;

    return {
        clave,
        etiqueta: base.etiqueta,
        esGrupo,
        hijos: (hijos ?? []).map((h) => nodoDesde(h.clave, h.hijos)).filter((n): n is Nodo => n !== null),
    };
}

function arbolPorDefecto(): Nodo[] {
    return CATALOGO_MENU.map((g) => ({
        clave: g.clave,
        etiqueta: g.etiqueta,
        esGrupo: true,
        hijos: g.hijos.map((h) => ({ clave: h.clave, etiqueta: h.etiqueta, esGrupo: false, hijos: [] })),
    }));
}

function recorrer(nodos: Nodo[], set: Set<string>): void {
    for (const n of nodos) {
        set.add(n.clave);
        recorrer(n.hijos, set);
    }
}

function buscar(nodos: Nodo[], clave: string): Nodo | null {
    for (const n of nodos) {
        if (n.clave === clave) {
            return n;
        }
        const enHijo = buscar(n.hijos, clave);
        if (enHijo) {
            return enHijo;
        }
    }
    return null;
}

// Agrega al final los grupos/opciones del catálogo que no estén en el árbol
// guardado: así una opción nueva (p. ej. "Menú") aparece aunque el rol ya tenga
// un menú viejo guardado.
function fusionarFaltantes(base: Nodo[]): Nodo[] {
    const presentes = new Set<string>();
    recorrer(base, presentes);

    for (const g of CATALOGO_MENU) {
        let grupo = buscar(base, g.clave);
        if (!grupo) {
            grupo = { clave: g.clave, etiqueta: g.etiqueta, esGrupo: true, hijos: [] };
            base.push(grupo);
            presentes.add(g.clave);
        }
        for (const h of g.hijos) {
            if (!presentes.has(h.clave)) {
                grupo.hijos.push({ clave: h.clave, etiqueta: h.etiqueta, esGrupo: false, hijos: [] });
                presentes.add(h.clave);
            }
        }
    }
    return base;
}

function cargarArbol(): void {
    const rol = props.roles.find((r) => r.id === rolId.value);
    if (!rol) {
        arbol.value = [];
        return;
    }
    if (!rol.estructura) {
        arbol.value = arbolPorDefecto();
        return;
    }
    const base = rol.estructura.map((n) => nodoDesde(n.clave, n.hijos)).filter((n): n is Nodo => n !== null);
    arbol.value = fusionarFaltantes(base);
}

watch(rolId, cargarArbol, { immediate: true });

// --- Guardar / restablecer ---
function aEstructura(nodos: Nodo[]): { clave: string; hijos: any[] }[] {
    return nodos.map((n) => ({ clave: n.clave, hijos: aEstructura(n.hijos) }));
}

function guardar(): void {
    if (rolId.value === null) {
        return;
    }
    guardando.value = true;
    router.put(`/plataforma/menu/${rolId.value}`, { estructura: aEstructura(arbol.value) }, {
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
            // Sin fila guardada, vuelve al default.
            const rol = props.roles.find((r) => r.id === rolId.value);
            if (rol) {
                rol.estructura = null;
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
                        <strong>Control escolar</strong>. Ordenar no da acceso: cada quien sigue viendo solo lo
                        que su rol y permisos permiten.
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

        <div class="tarjeta p-6">
            <div class="mx-auto max-w-2xl">
                <draggable
                    :list="arbol"
                    group="menu-arrastrable"
                    item-key="clave"
                    :animation="150"
                    handle=".asa"
                    ghost-class="fantasma-arrastre"
                    class="flex flex-col gap-2"
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

                <div class="mt-6 flex items-center gap-3 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }">
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
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.fantasma-arrastre {
    opacity: 0.4;
}
</style>
