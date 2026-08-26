<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Area {
    id: number;
    clave: string;
    nombre: string;
    descripcion: string | null;
    orden: number;
    activo: boolean;
    cuantos: number;
}

interface Reporte {
    clave: string;
    titulo: string;
    tituloPropio: string | null;
    descripcion: string;
    areaClave: string;
    areaId: number | null;
    activo: boolean;
    movido: boolean;
}

const props = defineProps<{ areas: Area[]; reportes: Reporte[] }>();

const editando = ref<Area | null>(null);
const creando = ref(false);

const formArea = useForm({ nombre: '', descripcion: '' });

function abrirAlta(): void {
    editando.value = null;
    creando.value = true;
    formArea.reset();
    formArea.clearErrors();
}

function abrirEdicion(a: Area): void {
    editando.value = a;
    creando.value = true;
    formArea.clearErrors();
    formArea.nombre = a.nombre;
    formArea.descripcion = a.descripcion ?? '';
}

function guardarArea(): void {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            creando.value = false;
            editando.value = null;
            formArea.reset();
        },
    };

    if (editando.value) {
        formArea.put(`/reportes/configuracion/areas/${editando.value.id}`, opciones);
    } else {
        formArea.post('/reportes/configuracion/areas', opciones);
    }
}

function alternarArea(a: Area): void {
    router.patch(`/reportes/configuracion/areas/${a.id}/activo`, { activo: !a.activo }, { preserveScroll: true });
}

function eliminarArea(a: Area): void {
    if (!confirm(`¿Eliminar el área «${a.nombre}»?`)) return;

    router.delete(`/reportes/configuracion/areas/${a.id}`, { preserveScroll: true });
}

/** Mueve un reporte, lo renombra o lo apaga. Todo por la misma puerta. */
function ubicar(r: Reporte, cambios: Partial<{ areaId: number; nombre: string | null; activo: boolean }>): void {
    router.put(
        `/reportes/configuracion/reportes/${r.clave}`,
        {
            area_id: cambios.areaId ?? r.areaId,
            nombre: cambios.nombre !== undefined ? cambios.nombre : r.tituloPropio,
            activo: cambios.activo !== undefined ? cambios.activo : r.activo,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Organizar reportes" />

    <AppLayout titulo="Organizar reportes">
        <div class="mb-3">
            <Link href="/reportes" class="text-xs hover:underline" :style="{ color: 'var(--color-suave)' }">
                ← Todos los reportes
            </Link>
        </div>

        <!--
            Se dice con palabras, porque es justo lo que alguien podría suponer
            al arrastrar un reporte de finanzas a un área llamada «Dirección».
        -->
        <p class="mb-4 max-w-3xl rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Un área es una carpeta: mover un reporte de área NO cambia quién puede verlo. Eso lo sigue
            decidiendo el permiso de los datos que saca.
        </p>

        <div class="grid gap-4 xl:grid-cols-2">
            <TarjetaSeccion titulo="Áreas" sin-relleno>
                <template #insignia>
                    <button
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-xs font-medium"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="abrirAlta"
                    >Nueva área</button>
                </template>

                <div v-if="creando" class="space-y-3 border-t border-borde bg-slate-50 p-4">
                    <p class="text-sm font-medium">{{ editando ? `Editar «${editando.nombre}»` : 'Nueva área' }}</p>
                    <CampoTexto v-model="formArea.nombre" etiqueta="Nombre" :maximo="80" requerido :error="formArea.errors.nombre" />
                    <CampoTexto v-model="formArea.descripcion" etiqueta="Descripción" :maximo="255" :error="formArea.errors.descripcion" />
                    <p v-if="editando" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Su clave (<code>{{ editando.clave }}</code>) no cambia: hay reportes que declaran nacer en ella.
                    </p>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium disabled:opacity-60"
                            :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                            :disabled="formArea.processing"
                            @click="guardarArea"
                        >Guardar</button>
                        <button type="button" class="rounded-lg border border-borde px-3 py-1.5 text-sm" @click="creando = false">Cancelar</button>
                    </div>
                </div>

                <ul>
                    <li
                        v-for="a in areas"
                        :key="a.id"
                        class="flex flex-wrap items-center justify-between gap-2 border-t px-4 py-2.5 text-sm"
                        :style="{ borderColor: 'var(--color-borde)', opacity: a.activo ? 1 : 0.55 }"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ a.nombre }}
                                <span class="text-xs font-normal" :style="{ color: 'var(--color-suave)' }">
                                    · {{ a.cuantos }} {{ a.cuantos === 1 ? 'reporte' : 'reportes' }}
                                    <template v-if="!a.activo"> · apagada</template>
                                </span>
                            </p>
                            <p v-if="a.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ a.descripcion }}</p>
                        </div>

                        <div class="flex shrink-0 gap-1">
                            <button type="button" class="rounded border border-borde px-2 py-1 text-xs" @click="alternarArea(a)">
                                {{ a.activo ? 'Apagar' : 'Encender' }}
                            </button>
                            <button type="button" class="rounded border border-borde px-2 py-1 text-xs" @click="abrirEdicion(a)">Renombrar</button>
                            <!-- Sólo se borra un área vacía: con reportes dentro
                                 los dejaría sin sitio. -->
                            <button
                                v-if="a.cuantos === 0"
                                type="button"
                                class="rounded border border-borde px-2 py-1 text-xs text-red-600"
                                @click="eliminarArea(a)"
                            >Eliminar</button>
                        </div>
                    </li>
                </ul>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Dónde vive cada reporte" sin-relleno>
                <ul>
                    <li
                        v-for="r in reportes"
                        :key="r.clave"
                        class="border-t px-4 py-3 text-sm"
                        :style="{ borderColor: 'var(--color-borde)', opacity: r.activo ? 1 : 0.55 }"
                    >
                        <p class="font-medium">
                            {{ r.tituloPropio ?? r.titulo }}
                            <span v-if="r.tituloPropio" class="text-xs font-normal" :style="{ color: 'var(--color-suave)' }">
                                · en el código se llama «{{ r.titulo }}»
                            </span>
                        </p>

                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            <select
                                class="rounded-lg border border-borde px-2 py-1 text-xs"
                                :value="r.areaId ?? ''"
                                @change="ubicar(r, { areaId: Number(($event.target as HTMLSelectElement).value) })"
                            >
                                <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.nombre }}</option>
                            </select>

                            <input
                                type="text"
                                class="w-44 rounded-lg border border-borde px-2 py-1 text-xs"
                                placeholder="Renombrarlo aquí…"
                                :value="r.tituloPropio ?? ''"
                                @change="ubicar(r, { nombre: ($event.target as HTMLInputElement).value || null })"
                            />

                            <label class="flex items-center gap-1.5 text-xs">
                                <input
                                    type="checkbox"
                                    class="h-3.5 w-3.5 rounded border-borde"
                                    :checked="r.activo"
                                    @change="ubicar(r, { activo: ($event.target as HTMLInputElement).checked })"
                                />
                                Visible
                            </label>
                        </div>
                    </li>
                </ul>
            </TarjetaSeccion>
        </div>
    </AppLayout>
</template>
