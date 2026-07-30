<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Tutor {
    persona_id: number | null;
    nombre: string;
    curp: string | null;
    email: string | null;
    total_alumnos: number;
    alumnos: { nombre: string; parentesco: string }[];
    suplantable: { usuario_id: number; usuario: string } | null;
}

defineProps<{ tutores: Tutor[] }>();

const etiquetaParentesco: Record<string, string> = {
    padre: 'Padre',
    madre: 'Madre',
    tutor: 'Tutor',
    abuelo: 'Abuelo/a',
    hermano: 'Hermano/a',
    otro: 'Otro',
};

// «Ver como» el padre/tutor: entrar con su cuenta. Queda en bitácora.
function verComo(suplantable: { usuario_id: number; usuario: string }): void {
    if (!confirm(`Vas a entrar como ${suplantable.usuario}. Queda registrado quién lo hizo y cuándo. ¿Continuar?`)) return;
    router.post(`/suplantar/${suplantable.usuario_id}`);
}
</script>

<template>
    <Head title="Padres y tutores" />

    <AppLayout titulo="Padres y tutores">
        <p class="mb-6 max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Directorio de padres y tutores vinculados a alumnos. El vínculo (agregar, quitar, qué puede ver)
            se administra desde el expediente de cada alumno; aquí ves el panorama y puedes «Ver como» ellos.
        </p>

        <div class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }">
                            <th class="px-5 py-3 font-medium">Nombre</th>
                            <th class="px-5 py-3 font-medium">CURP</th>
                            <th class="px-5 py-3 font-medium">Correo</th>
                            <th class="px-5 py-3 font-medium">Alumnos vinculados</th>
                            <th class="px-5 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="t in tutores"
                            :key="t.persona_id ?? t.nombre"
                            class="border-b"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-5 py-3 font-medium">{{ t.nombre }}</td>
                            <td class="px-5 py-3 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ t.curp ?? '—' }}</td>
                            <td class="px-5 py-3" :style="{ color: 'var(--color-suave)' }">{{ t.email ?? 'sin correo' }}</td>
                            <td class="px-5 py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="(a, i) in t.alumnos"
                                        :key="i"
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :style="{ backgroundColor: 'var(--color-borde)' }"
                                    >
                                        {{ a.nombre }}
                                        <span :style="{ color: 'var(--color-suave)' }">· {{ etiquetaParentesco[a.parentesco] ?? a.parentesco }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <button
                                    v-if="t.suplantable"
                                    type="button"
                                    class="rounded-lg border px-3 py-1.5 text-sm"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    title="Entrar como este padre/tutor para ver lo que ve. Queda en bitácora."
                                    @click="verComo(t.suplantable)"
                                >
                                    Ver como
                                </button>
                                <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">sin cuenta</span>
                            </td>
                        </tr>
                        <tr v-if="tutores.length === 0">
                            <td colspan="5" class="px-5 py-10 text-center" :style="{ color: 'var(--color-suave)' }">
                                Aún no hay padres ni tutores vinculados. Se vinculan desde el expediente de cada alumno.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
