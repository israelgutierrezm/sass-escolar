<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import BotonExpediente from '@/Components/BotonExpediente.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

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

const ICONO_TUTORES =
    'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z';

function iniciales(nombre: string | null): string {
    if (!nombre) return '—';
    const partes = nombre.trim().split(/\s+/);
    return ((partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? '')).toUpperCase() || '—';
}

// «Ver como» el padre/tutor: entrar con su cuenta. Queda en bitácora.
function verComo(suplantable: { usuario_id: number; usuario: string }): void {
    if (!confirm(`Vas a entrar como ${suplantable.usuario}. Queda registrado quién lo hizo y cuándo. ¿Continuar?`)) return;
    router.post(`/suplantar/${suplantable.usuario_id}`);
}
</script>

<template>
    <Head title="Padres y tutores" />

    <AppLayout titulo="Padres y tutores">
        <p class="max-w-2xl text-sm" :style="{ color: 'var(--color-suave)' }">
            Directorio de padres y tutores vinculados a alumnos. El vínculo (agregar, quitar, qué puede ver)
            se administra desde el expediente de cada alumno; aquí ves el panorama y puedes «Ver como» ellos.
        </p>

        <TarjetaSeccion titulo="Padres y tutores" descripcion="Directorio vinculado a alumnos" :icono="ICONO_TUTORES" sin-relleno>
            <template #insignia>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ tutores.length }} en total
                </span>
            </template>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Padre / Tutor</th>
                            <th class="px-4 py-3 font-semibold">Correo</th>
                            <th class="px-4 py-3 font-semibold">Alumnos vinculados</th>
                            <th class="px-6 py-3 text-right font-semibold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="t in tutores"
                            :key="t.persona_id ?? t.nombre"
                            class="fila-nueva border-t transition-colors"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <!-- Tutor: avatar + nombre + CURP -->
                            <td class="px-6 py-4">
                                <span class="flex items-center gap-3">
                                    <span
                                        class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-semibold"
                                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                                    >{{ iniciales(t.nombre) }}</span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-contenido">{{ t.nombre }}</span>
                                        <span v-if="t.curp" class="block truncate font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ t.curp }}</span>
                                    </span>
                                </span>
                            </td>

                            <!-- Correo -->
                            <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">{{ t.email ?? 'sin correo' }}</td>

                            <!-- Alumnos vinculados (chips) -->
                            <td class="px-4 py-4">
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="(a, i) in t.alumnos"
                                        :key="i"
                                        class="rounded-full px-2 py-0.5 text-[11px]"
                                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                                    >
                                        {{ a.nombre }}
                                        <span :style="{ opacity: 0.7 }">· {{ a.parentesco }}</span>
                                    </span>
                                </div>
                            </td>

                            <!-- Acciones -->
                            <td class="px-6 py-4 text-right">
                                <button
                                    v-if="t.suplantable"
                                    type="button"
                                    class="boton-accion boton-fantasma inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-xs font-medium"
                                    :style="{ borderColor: 'color-mix(in srgb, #0077B6 35%, transparent)', color: '#0077B6' }"
                                    title="Entrar como este padre/tutor para ver lo que ve. Queda en bitácora."
                                    @click="verComo(t.suplantable)"
                                >
                                    Ver como
                                </button>
                                <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">sin cuenta</span>

                                <!-- El expediente contesta lo que antes obligaba
                                     a suplantar: de quién es tutor y qué ve de
                                     cada uno. -->
                                <BotonExpediente :href="`/padres-tutores/${t.persona_id}`" class="ml-1" />
                            </td>
                        </tr>
                        <tr v-if="tutores.length === 0">
                            <td colspan="4" class="px-6 py-10 text-center" :style="{ color: 'var(--color-suave)' }">
                                Aún no hay padres ni tutores vinculados. Se vinculan desde el expediente de cada alumno.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </TarjetaSeccion>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
