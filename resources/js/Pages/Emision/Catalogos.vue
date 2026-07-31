<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonAccion from '@/Components/BotonAccion.vue';

interface Titulo {
    id: number;
    abreviatura: string;
    descripcion: string;
    en_uso: boolean;
}

const props = defineProps<{
    seccion: string;
    tituloSeccion: string;
    titulos: Titulo[];
}>();

const base = computed(() => `/${props.seccion}/configuracion/catalogos`);

const alta = useForm({ abreviatura: '', descripcion: '' });
const editando = ref<number | null>(null);
const edicion = useForm({ abreviatura: '', descripcion: '' });

function crear(): void {
    alta.post(base.value, { preserveScroll: true, onSuccess: () => alta.reset() });
}

function abrirEdicion(t: Titulo): void {
    editando.value = t.id;
    edicion.abreviatura = t.abreviatura;
    edicion.descripcion = t.descripcion;
}

function guardarEdicion(): void {
    if (editando.value === null) {
        return;
    }
    edicion.put(`${base.value}/${editando.value}`, { preserveScroll: true, onSuccess: () => (editando.value = null) });
}

function eliminar(t: Titulo): void {
    if (!confirm(`¿Eliminar el título "${t.abreviatura}"?`)) {
        return;
    }
    router.delete(`${base.value}/${t.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Catálogos · ${tituloSeccion}`" />

    <AppLayout :titulo="`${tituloSeccion} · Catálogos`">
        <section class="tarjeta mb-6 p-6">
            <h2 class="text-base font-semibold">Títulos profesionales</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Abreviatura y descripción del título del responsable (Ing./Ingeniero, Dr./Doctor…).
            </p>

            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="crear">
                <div class="w-32">
                    <CampoTexto v-model="alta.abreviatura" etiqueta="Abreviatura" requerido :error="alta.errors.abreviatura" />
                </div>
                <div class="min-w-[16rem] flex-1">
                    <CampoTexto v-model="alta.descripcion" etiqueta="Descripción" requerido :error="alta.errors.descripcion" />
                </div>
                <BotonPrincipal :procesando="alta.processing" texto="Agregar" icono="crear" />
            </form>
        </section>

        <section class="tarjeta overflow-hidden p-0">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                        <th class="px-6 py-3 font-semibold">Abreviatura</th>
                        <th class="px-4 py-3 font-semibold">Descripción</th>
                        <th class="px-6 py-3 text-right font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in titulos" :key="t.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                        <template v-if="editando === t.id">
                            <td class="px-6 py-2"><CampoTexto v-model="edicion.abreviatura" etiqueta="" /></td>
                            <td class="px-4 py-2"><CampoTexto v-model="edicion.descripcion" etiqueta="" /></td>
                            <td class="px-6 py-2 text-right">
                                <button type="button" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }" @click="guardarEdicion">Guardar</button>
                                <button type="button" class="ml-3 text-sm" :style="{ color: 'var(--color-suave)' }" @click="editando = null">Cancelar</button>
                            </td>
                        </template>
                        <template v-else>
                            <td class="px-6 py-4">
                                <span class="inline-block rounded-md px-2 py-0.5 font-mono text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }">{{ t.abreviatura }}</span>
                            </td>
                            <td class="px-4 py-4 text-contenido">{{ t.descripcion }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-1">
                                    <BotonAccion variante="editar" @click="abrirEdicion(t)" />
                                    <BotonAccion variante="eliminar" :disabled="t.en_uso" @click="eliminar(t)" />
                                </div>
                            </td>
                        </template>
                    </tr>
                    <tr v-if="!titulos.length">
                        <td colspan="3" class="px-6 py-8 text-center" :style="{ color: 'var(--color-suave)' }">
                            Aún no hay títulos. Agrega el primero arriba.
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
