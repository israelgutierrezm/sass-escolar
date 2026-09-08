<script setup lang="ts">
/**
 * Salida segura (escuela): ver quién recoge a un alumno y registrar bloqueos de
 * custodia. Los TERCEROS los agrega la familia desde su portal; aquí sólo se
 * ven. El BLOQUEO es una restricción legal que registra la escuela, con motivo.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';

interface Efectivo { nombre: string | null; parentesco: string | null; origen: string; vigencia_hasta: string | null }
interface Tercero { id: number; nombre: string; identificacion: string | null; parentesco: string | null; vigencia_hasta: string | null; vigente: boolean }
interface Tutor { persona_id: number; nombre: string | null; parentesco: string | null }
interface Bloqueo { id: number; nombre: string | null; motivo: string | null; vigencia_hasta: string | null }

const props = defineProps<{
    parentescos: { id: number; nombre: string }[];
    alumno?: { id: number; nombre: string };
    efectiva?: Efectivo[];
    terceros?: Tercero[];
    tutores?: Tutor[];
    bloqueos?: Bloqueo[];
}>();

function abrir(item: { id: number }): void {
    router.get(`/plataforma/salida-segura/${item.id}`);
}

const bloqueo = useForm({ persona_id: null as number | null, motivo: '', vigencia_hasta: '' });
const bloqueando = ref(false);

function bloquear(): void {
    if (!props.alumno) return;

    bloqueo.post(`/plataforma/salida-segura/${props.alumno.id}/bloquear`, {
        preserveScroll: true,
        onSuccess: () => {
            bloqueo.reset();
            bloqueando.value = false;
        },
    });
}

function desbloquear(id: number): void {
    if (!confirm('¿Retirar este bloqueo de custodia?')) return;

    router.delete(`/plataforma/salida-segura/bloqueos/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Salida segura" />

    <AppLayout titulo="Salida segura">
        <div class="mx-auto max-w-3xl space-y-4">
            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Busca al alumno</h2>
                <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Verás quién puede recogerlo. Los terceros los agrega la familia; aquí registras
                    bloqueos de custodia.
                </p>
                <BuscadorRemoto url="/buscar/alumnos" etiqueta="" marcador="Nombre o matrícula…" class="mt-3" @elegido="abrir" />
            </section>

            <template v-if="alumno">
                <section class="tarjeta overflow-hidden">
                    <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                        <h2 class="text-base font-semibold">Puede recoger a {{ alumno.nombre }}</h2>
                    </header>
                    <ul v-if="efectiva && efectiva.length" class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                        <li v-for="(p, i) in efectiva" :key="'e' + i" class="flex flex-wrap items-center justify-between gap-2 px-6 py-2.5 text-sm">
                            <span>{{ p.nombre }}<span v-if="p.parentesco" class="text-xs" :style="{ color: 'var(--color-suave)' }"> · {{ p.parentesco }}</span></span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: `color-mix(in srgb, ${p.origen === 'tutor' ? '#0d9488' : '#7c3aed'} 14%, transparent)`, color: p.origen === 'tutor' ? '#0d9488' : '#7c3aed' }">
                                {{ p.origen === 'tutor' ? 'Tutor' : 'Autorizado' }}<span v-if="p.vigencia_hasta"> · hasta {{ p.vigencia_hasta }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="px-6 py-6 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Nadie puede recogerlo todavía. La familia agrega terceros desde su portal.
                    </p>
                </section>

                <!-- Bloqueos de custodia -->
                <section class="tarjeta overflow-hidden">
                    <header class="flex flex-wrap items-center justify-between gap-2 border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                        <div>
                            <h2 class="text-base font-semibold">Bloqueos de custodia</h2>
                            <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">Quien esté aquí NO recoge, aunque sea tutor.</p>
                        </div>
                        <button
                            v-if="tutores && tutores.length"
                            type="button"
                            class="rounded-lg border px-3 py-1.5 text-sm"
                            :style="{ borderColor: '#b91c1c', color: '#b91c1c' }"
                            @click="bloqueando = !bloqueando"
                        >{{ bloqueando ? 'Cancelar' : 'Bloquear a un tutor' }}</button>
                    </header>

                    <form v-if="bloqueando" class="space-y-3 border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="bloquear">
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium">¿A quién?</span>
                            <select v-model="bloqueo.persona_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                <option :value="null">Elige…</option>
                                <option v-for="t in tutores" :key="t.persona_id" :value="t.persona_id">{{ t.nombre }} ({{ t.parentesco }})</option>
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium">Motivo</span>
                            <textarea v-model="bloqueo.motivo" required rows="2" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" placeholder="Sentencia de custodia, medida cautelar…" />
                            <span v-if="bloqueo.errors.motivo" class="mt-1 block text-xs text-red-600">{{ bloqueo.errors.motivo }}</span>
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium">Vigente hasta (opcional)</span>
                            <input v-model="bloqueo.vigencia_hasta" type="date" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                        </label>
                        <button type="submit" :disabled="bloqueo.processing" class="rounded-lg px-4 py-2 text-sm font-medium text-white" :style="{ backgroundColor: '#b91c1c' }">Registrar el bloqueo</button>
                    </form>

                    <ul v-if="bloqueos && bloqueos.length" class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                        <li v-for="b in bloqueos" :key="b.id" class="flex flex-wrap items-start justify-between gap-2 px-6 py-3 text-sm">
                            <div class="min-w-0">
                                <p class="font-medium">{{ b.nombre }}</p>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ b.motivo }}<span v-if="b.vigencia_hasta"> · hasta {{ b.vigencia_hasta }}</span></p>
                            </div>
                            <button type="button" class="text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="desbloquear(b.id)">Retirar</button>
                        </li>
                    </ul>
                    <p v-else class="px-6 py-4 text-sm" :style="{ color: 'var(--color-suave)' }">Sin bloqueos.</p>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
