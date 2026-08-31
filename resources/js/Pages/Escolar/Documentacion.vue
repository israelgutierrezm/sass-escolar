<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';

/**
 * Control de documentación: el expediente de la escuela visto al revés.
 *
 * ── La tabla contesta de un vistazo, sin abrir nada ────────────────────────
 * Una fila por documento, con cuántos lo tienen, cuántos esperan revisión y a
 * cuántos les falta. Ésa es la pregunta que trae a alguien aquí; abrir el
 * detalle es el segundo paso, no el primero.
 *
 * ── Y las cifras son PULSABLES ─────────────────────────────────────────────
 * Cada número lleva a la lista de quiénes son. Una cifra que no se puede abrir
 * obliga a salir a filtrar el listado de alumnos a ojo, que es exactamente el
 * trabajo que esta pantalla venía a quitar.
 *
 * ── Los filtros viajan en la URL ───────────────────────────────────────────
 * El universo puede ser de miles y las cuentas las hace la base: filtrar en el
 * navegador exigiría traerse la escuela entera. A cambio, un filtro puesto se
 * puede compartir por enlace y sobrevive a recargar.
 */
interface Documento {
    id: number;
    nombre: string;
    obligatorio: boolean;
    total: number;
    entregados: number;
    aceptados: number;
    pendientes: number;
    rechazados: number;
    vencidos: number;
    faltan: number;
}

const props = defineProps<{
    ambito: string;
    ambitos: Record<string, string>;
    estados: Record<string, string>;
    filtros: {
        documento_id: number | null;
        estado: string;
        campus_id: number | null;
        programa_academico_id: number | null;
        solo_activos: boolean;
    };
    total: number;
    documentos: Documento[];
    enFoco: Documento | null;
    personas: { id: number; enlace_id: number; referencia: string | null; nombre: string; vigencia: string | null; observaciones: string | null }[] | null;
    campus: { id: number; nombre: string }[];
    programas: { id: number; nombre: string }[];
    base: Record<string, string>;
}>();

const ambito = ref(props.ambito);
const campusId = ref(props.filtros.campus_id);
const programaId = ref(props.filtros.programa_academico_id);
const soloActivos = ref(props.filtros.solo_activos);

/** Sólo los alumnos tienen programa y situación: a un tutor no se le aplican. */
const esAlumno = computed(() => ambito.value === 'alumno');

function ir(extra: Record<string, unknown> = {}): void {
    router.get('/documentacion', {
        ambito: ambito.value,
        campus_id: campusId.value ?? undefined,
        programa_academico_id: esAlumno.value ? (programaId.value ?? undefined) : undefined,
        solo_activos: soloActivos.value ? undefined : '0',
        ...extra,
    }, { preserveState: false, preserveScroll: true });
}

/*
 * Cambiar de ámbito SUELTA el documento y el programa elegidos: los tipos de
 * documento son otros y el id que venía en la URL no existe en el ámbito nuevo
 * —el detalle saldría vacío sin decir por qué—.
 */
watch(ambito, () => {
    programaId.value = null;
    ir({ documento_id: undefined, estado: undefined });
});

watch([campusId, programaId, soloActivos], () => ir({
    documento_id: props.filtros.documento_id ?? undefined,
    estado: props.filtros.estado,
}));

function abrir(doc: Documento, estado: string): void {
    ir({ documento_id: doc.id, estado });
}

function cerrar(): void {
    ir({ documento_id: undefined, estado: undefined });
}

/** El porcentaje que ya entregó, para la barra. */
function avance(doc: Documento): number {
    return doc.total === 0 ? 0 : Math.round((doc.entregados / doc.total) * 100);
}

const COLORES: Record<string, string> = {
    aceptados: '#16a34a',
    pendientes: '#f59e0b',
    rechazados: '#dc2626',
    vencidos: '#dc2626',
    faltan: 'var(--color-suave)',
};

/** El enlace a la ficha de quien sea, según el ámbito en el que se esté. */
const fichaDe = (enlaceId: number) => `${props.base[props.ambito]}/${enlaceId}`;
</script>

<template>
    <Head title="Documentación" />

    <AppLayout titulo="Documentación" subtitulo="Qué tiene entregado la escuela y qué le falta">
        <PestanasSeccion />

        <!-- Filtros -->
        <section class="tarjeta mt-4 grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <CampoSelect
                v-model="ambito"
                etiqueta="A quién se le pide"
                :opciones="Object.entries(ambitos).map(([valor, texto]) => ({ valor, texto }))"
            />
            <CampoSelect
                v-model="campusId"
                etiqueta="Campus"
                vacio="Todos"
                :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
            />
            <CampoSelect
                v-if="esAlumno"
                v-model="programaId"
                etiqueta="Programa académico"
                vacio="Todos"
                :opciones="programas.map((p) => ({ valor: p.id, texto: p.nombre }))"
            />
            <div v-if="esAlumno" class="flex items-end">
                <CampoCheckbox v-model="soloActivos" etiqueta="Sólo alumnos activos" />
            </div>
        </section>

        <p class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
            <template v-if="total">
                Se mide contra <strong>{{ total }}</strong>
                {{ ambitos[ambito]?.toLowerCase() }}<span v-if="esAlumno && soloActivos"> activos</span>.
            </template>
            <template v-else>
                No hay {{ ambitos[ambito]?.toLowerCase() }} en este alcance, así que no hay contra qué medir.
            </template>
        </p>

        <!-- El resumen -->
        <section v-if="documentos.length" class="tarjeta mt-4 overflow-x-auto">
            <table class="w-full min-w-[820px] text-sm">
                <thead>
                    <tr class="border-b text-left" :style="{ borderColor: 'var(--color-borde)' }">
                        <th class="px-4 py-3 font-medium">Documento</th>
                        <th class="px-3 py-3 text-right font-medium">Entregados</th>
                        <th class="px-3 py-3 text-right font-medium">Aceptados</th>
                        <th class="px-3 py-3 text-right font-medium">Por validar</th>
                        <th class="px-3 py-3 text-right font-medium">Rechazados</th>
                        <th class="px-3 py-3 text-right font-medium">Vencidos</th>
                        <th class="px-3 py-3 text-right font-medium">Faltan</th>
                        <th class="px-4 py-3 font-medium">Avance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="doc in documentos"
                        :key="doc.id"
                        class="border-b last:border-0"
                        :style="{
                            borderColor: 'var(--color-borde)',
                            backgroundColor: doc.id === filtros.documento_id
                                ? 'color-mix(in srgb, var(--color-acento) 7%, transparent)'
                                : undefined,
                        }"
                    >
                        <td class="px-4 py-3">
                            {{ doc.nombre }}
                            <span v-if="doc.obligatorio" class="ml-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                (obligatorio)
                            </span>
                        </td>

                        <!--
                            Cada cifra abre su lista. El CERO no se puede pulsar:
                            un enlace que lleva a una pantalla vacía se lee como
                            que algo se rompió.
                        -->
                        <td class="px-3 py-3 text-right tabular-nums">{{ doc.entregados }}</td>
                        <td
                            v-for="clave in ['aceptados', 'pendientes', 'rechazados', 'vencidos', 'faltan']"
                            :key="clave"
                            class="px-3 py-3 text-right tabular-nums"
                        >
                            <button
                                v-if="(doc as any)[clave] > 0"
                                type="button"
                                class="underline decoration-dotted underline-offset-2"
                                :style="{ color: COLORES[clave] }"
                                @click="abrir(doc, clave === 'faltan' ? 'falta' : clave.slice(0, -1))"
                            >
                                {{ (doc as any)[clave] }}
                            </button>
                            <span v-else :style="{ color: 'var(--color-suave)' }">0</span>
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-24 overflow-hidden rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                                    <div
                                        class="h-full rounded-full"
                                        :style="{ width: `${avance(doc)}%`, backgroundColor: avance(doc) === 100 ? '#16a34a' : 'var(--color-acento)' }"
                                    />
                                </div>
                                <span class="text-xs tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ avance(doc) }}%</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <p v-else class="tarjeta mt-4 p-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
            La escuela no le pide ningún documento a {{ ambitos[ambito]?.toLowerCase() }}.
            Se configura en Admisiones → Documentos requeridos.
        </p>

        <!-- El detalle: quiénes son -->
        <section v-if="enFoco && personas" class="tarjeta mt-6 overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                <div>
                    <h2 class="text-base font-semibold">{{ enFoco.nombre }}</h2>
                    <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ estados[filtros.estado] }} · {{ personas.length }}
                        {{ personas.length === 1 ? 'persona' : 'personas' }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="(texto, clave) in estados"
                        :key="clave"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :style="clave === filtros.estado
                            ? { borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }
                            : { borderColor: 'var(--color-borde)' }"
                        @click="abrir(enFoco, String(clave))"
                    >
                        {{ texto }}
                    </button>
                    <button type="button" class="ml-1 text-xs underline" :style="{ color: 'var(--color-suave)' }" @click="cerrar">
                        Cerrar
                    </button>
                </div>
            </header>

            <ul v-if="personas.length" class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                <li v-for="p in personas" :key="p.id" class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm">
                    <div class="min-w-0">
                        <p class="font-medium">{{ p.nombre }}</p>
                        <p v-if="p.referencia" class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ p.referencia }}</p>
                        <p v-if="p.observaciones" class="mt-0.5 text-xs italic text-amber-700">{{ p.observaciones }}</p>
                        <p v-if="p.vigencia" class="text-xs" :style="{ color: 'var(--color-suave)' }">Vigencia {{ p.vigencia }}</p>
                    </div>
                    <a :href="fichaDe(p.enlace_id)" class="text-sm" :style="{ color: 'var(--color-acento)' }">
                        Abrir expediente
                    </a>
                </li>
            </ul>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Nadie está en esa situación con este documento.
            </p>
        </section>
    </AppLayout>
</template>
