<script setup lang="ts">
/**
 * La ficha del practicante, para su supervisor externo.
 *
 * Reúne lo suyo y nada más: sus horas (para aprobar), sus informes (para
 * revisar) y su evaluación. Las horas usan el MISMO componente que la escuela
 * —`BitacoraDeHoras`, con `puedeCapturar` en falso—, y los envíos van a las
 * mismas rutas de seguimiento, que ya comprueban permiso y alcance. No hay
 * cartera, ni calificaciones, ni papeles de admisión: no son asunto suyo.
 */
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BitacoraDeHoras from '@/Components/BitacoraDeHoras.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Nivel { id: number; titulo: string; puntos: number }
interface Criterio { id: number; titulo: string; niveles: Nivel[] }
interface Rubrica { id: number; nombre: string; total: number; criterios: Criterio[] }

const props = defineProps<{
    expediente: any;
    rubricas: Rubrica[];
    origenesEvaluacion: { valor: string; texto: string }[];
    puedeAprobarHoras: boolean;
    puedeRevisarInformes: boolean;
}>();

const errores = ref<Record<string, string>>({});
const procesando = ref(false);

const revisandoInforme = ref<any | null>(null);
const retro = ref('');

const evaluando = ref(false);
const evaluacion = ref<{ origen: string; rubrica_id: number | null; niveles: Record<number, number>; comentarios: string }>({
    origen: '',
    rubrica_id: null,
    niveles: {},
    comentarios: '',
});

const rubricaElegida = computed(() => props.rubricas.find((r) => r.id === evaluacion.value.rubrica_id) ?? null);

/* Una evaluación por origen: se ofrecen sólo los que nadie capturó aún. */
const origenesLibres = computed(() => {
    const puestos = props.expediente.evaluaciones.map((e: any) => e.origen);

    return props.origenesEvaluacion.filter((o) => !puestos.includes(o.valor));
});

function sembrar(): void {
    revisandoInforme.value = null;
    evaluando.value = false;
    retro.value = '';
    evaluacion.value = { origen: '', rubrica_id: null, niveles: {}, comentarios: '' };
    errores.value = {};
}

function revisarInforme(aceptado: boolean): void {
    if (revisandoInforme.value === null) return;

    procesando.value = true;

    router.post(
        `/procesos/expedientes/${props.expediente.id}/informes/${revisandoInforme.value.id}/revisar`,
        { aceptado, retroalimentacion: retro.value || null },
        {
            preserveScroll: true,
            onError: (e) => (errores.value = e),
            onSuccess: () => sembrar(),
            onFinish: () => (procesando.value = false),
        },
    );
}

function guardarEvaluacion(): void {
    procesando.value = true;

    router.post(`/procesos/expedientes/${props.expediente.id}/evaluaciones`, { ...evaluacion.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => sembrar(),
        onFinish: () => (procesando.value = false),
    });
}
</script>

<template>
    <Head :title="`Practicante · ${expediente.alumno ?? ''}`" />

    <AppLayout :titulo="expediente.alumno ?? 'Practicante'">
        <div class="mx-auto max-w-4xl space-y-4">
            <a href="/supervision" class="text-sm underline" :style="{ color: 'var(--color-suave)' }">← Mis practicantes</a>

            <TarjetaSeccion titulo="El proceso">
                <template #insignia>
                    <PildoraEstado :texto="expediente.estado_texto" :color="expediente.estado_color" sin-capitalizar />
                </template>

                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Programa</dt><dd>{{ expediente.programa ?? '—' }}</dd></div>
                    <div><dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Proceso</dt><dd>{{ expediente.tipo ?? '—' }}</dd></div>
                    <div><dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Organización</dt><dd>{{ expediente.organizacion ?? '—' }}</dd></div>
                    <div><dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Plaza / modalidad</dt><dd>{{ [expediente.plaza, expediente.modalidad].filter(Boolean).join(' · ') || '—' }}</dd></div>
                    <div><dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Periodo</dt><dd>{{ expediente.fecha_inicio ?? '?' }} → {{ expediente.fecha_fin_programada ?? '?' }}</dd></div>
                    <div><dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Regla</dt><dd>{{ expediente.regla ?? '—' }}</dd></div>
                </dl>
            </TarjetaSeccion>

            <BitacoraDeHoras
                :expediente-id="expediente.id"
                :horas="expediente.horas"
                :puede-capturar="false"
                :puede-revisar="puedeAprobarHoras"
            />

            <TarjetaSeccion titulo="Informes">
                <ul v-if="expediente.informes.length" class="space-y-2">
                    <li
                        v-for="i in expediente.informes"
                        :key="i.id"
                        class="flex flex-wrap items-start justify-between gap-2 border-b pb-2 text-sm last:border-0"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <div class="min-w-0">
                            <span class="font-medium">{{ i.tipo }}<span v-if="i.numero > 1"> n.º {{ i.numero }}</span></span>
                            <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                <template v-if="i.fecha_limite">Para el {{ i.fecha_limite }}</template>
                                <template v-else>Sin fecha límite</template>
                                <span v-if="i.entregado_en"> · entregado el {{ i.entregado_en }}</span>
                                <span v-if="i.tarde" :style="{ color: '#b45309' }"> · tarde</span>
                                <span v-else-if="i.vencido" :style="{ color: '#b91c1c' }"> · vencido</span>
                            </span>
                            <span v-if="i.retroalimentacion" class="mt-0.5 block text-xs" :style="{ color: '#b45309' }">{{ i.retroalimentacion }}</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <a v-if="i.nombre_original" class="underline" :href="`/procesos/expedientes/${expediente.id}/informes/${i.id}/archivo`">Ver</a>
                            <button
                                v-if="puedeRevisarInformes && i.entregado_en"
                                type="button"
                                class="underline"
                                @click="revisandoInforme = i; retro = i.retroalimentacion ?? ''"
                            >Revisar</button>
                            <PildoraEstado :texto="i.estado_texto" :color="i.estado === 'aceptado' ? '#16a34a' : (i.estado === 'rechazado' ? '#b91c1c' : (i.estado === 'entregado' ? '#0284c7' : '#64748b'))" sin-capitalizar />
                        </div>
                    </li>
                </ul>

                <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    No hay informes que revisar.
                </p>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Evaluación">
                <template #insignia>
                    <button
                        v-if="puedeRevisarInformes && origenesLibres.length"
                        type="button"
                        class="rounded-lg border border-borde px-3 py-1 text-xs"
                        @click="evaluando = true"
                    >Capturar una</button>
                </template>

                <ul v-if="expediente.evaluaciones.length" class="space-y-3">
                    <li v-for="ev in expediente.evaluaciones" :key="ev.id" class="border-b pb-2 text-sm last:border-0" :style="{ borderColor: 'var(--color-borde)' }">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ ev.origen_texto }}</span>
                            <span v-if="ev.puntaje !== null" class="tabular-nums">{{ ev.puntaje }}<span v-if="ev.total"> de {{ ev.total }}</span></span>
                        </div>
                        <p v-if="ev.rubrica" class="mt-0.5 text-xs" :style="{ color: 'var(--color-suave)' }">Con «{{ ev.rubrica }}» · {{ ev.firmada_en }}</p>
                        <ul v-if="ev.respuestas?.length" class="mt-1 space-y-0.5">
                            <li v-for="(r, idx) in ev.respuestas" :key="idx" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ r.criterio }}: <strong>{{ r.nivel }}</strong> ({{ r.puntos }} de {{ r.maximo }})
                            </li>
                        </ul>
                        <p v-if="ev.comentarios" class="mt-1 text-xs">{{ ev.comentarios }}</p>
                    </li>
                </ul>

                <p v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">Todavía ninguna.</p>
            </TarjetaSeccion>
        </div>

        <Modal v-if="revisandoInforme" etiqueta="Revisar informe" ancho="max-w-lg" @cerrar="revisandoInforme = null">
            <template #default="{ cerrar }">
                <div class="space-y-4 p-6">
                    <h2 class="text-base font-semibold">{{ revisandoInforme.tipo }}</h2>
                    <a v-if="revisandoInforme.nombre_original" class="text-sm underline" :href="`/procesos/expedientes/${expediente.id}/informes/${revisandoInforme.id}/archivo`">Ver el archivo entregado</a>

                    <CampoTextarea
                        v-model="retro"
                        etiqueta="Retroalimentación"
                        :filas="3"
                        ayuda="Para devolverlo hace falta decir qué corregir. Aceptarlo no la exige."
                        :error="errores.retroalimentacion"
                    />

                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Aceptar" icono="crear" @click="revisarInforme(true)" />
                        <button
                            type="button"
                            class="rounded-lg border border-borde px-4 py-2 text-sm disabled:opacity-50"
                            :disabled="retro.trim() === ''"
                            @click="revisarInforme(false)"
                        >Devolver para corregir</button>
                        <button type="button" class="text-sm underline" @click="cerrar">Cancelar</button>
                    </div>
                </div>
            </template>
        </Modal>

        <Modal v-if="evaluando" etiqueta="Capturar evaluación" ancho="max-w-2xl" @cerrar="evaluando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarEvaluacion">
                    <h2 class="text-base font-semibold">Capturar una evaluación</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="evaluacion.origen"
                            etiqueta="¿De quién?"
                            requerido
                            :opciones="origenesLibres.map((o) => ({ valor: o.valor, texto: o.texto }))"
                            vacio="Elige…"
                            :error="errores.origen"
                        />
                        <CampoSelect
                            v-model="evaluacion.rubrica_id"
                            etiqueta="Rúbrica"
                            :opciones="rubricas.map((r) => ({ valor: r.id, texto: `${r.nombre} (${r.total} pts)` }))"
                            vacio="Sin rúbrica: sólo comentarios"
                            ayuda="Sólo las de la escuela."
                            :error="errores.rubrica_id"
                            @update:model-value="evaluacion.niveles = {}"
                        />
                    </div>

                    <div v-if="rubricaElegida" class="space-y-3">
                        <div v-for="c in rubricaElegida.criterios" :key="c.id">
                            <p class="mb-1 text-sm font-medium">{{ c.titulo }}</p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="n in c.niveles"
                                    :key="n.id"
                                    type="button"
                                    class="rounded-lg border px-3 py-1.5 text-xs"
                                    :class="evaluacion.niveles[c.id] === n.id ? 'elegido-acento' : ''"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                    @click="evaluacion.niveles = { ...evaluacion.niveles, [c.id]: n.id }"
                                >{{ n.titulo }} · {{ n.puntos }}</button>
                            </div>
                        </div>
                    </div>

                    <CampoTextarea v-model="evaluacion.comentarios" etiqueta="Comentarios" :filas="3" :error="errores.comentarios" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" texto="Guardar" icono="crear" :deshabilitado="!evaluacion.origen" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
