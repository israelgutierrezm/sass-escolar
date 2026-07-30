<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Genero {
    id: number;
    clave: string;
    identificador: string | null;
    nombre: string;
}

defineProps<{ generos: Genero[] }>();

const alta = useForm({ clave: '', identificador: '', nombre: '' });
const creando = ref(false);

function crear(): void {
    alta.post('/plataforma/configuracion/catalogos/generos', {
        preserveScroll: true,
        onSuccess: () => { alta.reset(); creando.value = false; },
    });
}

const editando = ref<number | null>(null);
const edicion = reactive({ clave: '', identificador: '', nombre: '' });

function abrirEdicion(g: Genero): void {
    editando.value = g.id;
    edicion.clave = g.clave;
    edicion.identificador = g.identificador ?? '';
    edicion.nombre = g.nombre;
}

function guardar(g: Genero): void {
    router.put(`/plataforma/configuracion/catalogos/generos/${g.id}`, { ...edicion }, {
        preserveScroll: true,
        onSuccess: () => { editando.value = null; },
    });
}

function eliminar(g: Genero): void {
    if (!confirm(`¿Eliminar el género "${g.nombre}"?`)) return;
    router.delete(`/plataforma/configuracion/catalogos/generos/${g.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Catálogos" />

    <AppLayout titulo="Catálogos">
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <h2 class="text-base font-semibold">Género</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Alimenta el atributo <span class="font-mono">idGenero</span> del certificado electrónico
                        (250 = Mujer, 251 = Hombre). El <strong>identificador</strong> es el valor oficial que
                        viaja en el XML. Es un catálogo compartido por toda la plataforma.
                    </p>
                </div>
                <BotonAccion v-if="!creando" variante="nuevo" texto="Nuevo género" class="shrink-0" @click="creando = true" />
            </div>

            <form v-if="creando" class="mt-5 flex flex-wrap items-end gap-3 border-t pt-5" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
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

            <ul class="mt-5 divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                <li v-for="g in generos" :key="g.id" class="flex items-center gap-3 py-2.5" :style="{ borderColor: 'var(--color-borde)' }">
                    <template v-if="editando === g.id">
                        <input v-model="edicion.clave" class="w-20 rounded border px-2 py-1 font-mono text-xs" :style="{ borderColor: 'var(--color-borde)' }" />
                        <input v-model="edicion.identificador" class="w-24 rounded border px-2 py-1 font-mono text-xs" :style="{ borderColor: 'var(--color-borde)' }" placeholder="identificador" />
                        <input v-model="edicion.nombre" class="min-w-0 flex-1 rounded border px-2 py-1 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        <button type="button" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }" @click="guardar(g)">Guardar</button>
                        <BotonAccion variante="cerrar" @click="editando = null" />
                    </template>
                    <template v-else>
                        <span class="w-20 shrink-0 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ g.clave }}</span>
                        <span class="min-w-0 flex-1">{{ g.nombre }}</span>
                        <span class="shrink-0 rounded px-1.5 py-0.5 font-mono text-[11px]" :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }" title="Identificador oficial (DEC)">
                            ID {{ g.identificador ?? '—' }}
                        </span>
                        <span class="flex shrink-0 items-center gap-1">
                            <BotonAccion variante="editar" @click="abrirEdicion(g)" />
                            <BotonAccion variante="eliminar" @click="eliminar(g)" />
                        </span>
                    </template>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
