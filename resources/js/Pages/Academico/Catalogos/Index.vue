<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, reactive, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Item {
    id: number;
    clave: string;
    nombre: string;
    en_uso: boolean;
    protegido?: boolean;
    color?: string | null;
}

interface Extra {
    tipo: string;
    etiqueta: string;
}

interface Catalogo {
    clave: string;
    etiqueta: string;
    singular: string;
    grupo: string;
    extras: Record<string, Extra>;
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

// Un tono pastel aleatorio para sugerir color al crear un área: cada canal a
// medias con blanco, siempre claro y suave. El backend también lo genera si
// llega vacío, así que esto es sólo la sugerencia visible en el input.
function pastelAleatorio(): string {
    const c = () => Math.round((Math.random() * 255 + 255) / 2).toString(16).padStart(2, '0');
    return `#${c()}${c()}${c()}`;
}

function tieneColor(catalogo: Catalogo): boolean {
    return !!catalogo.extras?.color;
}

// Un borrador de alta por catálogo (clave + nombre + color cuando aplica), y
// cuál se está editando.
const nuevos = reactive<Record<string, { clave: string; nombre: string; color: string }>>(
    Object.fromEntries(props.catalogos.map((c) => [c.clave, { clave: '', nombre: '', color: pastelAleatorio() }])),
);

const editando = ref<{ catalogo: string; id: number } | null>(null);
const edicion = reactive({ clave: '', nombre: '', color: '#CCCCCC' });

// Qué catálogos tienen abierto su formulario de alta. Es por catálogo
// (independiente) para no llenar la pantalla de campos que casi nunca se usan:
// se abre solo el que se va a cargar, y se queda abierto tras agregar para
// encadenar varias altas seguidas sin volver a pulsar el botón.
const abiertos = reactive<Record<string, boolean>>({});

/** Enfoca el campo «clave» del catálogo, para teclear de inmediato. */
function enfocarClave(clave: string): void {
    nextTick(() => document.getElementById(`alta-${clave}`)?.focus());
}

function abrirAlta(clave: string): void {
    abiertos[clave] = true;
    enfocarClave(clave);
}

function cerrarAlta(clave: string): void {
    abiertos[clave] = false;
}

function agregar(catalogo: Catalogo): void {
    const borrador = nuevos[catalogo.clave];

    if (!borrador.clave.trim() || !borrador.nombre.trim()) {
        return;
    }

    const carga: Record<string, string> = { clave: borrador.clave, nombre: borrador.nombre };
    if (tieneColor(catalogo)) {
        carga.color = borrador.color;
    }

    router.post(`/academico/catalogos/${catalogo.clave}`, carga, {
        preserveScroll: true,
        onSuccess: () => {
            borrador.clave = '';
            borrador.nombre = '';
            borrador.color = pastelAleatorio();
            // Se queda abierto y con el foco en la clave: la siguiente alta se
            // teclea sin tocar el ratón.
            enfocarClave(catalogo.clave);
        },
    });
}

function abrirEdicion(catalogo: Catalogo, item: Item): void {
    editando.value = { catalogo: catalogo.clave, id: item.id };
    edicion.clave = item.clave;
    edicion.nombre = item.nombre;
    edicion.color = item.color || pastelAleatorio();
}

function guardarEdicion(catalogo: Catalogo): void {
    if (!editando.value) {
        return;
    }

    const carga: Record<string, string> = { clave: edicion.clave, nombre: edicion.nombre };
    if (tieneColor(catalogo)) {
        carga.color = edicion.color;
    }

    router.put(`/academico/catalogos/${editando.value.catalogo}/${editando.value.id}`, carga, {
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

                    <!-- Encabezado de columnas: da a la lista lectura de tabla
                         (clave | descripción | color) y evita que se lean como
                         un texto corrido pegado. -->
                    <div
                        class="mt-3 flex items-center gap-3 border-b pb-1.5 text-[11px] font-semibold uppercase tracking-wide"
                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                    >
                        <span class="w-24 shrink-0">Clave</span>
                        <span class="flex-1">Descripción</span>
                        <span v-if="tieneColor(catalogo)" class="w-12 shrink-0 text-center">Color</span>
                        <span v-if="puedeEditar" class="w-28 shrink-0 text-right">Acciones</span>
                    </div>

                    <ul class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                        <li
                            v-for="item in catalogo.items"
                            :key="item.id"
                            class="flex items-center gap-3 py-2"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <template v-if="esEditando(catalogo.clave, item.id)">
                                <input
                                    v-model="edicion.clave"
                                    class="w-24 shrink-0 rounded border px-2 py-1 font-mono text-xs"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                />
                                <input
                                    v-model="edicion.nombre"
                                    class="min-w-0 flex-1 rounded border px-2 py-1 text-sm"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    @keyup.enter="guardarEdicion(catalogo)"
                                />
                                <input
                                    v-if="tieneColor(catalogo)"
                                    v-model="edicion.color"
                                    type="color"
                                    class="h-8 w-12 shrink-0 cursor-pointer rounded border"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                />
                                <span class="flex w-28 shrink-0 items-center justify-end gap-1">
                                    <button type="button" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }" @click="guardarEdicion(catalogo)">
                                        Guardar
                                    </button>
                                    <BotonAccion variante="cerrar" @click="editando = null" />
                                </span>
                            </template>

                            <template v-else>
                                <span class="w-24 shrink-0 break-all font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ item.clave }}
                                </span>
                                <span class="flex min-w-0 flex-1 items-center gap-2">
                                    <span class="min-w-0 break-words">{{ item.nombre }}</span>
                                    <span
                                        v-if="item.en_uso"
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[11px]"
                                        :style="{ backgroundColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                                        title="Está en uso; no se puede eliminar"
                                    >
                                        en uso
                                    </span>
                                </span>
                                <span v-if="tieneColor(catalogo)" class="flex w-12 shrink-0 justify-center">
                                    <span
                                        class="h-5 w-5 rounded border"
                                        :style="{ backgroundColor: item.color || 'transparent', borderColor: 'var(--color-borde)' }"
                                        :title="item.color || 'Sin color'"
                                    />
                                </span>
                                <span v-if="puedeEditar" class="flex w-28 shrink-0 items-center justify-end gap-1">
                                    <!-- Los valores oficiales (niveles, tipos de
                                         periodo) no se editan ni se eliminan. -->
                                    <span
                                        v-if="item.protegido"
                                        class="rounded px-2 py-0.5 text-xs"
                                        :style="{ color: 'var(--color-suave)', backgroundColor: 'var(--color-fondo)' }"
                                        title="Valor oficial: no se puede modificar ni eliminar"
                                    >
                                        Oficial
                                    </span>
                                    <template v-else>
                                        <BotonAccion variante="editar" @click="abrirEdicion(catalogo, item)" />
                                        <BotonAccion
                                            variante="eliminar"
                                            :disabled="item.en_uso"
                                            @click="eliminar(catalogo.clave, item)"
                                        />
                                    </template>
                                </span>
                            </template>
                        </li>

                        <li v-if="!catalogo.items.length" class="py-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                            Sin registros todavía.
                        </li>
                    </ul>

                    <template v-if="puedeEditar">
                        <!-- Botón «Agregar» (ícono + texto): abre el form de alta
                             SOLO en este catálogo. Se queda abierto tras agregar. -->
                        <BotonAccion
                            v-if="!abiertos[catalogo.clave]"
                            variante="nuevo"
                            texto="Agregar"
                            fino
                            class="mt-3"
                            @click="abrirAlta(catalogo.clave)"
                        />

                        <form v-else class="mt-3 flex items-center gap-3" @submit.prevent="agregar(catalogo)">
                            <input
                                :id="`alta-${catalogo.clave}`"
                                v-model="nuevos[catalogo.clave].clave"
                                placeholder="clave"
                                class="w-24 shrink-0 rounded border px-2 py-1.5 font-mono text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            />
                            <input
                                v-model="nuevos[catalogo.clave].nombre"
                                :placeholder="`Nueva ${catalogo.singular}`"
                                class="min-w-0 flex-1 rounded border px-2 py-1.5 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @keyup.esc="cerrarAlta(catalogo.clave)"
                            />
                            <input
                                v-if="tieneColor(catalogo)"
                                v-model="nuevos[catalogo.clave].color"
                                type="color"
                                class="h-9 w-12 shrink-0 cursor-pointer rounded border"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                title="Color del área (se genera uno pastel si no lo cambias)"
                            />
                            <BotonPrincipal solo-icono icono="crear-circulo" texto="Agregar" class="shrink-0" />
                            <BotonAccion variante="cerrar" @click="cerrarAlta(catalogo.clave)" />
                        </form>
                    </template>
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
