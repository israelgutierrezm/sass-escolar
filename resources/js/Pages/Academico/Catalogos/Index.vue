<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';

interface Item {
    id: number;
    clave: string;
    nombre: string;
    en_uso: boolean;
}

interface Catalogo {
    clave: string;
    etiqueta: string;
    singular: string;
    grupo: string;
    items: Item[];
}

interface CatalogoGlobal {
    etiqueta: string;
    descripcion: string;
    items: { clave: string; nombre: string }[];
}

const props = defineProps<{
    catalogos: Catalogo[];
    globales: CatalogoGlobal[];
    puedeEditar: boolean;
}>();

// Los globales se muestran plegados: son 33 filas cada uno y casi nunca se
// consultan, pero deben poder verse sin salir de la pantalla.
const globalAbierto = ref<string | null>(null);

// Los catálogos se muestran agrupados por dónde se usan (Asignaturas, Plan de
// estudios, Carreras), que es como el cliente los pensó.
const grupos = computed(() => {
    const mapa = new Map<string, Catalogo[]>();

    for (const catalogo of props.catalogos) {
        if (!mapa.has(catalogo.grupo)) {
            mapa.set(catalogo.grupo, []);
        }

        mapa.get(catalogo.grupo)!.push(catalogo);
    }

    return Array.from(mapa, ([grupo, catalogos]) => ({ grupo, catalogos }));
});

// Un borrador de alta por catálogo (clave + nombre), y cuál se está editando.
const nuevos = reactive<Record<string, { clave: string; nombre: string }>>(
    Object.fromEntries(props.catalogos.map((c) => [c.clave, { clave: '', nombre: '' }])),
);

const editando = ref<{ catalogo: string; id: number } | null>(null);
const edicion = reactive({ clave: '', nombre: '' });

function agregar(catalogo: string): void {
    const borrador = nuevos[catalogo];

    if (!borrador.clave.trim() || !borrador.nombre.trim()) {
        return;
    }

    router.post(`/academico/catalogos/${catalogo}`, borrador, {
        preserveScroll: true,
        onSuccess: () => {
            borrador.clave = '';
            borrador.nombre = '';
        },
    });
}

function abrirEdicion(catalogo: string, item: Item): void {
    editando.value = { catalogo, id: item.id };
    edicion.clave = item.clave;
    edicion.nombre = item.nombre;
}

function guardarEdicion(): void {
    if (!editando.value) {
        return;
    }

    router.put(`/academico/catalogos/${editando.value.catalogo}/${editando.value.id}`, { ...edicion }, {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    });
}

function eliminar(catalogo: string, item: Item): void {
    if (!confirm(`¿Eliminar "${item.nombre}"?`)) {
        return;
    }

    router.delete(`/academico/catalogos/${catalogo}/${item.id}`, { preserveScroll: true });
}

function esEditando(catalogo: string, id: number): boolean {
    return editando.value?.catalogo === catalogo && editando.value?.id === id;
}
</script>

<template>
    <Head title="Catálogos" />

    <AppLayout titulo="Configuración de catálogos">
        <NavAcademico />

        <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
            Los catálogos que alimentan los formularios de Académico. Cada uno lleva una clave y una
            descripción. Lo que está en uso no se puede eliminar.
        </p>

        <div v-for="({ grupo, catalogos: lista }) in grupos" :key="grupo" class="space-y-4">
            <!-- Divisor de grupo: barra de acento + título + línea, para que
                 separe con claridad los catálogos de cada sección. -->
            <div class="flex items-center gap-3">
                <span class="h-5 w-1.5 rounded-full" :style="{ backgroundColor: 'var(--color-acento)' }" />
                <h2 class="text-base font-bold">{{ grupo }}</h2>
                <span class="h-px flex-1" :style="{ backgroundColor: 'var(--color-borde)' }" />
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <section v-for="catalogo in lista" :key="catalogo.clave" class="tarjeta p-5">
                    <h3 class="text-base font-semibold">{{ catalogo.etiqueta }}</h3>

                    <ul class="mt-3 divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                        <li
                            v-for="item in catalogo.items"
                            :key="item.id"
                            class="flex items-center gap-2 py-2"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <template v-if="esEditando(catalogo.clave, item.id)">
                                <input
                                    v-model="edicion.clave"
                                    class="w-28 rounded border px-2 py-1 font-mono text-xs"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                />
                                <input
                                    v-model="edicion.nombre"
                                    class="flex-1 rounded border px-2 py-1 text-sm"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    @keyup.enter="guardarEdicion"
                                />
                                <button type="button" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }" @click="guardarEdicion">
                                    Guardar
                                </button>
                                <button type="button" class="text-sm" :style="{ color: 'var(--color-suave)' }" @click="editando = null">
                                    Cancelar
                                </button>
                            </template>

                            <template v-else>
                                <span class="w-28 shrink-0 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ item.clave }}
                                </span>
                                <span class="flex-1">{{ item.nombre }}</span>
                                <span
                                    v-if="item.en_uso"
                                    class="rounded-full px-2 py-0.5 text-[11px]"
                                    :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                                    title="Está en uso; no se puede eliminar"
                                >
                                    en uso
                                </span>
                                <template v-if="puedeEditar">
                                    <button type="button" class="text-sm" :style="{ color: 'var(--color-acento)' }" @click="abrirEdicion(catalogo.clave, item)">
                                        Editar
                                    </button>
                                    <button
                                        type="button"
                                        class="text-sm disabled:opacity-40"
                                        :style="{ color: 'var(--color-suave)' }"
                                        :disabled="item.en_uso"
                                        @click="eliminar(catalogo.clave, item)"
                                    >
                                        Eliminar
                                    </button>
                                </template>
                            </template>
                        </li>

                        <li v-if="!catalogo.items.length" class="py-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Sin registros todavía.
                        </li>
                    </ul>

                    <form v-if="puedeEditar" class="mt-3 flex items-center gap-2" @submit.prevent="agregar(catalogo.clave)">
                        <input
                            v-model="nuevos[catalogo.clave].clave"
                            placeholder="clave"
                            class="w-28 rounded border px-2 py-1.5 font-mono text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <input
                            v-model="nuevos[catalogo.clave].nombre"
                            :placeholder="`Nueva ${catalogo.singular}`"
                            class="flex-1 rounded border px-2 py-1.5 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        />
                        <button
                            type="submit"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        >
                            Agregar
                        </button>
                    </form>
                </section>
            </div>
        </div>

        <!-- Los catálogos globales (Entidad / Identidad Federativa) no se editan
             aquí: son compartidos entre escuelas y los administra el dueño del
             sistema. Mismo divisor que los demás grupos para que se lea igual. -->
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <span class="h-5 w-1.5 rounded-full" :style="{ backgroundColor: 'var(--color-acento)' }" />
                <h2 class="text-base font-bold">General</h2>
                <span class="h-px flex-1" :style="{ backgroundColor: 'var(--color-borde)' }" />
            </div>

            <section class="tarjeta p-5">
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    <strong>Entidad</strong> e <strong>Identidad Federativa</strong> son catálogos globales,
                    compartidos entre todas las escuelas.
                </p>

                <div class="mt-4 space-y-2">
                <div v-for="cat in globales" :key="cat.etiqueta" class="rounded-lg border" :style="{ borderColor: 'var(--color-borde)' }">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left"
                        @click="globalAbierto = globalAbierto === cat.etiqueta ? null : cat.etiqueta"
                    >
                        <span>
                            <span class="text-sm font-medium">{{ cat.etiqueta }}</span>
                            <span class="ml-2 rounded-full px-2 py-0.5 text-[11px]" :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                                solo lectura
                            </span>
                            <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ cat.descripcion }}</span>
                        </span>
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ globalAbierto === cat.etiqueta ? 'Ocultar' : `Ver ${cat.items.length}` }}
                        </span>
                    </button>

                    <div v-if="globalAbierto === cat.etiqueta" class="grid gap-x-6 gap-y-1 border-t p-4 sm:grid-cols-2 lg:grid-cols-3" :style="{ borderColor: 'var(--color-borde)' }">
                        <div v-for="item in cat.items" :key="item.clave" class="flex items-baseline gap-2 text-sm">
                            <span class="w-8 shrink-0 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ item.clave }}</span>
                            <span>{{ item.nombre }}</span>
                        </div>
                    </div>
                </div>
            </div>
            </section>
        </div>
    </AppLayout>
</template>
