<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Tipo {
    id: number;
    clave: string;
    identificador: string | null;
    nombre: string;
    protegido: boolean;
}

defineProps<{ tipos: Tipo[] }>();

const alta = useForm({ clave: '', identificador: '', nombre: '' });
const creando = ref(false);

function crear(): void {
    alta.post('/certificacion/configuracion/tipos-certificacion', {
        preserveScroll: true,
        onSuccess: () => { alta.reset(); creando.value = false; },
    });
}

const editando = ref<number | null>(null);
const edicion = reactive({ clave: '', identificador: '', nombre: '' });

function abrirEdicion(t: Tipo): void {
    editando.value = t.id;
    edicion.clave = t.clave;
    edicion.identificador = t.identificador ?? '';
    edicion.nombre = t.nombre;
}

function guardar(t: Tipo): void {
    router.put(`/certificacion/configuracion/tipos-certificacion/${t.id}`, { ...edicion }, {
        preserveScroll: true,
        onSuccess: () => { editando.value = null; },
    });
}

function eliminar(t: Tipo): void {
    if (!confirm(`¿Eliminar el tipo "${t.nombre}"?`)) return;
    router.delete(`/certificacion/configuracion/tipos-certificacion/${t.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Tipos de certificación" />

    <AppLayout titulo="Tipos de certificación">
        <div class="mb-6 flex items-start justify-between gap-4">
            <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
                El tipo de Documento Electrónico de Certificación (DEC) de la SEP. Los oficiales
                (79 Total, 80 Parcial) son fijos; el <strong>identificador</strong> es el valor que viaja en el XML.
            </p>
            <BotonAccion v-if="!creando" variante="nuevo" texto="Nuevo tipo" class="shrink-0" @click="creando = true" />
        </div>

        <form v-if="creando" class="tarjeta mb-6 flex flex-wrap items-end gap-3 p-5" @submit.prevent="crear">
            <div class="w-28">
                <label class="mb-1 block text-sm font-medium">Clave</label>
                <input v-model="alta.clave" class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                <p v-if="alta.errors.clave" class="mt-1 text-xs text-red-600">{{ alta.errors.clave }}</p>
            </div>
            <div class="w-32">
                <label class="mb-1 block text-sm font-medium">Identificador</label>
                <input v-model="alta.identificador" class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
            </div>
            <div class="min-w-48 flex-1">
                <label class="mb-1 block text-sm font-medium">Nombre</label>
                <input v-model="alta.nombre" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                <p v-if="alta.errors.nombre" class="mt-1 text-xs text-red-600">{{ alta.errors.nombre }}</p>
            </div>
            <BotonPrincipal :procesando="alta.processing" texto="Agregar" />
            <button type="button" class="rounded-lg border px-4 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @click="creando = false; alta.reset()">Cancelar</button>
        </form>

        <div class="tarjeta overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                        <th class="px-5 py-3 font-medium">Clave</th>
                        <th class="px-5 py-3 font-medium">Identificador</th>
                        <th class="px-5 py-3 font-medium">Nombre</th>
                        <th class="px-5 py-3 text-right font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in tipos" :key="t.id" class="border-b" :style="{ borderColor: 'var(--color-borde)' }">
                        <template v-if="editando === t.id">
                            <td class="px-5 py-2"><input v-model="edicion.clave" class="w-20 rounded border px-2 py-1 font-mono text-xs" :style="{ borderColor: 'var(--color-borde)' }" /></td>
                            <td class="px-5 py-2"><input v-model="edicion.identificador" class="w-24 rounded border px-2 py-1 font-mono text-xs" :style="{ borderColor: 'var(--color-borde)' }" /></td>
                            <td class="px-5 py-2"><input v-model="edicion.nombre" class="w-full rounded border px-2 py-1 text-sm" :style="{ borderColor: 'var(--color-borde)' }" /></td>
                            <td class="px-5 py-2 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }" @click="guardar(t)">Guardar</button>
                                    <BotonAccion variante="cerrar" @click="editando = null" />
                                </div>
                            </td>
                        </template>
                        <template v-else>
                            <td class="px-5 py-3 font-mono">{{ t.clave }}</td>
                            <td class="px-5 py-3 font-mono" :style="{ color: 'var(--color-suave)' }">{{ t.identificador ?? '—' }}</td>
                            <td class="px-5 py-3">{{ t.nombre }}</td>
                            <td class="px-5 py-3 text-right">
                                <span v-if="t.protegido" class="rounded px-2 py-0.5 text-xs" :style="{ color: 'var(--color-suave)', backgroundColor: 'var(--color-fondo)' }" title="Valor oficial: no se puede modificar ni eliminar">Oficial</span>
                                <span v-else class="flex items-center justify-end gap-1">
                                    <BotonAccion variante="editar" @click="abrirEdicion(t)" />
                                    <BotonAccion variante="eliminar" @click="eliminar(t)" />
                                </span>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
