<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * Los mostradores donde se recibe dinero.
 *
 * Es el catálogo, no la operación: aquí la dirección decide qué cajas existen y
 * en qué campus, y en «Caja» quien cobra abre su turno. Son dos permisos porque
 * son dos oficios.
 */
interface Caja {
    id: number;
    clave: string;
    nombre: string;
    campus: string | null;
    campus_id: number;
    activa: boolean;
    con_turno_abierto: boolean;
}

const props = defineProps<{
    cajas: Caja[];
    campus: { id: number; nombre: string }[];
}>();

function vacia() {
    return { clave: '', nombre: '', campus_id: props.campus[0]?.id ?? null, activa: true };
}

const creando = ref(false);
const editando = ref<number | null>(null);
const alta = useForm(vacia());
const datos = useForm(vacia());

function crear(): void {
    alta.post('/finanzas/cajas', { preserveScroll: true, onSuccess: () => alta.reset() });
}

function abrirEdicion(c: Caja): void {
    if (editando.value === c.id) {
        editando.value = null;

        return;
    }

    editando.value = c.id;
    datos.clave = c.clave;
    datos.nombre = c.nombre;
    datos.campus_id = c.campus_id;
    datos.activa = c.activa;
}

function guardar(c: Caja): void {
    datos.put(`/finanzas/cajas/${c.id}`, { preserveScroll: true, onSuccess: () => (editando.value = null) });
}
</script>

<template>
    <Head title="Cajas" />

    <AppLayout titulo="Cajas">
        <TarjetaSeccion
            titulo="Cajas"
            descripcion="Los mostradores donde se recibe dinero. Una caja es un lugar físico: el efectivo no viaja entre campus."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <BotonPrincipal
                    v-if="!creando"
                    texto="Agregar una caja"
                    icono="crear"
                    @click="creando = true"
                />

                <form v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="crear">
                    <CampoTexto v-model="alta.clave" etiqueta="Clave" mono requerido :error="alta.errors.clave" />
                    <CampoTexto v-model="alta.nombre" etiqueta="Nombre" requerido :error="alta.errors.nombre" />
                    <CampoSelect
                        v-model="alta.campus_id"
                        etiqueta="Campus"
                        requerido
                        :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        :error="alta.errors.campus_id"
                    />
                    <div class="flex items-end gap-2">
                        <BotonPrincipal :procesando="alta.processing" texto="Crear" icono="crear" />
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="creando = false"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Caja</th>
                            <th class="px-4 py-3 font-medium">Campus</th>
                            <th class="px-4 py-3 font-medium">Estado</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="c in cajas" :key="c.id">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="block font-medium">{{ c.nombre }}</span>
                                    <span class="font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ c.clave }}</span>
                                </td>
                                <td class="px-4 py-3">{{ c.campus ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="!c.activa" class="text-xs" :style="{ color: 'var(--color-suave)' }">Apagada</span>
                                    <span
                                        v-else-if="c.con_turno_abierto"
                                        class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                        :style="{ color: '#15803d', backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)' }"
                                    >Con turno abierto</span>
                                    <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">Libre</span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <BotonAccion :variante="editando === c.id ? 'cerrar' : 'editar'" @click="abrirEdicion(c)" />
                                </td>
                            </tr>
                            <tr v-if="editando === c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="4" class="px-6 py-4" style="background-color: color-mix(in srgb, var(--color-acento) 4%, transparent)">
                                    <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="guardar(c)">
                                        <CampoTexto v-model="datos.clave" etiqueta="Clave" mono requerido :error="datos.errors.clave" />
                                        <CampoTexto v-model="datos.nombre" etiqueta="Nombre" requerido :error="datos.errors.nombre" />
                                        <CampoSelect
                                            v-model="datos.campus_id"
                                            etiqueta="Campus"
                                            requerido
                                            :opciones="campus.map((x) => ({ valor: x.id, texto: x.nombre }))"
                                            :error="datos.errors.campus_id"
                                        />
                                        <div class="flex flex-col justify-end gap-2">
                                            <label class="flex items-center gap-2 text-sm">
                                                <input v-model="datos.activa" type="checkbox" />
                                                Activa
                                            </label>
                                            <!--
                                                Una caja no se borra: sus turnos cerrados son los
                                                cortes, y borrarla se los llevaría. Se apaga.
                                            -->
                                            <BotonPrincipal :procesando="datos.processing" texto="Guardar" />
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p v-if="!cajas.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay cajas. Sin ellas nadie puede abrir un turno ni cuadrar el efectivo del día.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
