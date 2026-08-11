<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';

/**
 * El equipo de promoción: quién es asesor y cuál está en turno.
 *
 * ── Lo que esta pantalla decide de verdad ─────────────────────────────────
 * No reparte permisos —eso es `/plataforma/roles`—: dice quién ATIENDE
 * prospectos. Es la otra mitad del par que sostiene todo el CRM: el permiso
 * dice qué puede hacer alguien, y estar aquí dice sobre quiénes.
 *
 * ── El interruptor, no el botón de borrar ─────────────────────────────────
 * Apagar a un asesor lo saca del reparto y le deja su cartera y su historial.
 * Retirarlo sólo se puede si no tiene prospectos; con ellos, el sistema manda a
 * apagarlo. Un asesor borrado deja prospectos sin dueño en silencio.
 *
 * La CARGA se muestra junto a cada uno porque es lo que hace comprensible el
 * reparto por turno: sin ella, «le tocó a Ana» parece arbitrario.
 */
defineProps<{
    asesores: {
        persona_id: number;
        nombre: string | null;
        email: string | null;
        clave_asesor: string | null;
        activo: boolean;
        campus: { id: number; nombre: string }[];
        prospectos: number;
    }[];
    campus: { id: number; nombre: string }[];
    /** Las dos reglas del reparto: son decisiones distintas. */
    reparto: { quienRegistra: boolean; modo: string };
}>();

const alta = useForm({
    persona_id: null as number | null,
    clave_asesor: '',
    campus_ids: [] as number[],
});

const dandoDeAlta = ref(false);

function guardar(): void {
    alta.post('/promocion/asesores', {
        preserveScroll: true,
        onSuccess: () => {
            alta.reset();
            dandoDeAlta.value = false;
        },
    });
}

function alternar(personaId: number, activo: boolean): void {
    router.put(`/promocion/asesores/${personaId}`, { activo: !activo }, { preserveScroll: true });
}

/** Los campus que atiende. Vacío = todos, que es lo que espera un coordinador. */
const editandoCampus = ref<number | null>(null);
const campusForm = useForm({ activo: true, campus_ids: [] as number[] });

function abrirCampus(a: { persona_id: number; activo: boolean; campus: { id: number }[] }): void {
    editandoCampus.value = editandoCampus.value === a.persona_id ? null : a.persona_id;
    campusForm.activo = a.activo;
    campusForm.campus_ids = a.campus.map((c) => c.id);
}

function guardarCampus(personaId: number): void {
    campusForm.put(`/promocion/asesores/${personaId}`, {
        preserveScroll: true,
        onSuccess: () => { editandoCampus.value = null; },
    });
}

function retirar(personaId: number, nombre: string | null): void {
    if (!confirm(`¿Retirar a ${nombre ?? 'esta persona'} del equipo de promoción?`)) {
        return;
    }

    router.delete(`/promocion/asesores/${personaId}`, { preserveScroll: true });
}

const comoReparte: Record<string, string> = {
    manual: 'a mano',
    secuencial: 'por turno entre los asesores del campus',
};
</script>

<template>
    <Head title="Asesores" />

    <AppLayout titulo="Asesores">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-suave">
                Quién atiende prospectos. Los <strong>activos</strong> entran en el reparto;
                los inactivos conservan su cartera y su historial.
            </p>
            <button
                type="button"
                class="rounded-lg px-3.5 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="dandoDeAlta = !dandoDeAlta"
            >
                {{ dandoDeAlta ? 'Cancelar' : '+ Agregar asesor' }}
            </button>
        </div>

        <!-- Cómo se reparte hoy: se configura en otra pantalla, pero se dice
             aquí porque es donde se está pensando en el equipo. -->
        <div class="tarjeta mb-4 flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-3 text-sm">
            <span class="text-suave">Reparto:</span>
            <strong v-if="reparto.quienRegistra" class="text-contenido">
                el asesor se queda lo que registra
            </strong>
            <span v-if="reparto.quienRegistra" class="text-suave">·</span>
            <span class="text-suave">{{ reparto.quienRegistra ? 'lo demás,' : 'todo,' }}</span>
            <strong class="text-contenido">{{ comoReparte[reparto.modo] ?? reparto.modo }}</strong>
            <a href="/plataforma/configuracion" class="ml-auto text-xs" :style="{ color: 'var(--color-acento)' }">
                Cambiar en Configuración →
            </a>
        </div>

        <form v-if="dandoDeAlta" class="tarjeta mb-4 grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="guardar">
            <div class="sm:col-span-2">
                <BuscadorRemoto
                    v-model="alta.persona_id"
                    url="/promocion/asesores/candidatas"
                    etiqueta="Persona"
                    marcador="Busca por nombre, correo o CURP…"
                    ayuda="Alguien que ya existe en el sistema: un asesor no es un registro nuevo."
                    :error="alta.errors.persona_id"
                    :campos="{ titulo: 'etiqueta', subtitulo: 'detalle' }"
                />
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-suave">Clave (opcional)</label>
                <input
                    v-model="alta.clave_asesor"
                    type="text"
                    class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-superficie)', color: 'var(--color-contenido)' }"
                />
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wide text-suave">Campus que atiende</label>
                <p class="mt-1 text-xs text-suave">Sin marcar ninguno, atiende todos.</p>
                <div class="mt-1 flex flex-wrap gap-2">
                    <label v-for="c in campus" :key="c.id" class="flex items-center gap-1.5 text-sm">
                        <input v-model="alta.campus_ids" type="checkbox" :value="c.id" />
                        {{ c.nombre }}
                    </label>
                </div>
            </div>
            <div class="sm:col-span-2">
                <button
                    type="submit"
                    class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    :disabled="!alta.persona_id || alta.processing"
                >
                    Dar de alta
                </button>
            </div>
        </form>

        <div v-if="!asesores.length" class="tarjeta px-6 py-14 text-center">
            <h2 class="text-base font-semibold text-contenido">Todavía no hay asesores</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-suave">
                Sin asesores, los prospectos nuevos no tienen a quién asignarse y el embudo
                no se puede acotar por cartera.
            </p>
        </div>

        <div v-else class="tarjeta overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-xs uppercase tracking-wide text-suave" :style="{ borderColor: 'var(--color-borde)' }">
                        <th class="px-4 py-3">Asesor</th>
                        <th class="px-4 py-3">Campus</th>
                        <th class="px-4 py-3 text-right">Prospectos</th>
                        <th class="px-4 py-3 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="a in asesores" :key="a.persona_id">
                        <tr class="border-b" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-2 w-2 shrink-0 rounded-full"
                                        :style="{ backgroundColor: a.activo ? '#16a34a' : 'var(--color-suave)' }"
                                        :title="a.activo ? 'Activo' : 'Inactivo'"
                                    />
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-contenido">{{ a.nombre }}</p>
                                        <p class="truncate text-xs text-suave">
                                            {{ a.email ?? '—' }}
                                            <span v-if="a.clave_asesor"> · {{ a.clave_asesor }}</span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="!a.campus.length" class="text-xs text-suave">Todos</span>
                                <span v-else class="text-xs text-contenido">
                                    {{ a.campus.map((c) => c.nombre).join(', ') }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-contenido">{{ a.prospectos }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <button
                                        type="button"
                                        class="rounded-lg border px-2.5 py-1.5 text-xs"
                                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-acento)' }"
                                        @click="abrirCampus(a)"
                                    >
                                        Campus
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-lg border px-2.5 py-1.5 text-xs"
                                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-contenido)' }"
                                        @click="alternar(a.persona_id, a.activo)"
                                    >
                                        {{ a.activo ? 'Desactivar' : 'Activar' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-lg border px-2.5 py-1.5 text-xs"
                                        :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                                        @click="retirar(a.persona_id, a.nombre)"
                                    >
                                        Retirar
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="editandoCampus === a.persona_id" :style="{ borderColor: 'var(--color-borde)' }" class="border-b">
                            <td colspan="4" class="px-4 py-3">
                                <p class="mb-2 text-xs text-suave">
                                    Sin marcar ninguno atiende todos los campus, que es lo que se espera de un coordinador.
                                </p>
                                <div class="flex flex-wrap items-center gap-3">
                                    <label v-for="c in campus" :key="c.id" class="flex items-center gap-1.5 text-sm">
                                        <input v-model="campusForm.campus_ids" type="checkbox" :value="c.id" />
                                        {{ c.nombre }}
                                    </label>
                                    <button
                                        type="button"
                                        class="ml-auto rounded-lg px-3 py-1.5 text-xs font-medium"
                                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                                        @click="guardarCampus(a.persona_id)"
                                    >
                                        Guardar
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
