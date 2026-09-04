<script setup lang="ts">
/**
 * Los casos de seguimiento: la cola de trabajo de quien acompaña.
 *
 * ── Lo que esta pantalla tiene que dejar claro ────────────────────────────
 *  1. **Qué se pasó del compromiso de primer contacto.** Va primero, en rojo y
 *     con su propia cifra arriba: un caso abierto al que nadie ha llamado es
 *     exactamente el fallo que este módulo existe para impedir.
 *  2. **Qué no tiene responsable.** Un caso sin dueño es una nota que nadie lee.
 *  3. **Que un caso NO es un expediente disciplinario.** Se dice arriba y con
 *     esas palabras: es acompañamiento, y quien lo abre por primera vez tiene
 *     que leerlo antes de escribir nada dentro.
 *
 * ── Lenguaje ─────────────────────────────────────────────────────────────
 * Nada que describa a la persona en vez de a la situación. Una prueba barre las
 * cadenas del módulo contra una lista negra.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { COLOR_PRIORIDAD, colorPermanencia, etiquetaPermanencia } from '@/utils/coloresPermanencia';

interface Caso {
    id: number;
    folio: string;
    alumno: string | null;
    matricula: string | null;
    programa: string | null;
    campus: string | null;
    estado: string;
    estado_etiqueta: string;
    estado_color: string;
    prioridad: string;
    responsable: string | null;
    abierto_en: string | null;
    dias_abierto: number;
    sla_vence_en: string | null;
    sla_vencido: boolean;
    primer_contacto_en: string | null;
    horas_primer_contacto: number | null;
    tardanza_primer_contacto: string | null;
    nivel_apertura: string | null;
    nivel_color: string | null;
    intervenciones: number | null;
    tareas_pendientes: number | null;
}

const props = defineProps<{
    casos: {
        data: Caso[];
        links: { url: string | null; label: string; active: boolean }[];
        total?: number;
        from?: number | null;
        to?: number | null;
    };
    resumen: {
        abiertos: number;
        sin_asignar: number;
        sla_vencido: number;
        por_estado: Record<string, number>;
        cerrados_30_dias: number;
    };
    catalogos: {
        estados: { valor: string; etiqueta: string; color: string }[];
        prioridades: string[];
        campus: { id: number; nombre: string }[];
        responsables: { id: number; nombre: string | null }[];
    };
    filtros: Record<string, string | null>;
    permisos: Record<string, boolean>;
}>();

const filtros = ref({ ...props.filtros });

function filtrar(): void {
    router.get('/permanencia/casos', filtros.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function limpiar(): void {
    filtros.value = {};
    filtrar();
}

/* Los dos atajos de arriba son filtros, no listas aparte: así se pueden
 * combinar con el campus y con la búsqueda, que es lo que se hace de verdad
 * («los míos sin asignar del campus norte»). */
function soloVencidos(): void {
    filtros.value = { ...filtros.value, sla: '1', sin_asignar: null };
    filtrar();
}

function soloSinAsignar(): void {
    filtros.value = { ...filtros.value, sin_asignar: '1', sla: null };
    filtrar();
}
</script>

<template>
    <Head title="Casos de seguimiento" />

    <AppLayout titulo="Casos de seguimiento">
        <section class="tarjeta mb-4 p-5">
            <h2 class="font-semibold">Acompañamiento en curso</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Cada caso es una situación que alguien está atendiendo, con su responsable, lo que se
                ha hecho y a qué se llegó. <strong>No es un expediente disciplinario</strong>: nada de
                lo que se escribe aquí modifica calificaciones, asistencia, adeudos ni la situación
                del alumno.
            </p>
        </section>

        <!-- ── Las cifras ────────────────────────────────────────────────── -->
        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="tarjeta p-4">
                <p class="text-2xl font-semibold">{{ resumen.abiertos }}</p>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">abiertos</p>
            </div>
            <button
                type="button"
                class="tarjeta p-4 text-left"
                :style="{ borderColor: resumen.sla_vencido > 0 ? 'var(--color-rojo)' : undefined }"
                @click="soloVencidos"
            >
                <p
                    class="text-2xl font-semibold"
                    :style="{ color: resumen.sla_vencido > 0 ? 'var(--color-rojo)' : undefined }"
                >
                    {{ resumen.sla_vencido }}
                </p>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    sin primer contacto en plazo
                    <span class="block text-xs">Es lo primero que hay que destrabar.</span>
                </p>
            </button>
            <button type="button" class="tarjeta p-4 text-left" @click="soloSinAsignar">
                <p class="text-2xl font-semibold">{{ resumen.sin_asignar }}</p>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    sin responsable
                    <span class="block text-xs">Un caso sin dueño no lo atiende nadie.</span>
                </p>
            </button>
            <div class="tarjeta p-4">
                <p class="text-2xl font-semibold">{{ resumen.cerrados_30_dias }}</p>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    cerrados en 30 días
                    <span class="block text-xs">Dice si la cola se mueve.</span>
                </p>
            </div>
        </div>

        <!-- ── Filtros ───────────────────────────────────────────────────── -->
        <section class="tarjeta mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <CampoTexto
                    v-model="filtros.busqueda"
                    etiqueta="Alumno, matrícula o folio"
                    marcador="Buscar…"
                    @keyup.enter="filtrar"
                />
                <CampoSelect
                    v-model="filtros.estado"
                    etiqueta="Estado"
                    :opciones="catalogos.estados.map((e) => ({ valor: e.valor, texto: e.etiqueta }))"
                    vacio="Sólo los abiertos"
                />
                <CampoSelect
                    v-model="filtros.prioridad"
                    etiqueta="Prioridad"
                    :opciones="catalogos.prioridades.map((p) => ({ valor: p, texto: etiquetaPermanencia(p) }))"
                    vacio="Todas"
                />
                <CampoSelect
                    v-model="filtros.campus_id"
                    etiqueta="Campus"
                    :opciones="catalogos.campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    vacio="Todos"
                />
                <CampoSelect
                    v-model="filtros.responsable_id"
                    etiqueta="Responsable"
                    :opciones="catalogos.responsables.map((r) => ({ valor: r.id, texto: r.nombre ?? '—' }))"
                    vacio="Cualquiera"
                />
                <div class="flex items-end gap-2 sm:col-span-2">
                    <BotonPrincipal texto="Filtrar" icono="ninguno" tipo="button" @click="filtrar" />
                    <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="limpiar">
                        Limpiar
                    </button>
                </div>
            </div>
        </section>

        <!-- ── La cola ───────────────────────────────────────────────────── -->
        <div class="tarjeta overflow-x-auto">
            <table class="w-full min-w-[64rem] text-sm">
                <thead>
                    <tr class="border-b border-borde text-left">
                        <th class="p-3">Folio</th>
                        <th class="p-3">Alumno</th>
                        <th class="p-3">Estado</th>
                        <th class="p-3">Responsable</th>
                        <th class="p-3">Primer contacto</th>
                        <th class="p-3">Actividad</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="caso in casos.data" :key="caso.id" class="border-b border-borde/60">
                        <td class="p-3 align-top">
                            <p class="font-mono text-xs">{{ caso.folio }}</p>
                            <PildoraEstado
                                :texto="caso.prioridad"
                                :color="colorPermanencia(COLOR_PRIORIDAD[caso.prioridad])"
                                class="mt-1"
                            />
                        </td>
                        <td class="p-3 align-top">
                            <p class="font-medium">{{ caso.alumno ?? '—' }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ caso.matricula }}
                                <span v-if="caso.programa"> · {{ caso.programa }}</span>
                                <span v-if="caso.campus"> · {{ caso.campus }}</span>
                            </p>
                        </td>
                        <td class="p-3 align-top">
                            <PildoraEstado
                                :texto="caso.estado_etiqueta"
                                :color="colorPermanencia(caso.estado_color)"
                                sin-capitalizar
                            />
                            <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ caso.dias_abierto }} día{{ caso.dias_abierto === 1 ? '' : 's' }} abierto
                            </p>
                        </td>
                        <td class="p-3 align-top">
                            <span v-if="caso.responsable">{{ caso.responsable }}</span>
                            <span v-else :style="{ color: 'var(--color-ambar)' }">Sin asignar</span>
                        </td>
                        <td class="p-3 align-top">
                            <!--
                                Tres ramas y no dos. Un caso con contacto hecho, uno
                                dentro de plazo y uno vencido son tres situaciones
                                distintas, y con dos ramas la primera se confunde con
                                la segunda: se leería «en plazo» sobre algo que ya se
                                atendió.
                            -->
                            <template v-if="caso.primer_contacto_en">
                                <span :style="{ color: 'var(--color-verde)' }">Hecho</span>
                                <p v-if="caso.tardanza_primer_contacto" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    a {{ caso.tardanza_primer_contacto === '1 día' ? 'el' : 'los' }}
                                    {{ caso.tardanza_primer_contacto }} de abrirse
                                </p>
                            </template>
                            <template v-else-if="caso.sla_vencido">
                                <span :style="{ color: 'var(--color-rojo)' }">Fuera de plazo</span>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    vencía el {{ caso.sla_vence_en }}
                                </p>
                            </template>
                            <template v-else-if="caso.sla_vence_en">
                                <span>Pendiente</span>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                    antes del {{ caso.sla_vence_en }}
                                </p>
                            </template>
                            <span v-else :style="{ color: 'var(--color-suave)' }">Sin plazo fijado</span>
                        </td>
                        <td class="p-3 align-top">
                            <p>
                                {{ caso.intervenciones ?? 0 }}
                                {{ (caso.intervenciones ?? 0) === 1 ? 'intervención' : 'intervenciones' }}
                            </p>
                            <p
                                v-if="(caso.tareas_pendientes ?? 0) > 0"
                                class="text-xs"
                                :style="{ color: 'var(--color-suave)' }"
                            >
                                {{ caso.tareas_pendientes }} tarea{{ caso.tareas_pendientes === 1 ? '' : 's' }} pendiente{{ caso.tareas_pendientes === 1 ? '' : 's' }}
                            </p>
                        </td>
                        <td class="p-3 text-right align-top">
                            <Link
                                :href="`/permanencia/casos/${caso.id}`"
                                class="rounded-lg border border-borde px-3 py-1.5 text-sm"
                            >
                                Abrir
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="casos.data.length === 0">
                        <td colspan="7" class="p-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                            <!--
                                El vacío se explica: sin esto se lee como «no hay
                                nadie a quien acompañar», que es la peor conclusión
                                que este módulo puede inducir.
                            -->
                            No hay casos con estos filtros. Los casos se abren desde una señal ya
                            validada, en la bandeja de
                            <Link href="/permanencia/alertas" class="underline">Alertas</Link>.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Paginacion
            v-if="casos.links"
            class="mt-4"
            :enlaces="casos.links"
            :total="casos.total"
            :desde="casos.from"
            :hasta="casos.to"
        />
    </AppLayout>
</template>
