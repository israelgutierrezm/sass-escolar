<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';

/**
 * Quién ha estado abriendo bitácoras de tutoría.
 *
 * ── Para qué ───────────────────────────────────────────────────────────────
 * La lista por alumno sirve mientras se opera —«¿alguien más vio esto?»—, pero
 * no responde la pregunta del día de una filtración: quién ha estado abriendo
 * bitácoras esta semana. Sin esta pantalla habría que ir alumno por alumno, que
 * con quinientos es lo mismo que no poder.
 *
 * ── Se ve la consulta, no el contenido ─────────────────────────────────────
 * Aquí no aparece lo que se leyó, sólo que se leyó. Una pantalla de auditoría
 * que muestre lo vigilado multiplica el problema que intenta resolver.
 */
const props = defineProps<{
    accesos: {
        id: number;
        quien: string;
        alumno: string;
        alumno_id: number;
        cuando: string | null;
        sesiones: number;
        reservadas: number;
        ip: string | null;
    }[];
    filtros: { desde: string; hasta: string; persona_id: number | null };
    personas: { id: number; nombre: string }[];
    tope: boolean;
}>();

const desde = ref(props.filtros.desde);
const hasta = ref(props.filtros.hasta);
const persona = ref<number | null>(props.filtros.persona_id);

function filtrar(): void {
    router.get(
        '/escolar/tutorias/accesos',
        {
            desde: desde.value,
            hasta: hasta.value,
            ...(persona.value ? { persona_id: persona.value } : {}),
        },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Accesos a bitácoras" />

    <AppLayout titulo="Accesos a bitácoras de tutoría">
        <BotonVolver href="/escolar/tutorias" texto="Tutorías" class="mb-4" />

        <section class="tarjeta mb-4 p-6">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Cada vez que alguien abre la bitácora de un alumno queda registrado aquí: quién, de
                quién y cuándo. No se guarda lo que leyó, sólo que lo leyó.
            </p>

            <div class="mt-4 flex flex-wrap items-end gap-4">
                <div class="w-40">
                    <CampoTexto v-model="desde" etiqueta="Desde" tipo="date" />
                </div>
                <div class="w-40">
                    <CampoTexto v-model="hasta" etiqueta="Hasta" tipo="date" />
                </div>
                <div class="w-64">
                    <CampoSelect
                        v-model="persona"
                        etiqueta="Quién consultó"
                        vacio="Cualquiera"
                        :opciones="personas.map((p) => ({ valor: p.id, texto: p.nombre }))"
                    />
                </div>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                    :style="{ backgroundColor: 'var(--color-acento)' }"
                    @click="filtrar"
                >
                    Filtrar
                </button>
            </div>

            <!--
                Se avisa del tope en vez de dejar creer que eso es todo: una
                auditoría recortada en silencio es peor que no tenerla, porque
                se concluye sobre datos incompletos sin saberlo.
            -->
            <p v-if="tope" class="mt-3 text-xs text-amber-700">
                Se muestran los 500 más recientes del rango. Acota las fechas para verlos todos.
            </p>
        </section>

        <section class="tarjeta overflow-hidden">
            <div v-if="accesos.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr class="border-b" :style="{ borderColor: 'var(--color-borde)' }">
                            <th class="px-5 py-2 font-medium">Cuándo</th>
                            <th class="py-2 font-medium">Quién consultó</th>
                            <th class="py-2 font-medium">Bitácora de</th>
                            <th class="py-2 font-medium">Sesiones</th>
                            <th class="py-2 pr-5 font-medium">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="a in accesos"
                            :key="a.id"
                            class="border-b last:border-0"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <td class="px-5 py-2.5 tabular-nums">{{ a.cuando }}</td>
                            <td class="py-2.5 font-medium">{{ a.quien }}</td>
                            <td class="py-2.5">
                                <Link
                                    :href="`/escolar/tutorias/${a.alumno_id}/bitacora`"
                                    :style="{ color: 'var(--color-acento)' }"
                                >
                                    {{ a.alumno }}
                                </Link>
                            </td>
                            <td class="py-2.5 tabular-nums">
                                {{ a.sesiones }}
                                <!--
                                    Cuántas quedaron reservadas distingue a quien
                                    leyó un expediente completo de quien topó con
                                    una pared: al revisar una filtración, no es lo
                                    mismo.
                                -->
                                <span v-if="a.reservadas" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    ({{ a.reservadas }} reservada{{ a.reservadas === 1 ? '' : 's' }})
                                </span>
                            </td>
                            <td class="py-2.5 pr-5 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ a.ip ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-else class="px-5 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Nadie abrió ninguna bitácora en ese rango.
            </p>
        </section>
    </AppLayout>
</template>
