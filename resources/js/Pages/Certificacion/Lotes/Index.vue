<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';

interface Lote {
    id: number;
    folio: string;
    nombre: string | null;
    estado: string;
    estado_label: string;
    estado_color: string;
    total: number;
    certificados: number;
    responsable: string | null;
    cerrado_en: string | null;
    firmado_en: string | null;
    creado_en: string | null;
}

defineProps<{ lotes: Lote[] }>();

const creando = ref(false);
const form = useForm({ nombre: '' });

function crear(): void {
    form.post('/certificacion/lotes', {
        onSuccess: () => {
            form.reset();
            creando.value = false;
        },
    });
}

const clasesBadge: Record<string, string> = {
    gris: 'bg-gray-100 text-gray-700 dark:bg-gray-700/40 dark:text-gray-200',
    ambar: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
    verde: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300',
};
</script>

<template>
    <Head title="Lotes de certificación" />

    <AppLayout titulo="Lotes de certificación">
        <div class="mb-6 flex items-start justify-between gap-4">
            <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Agrupa alumnos que ya cerraron su plan para certificarlos juntos. Arma el lote, agrégale
                alumnos, ciérralo y fírmalo con la e.firma del responsable: cada alumno recibe su XML sellado.
            </p>
            <button
                v-if="!creando"
                type="button"
                class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium text-white"
                :style="{ backgroundColor: 'var(--color-acento)' }"
                @click="creando = true"
            >
                Nuevo lote
            </button>
        </div>

        <form v-if="creando" class="tarjeta mb-6 p-5" @submit.prevent="crear">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1">
                    <CampoTexto
                        v-model="form.nombre"
                        etiqueta="Nombre del lote (opcional)"
                        placeholder="Ej. Egresados enero 2026"
                        :error="form.errors.nombre"
                    />
                </div>
                <BotonPrincipal :procesando="form.processing" texto="Crear lote" />
                <button
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="creando = false; form.reset()"
                >
                    Cancelar
                </button>
            </div>
        </form>

        <div class="tarjeta overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                        <th class="px-5 py-3 font-medium">Folio</th>
                        <th class="px-5 py-3 font-medium">Nombre</th>
                        <th class="px-5 py-3 font-medium">Estado</th>
                        <th class="px-5 py-3 text-center font-medium">Alumnos</th>
                        <th class="px-5 py-3 font-medium">Responsable</th>
                        <th class="px-5 py-3 font-medium">Creado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="lote in lotes"
                        :key="lote.id"
                        class="border-b transition hover:bg-black/[.02] dark:hover:bg-white/[.03]"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-5 py-3">
                            <Link :href="`/certificacion/lotes/${lote.id}`" class="font-mono font-medium" :style="{ color: 'var(--color-acento)' }">
                                {{ lote.folio }}
                            </Link>
                        </td>
                        <td class="px-5 py-3">{{ lote.nombre ?? '—' }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="clasesBadge[lote.estado_color]">
                                {{ lote.estado_label }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span v-if="lote.certificados > 0" :style="{ color: 'var(--color-suave)' }">
                                {{ lote.certificados }}/{{ lote.total }}
                            </span>
                            <span v-else>{{ lote.total }}</span>
                        </td>
                        <td class="px-5 py-3" :style="{ color: 'var(--color-suave)' }">{{ lote.responsable ?? '—' }}</td>
                        <td class="px-5 py-3" :style="{ color: 'var(--color-suave)' }">{{ lote.creado_en }}</td>
                    </tr>
                    <tr v-if="lotes.length === 0">
                        <td colspan="6" class="px-5 py-10 text-center" :style="{ color: 'var(--color-suave)' }">
                            Aún no hay lotes. Crea el primero para empezar a certificar.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
