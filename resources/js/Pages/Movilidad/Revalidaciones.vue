<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Revalidacion {
    id: number;
    materia_externa: string;
    calificacion_externa: string | null;
    plan_materia_id: number;
    equivalente: string;
    dictamen: string | null;
    asentada: boolean;
    ciclo: string | null;
    notas: string | null;
    motivo: string | null;
}

const props = defineProps<{
    estancia: {
        id: number;
        quien: string | null;
        matricula: string | null;
        institucion: string | null;
        desde: string | null;
        hasta: string | null;
        concluida: boolean;
        es_saliente: boolean;
    };
    revalidaciones: Revalidacion[];
    materias: { id: number; nombre: string; periodo: number }[];
    dictamenes: { id: number; nombre: string; asienta: boolean }[];
    ciclos: { id: number; clave: string }[];
}>();

const capturando = ref(false);
const dictaminando = ref<Revalidacion | null>(null);

const form = useForm({
    materia_externa: '',
    calificacion_externa: '',
    plan_materia_id: null as number | null,
    calificacion_equivalente: '',
    ciclo_id: null as number | null,
    notas: '',
});

const dictamen = useForm({ dictamen_id: null as number | null });

function guardar(): void {
    form.post(`/movilidad/estancias/${props.estancia.id}/revalidaciones`, {
        preserveScroll: true,
        onSuccess: () => {
            capturando.value = false;
            form.reset();
        },
    });
}

function abrirDictamen(r: Revalidacion): void {
    dictaminando.value = r;
    dictamen.reset();
    dictamen.defaults();
}

function resolver(): void {
    if (dictaminando.value === null) return;

    dictamen.put(`/movilidad/estancias/${props.estancia.id}/revalidaciones/${dictaminando.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            dictaminando.value = null;
        },
    });
}

function revocar(r: Revalidacion): void {
    if (!confirm(
        `Vas a revocar el asiento de «${r.materia_externa}». El renglón queda dado de baja en su `
        + 'historial académico, con su auditoría. ¿Continuar?',
    )) {
        return;
    }

    router.delete(`/movilidad/estancias/${props.estancia.id}/revalidaciones/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Revalidaciones · ${estancia.quien}`" />

    <AppLayout titulo="Revalidaciones">
        <BotonVolver href="/movilidad/convocatorias" texto="Convocatorias" class="mb-4" />

        <section class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ estancia.quien }}</h2>
                    <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                        <span v-if="estancia.matricula" class="font-mono">{{ estancia.matricula }} · </span>
                        {{ estancia.institucion }}
                    </p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Estancia desde el {{ estancia.desde }}
                        <template v-if="estancia.hasta"> hasta el {{ estancia.hasta }}</template>
                    </p>
                </div>

                <button
                    v-if="estancia.es_saliente"
                    type="button"
                    class="shrink-0 rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="capturando = true"
                >
                    Capturar materia
                </button>
            </div>

            <!--
                Las dos condiciones que gobiernan todo el asiento, dichas antes
                de que alguien intente dictaminar.
            -->
            <p
                v-if="!estancia.es_saliente"
                class="mt-3 rounded-lg border px-4 py-2 text-sm"
                :style="{ borderColor: '#d97706', color: '#d97706' }"
            >
                Es una estancia de alguien ENTRANTE: no tiene historial académico aquí, así que no se
                le revalidan materias.
            </p>
            <p
                v-else-if="!estancia.concluida"
                class="mt-3 rounded-lg border px-4 py-2 text-sm"
                :style="{ borderColor: '#d97706', color: '#d97706' }"
            >
                La estancia todavía no está concluida. Se pueden capturar las materias, pero no
                asentarlas: mientras siga en curso, las calificaciones de allá no están cerradas.
            </p>
        </section>

        <TarjetaSeccion titulo="Materias traídas de fuera" sin-relleno>
            <ul v-if="revalidaciones.length">
                <li
                    v-for="r in revalidaciones"
                    :key="r.id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium">{{ r.materia_externa }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                <!-- Lo que dijo el destino, tal cual, junto a lo
                                     que se decidió aquí. -->
                                <template v-if="r.calificacion_externa">
                                    allá: {{ r.calificacion_externa }} ·
                                </template>
                                aquí: <strong>{{ r.equivalente }}</strong>
                                <span v-if="r.ciclo"> · ciclo {{ r.ciclo }}</span>
                            </p>
                            <p v-if="r.notas" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ r.notas }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :style="{
                                    backgroundColor: `color-mix(in srgb, ${r.asentada ? '#16a34a' : '#64748b'} 14%, transparent)`,
                                    color: r.asentada ? '#16a34a' : '#64748b',
                                }"
                            >
                                {{ r.asentada ? 'Asentada' : (r.dictamen ?? '—') }}
                            </span>

                            <button
                                v-if="!r.asentada"
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="abrirDictamen(r)"
                            >
                                Dictaminar
                            </button>
                            <button
                                v-else
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: '#dc2626', color: '#dc2626' }"
                                @click="revocar(r)"
                            >
                                Revocar
                            </button>
                        </div>
                    </div>

                    <!--
                        Por qué no se puede asentar, dicho por su nombre: «no se
                        puede» sin motivo obliga a adivinar.
                    -->
                    <p v-if="r.motivo" class="mt-1 text-xs" :style="{ color: '#d97706' }">
                        {{ r.motivo }}
                    </p>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no se le ha capturado ninguna materia.
            </p>
        </TarjetaSeccion>

        <Modal v-if="capturando" etiqueta="Capturar materia" :formulario="form" @cerrar="capturando = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Materia cursada fuera</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto
                            v-model="form.materia_externa"
                            etiqueta="Cómo se llama allá"
                            requerido
                            :error="form.errors.materia_externa"
                        />
                        <CampoTexto
                            v-model="form.calificacion_externa"
                            etiqueta="Calificación que reportaron"
                            ayuda="Tal cual la dieron: «B+», «16/20»."
                            :error="form.errors.calificacion_externa"
                        />
                    </div>

                    <!--
                        Sólo se ofrecen las que TODAVÍA se le pueden revalidar:
                        las que ya tiene aprobadas no aparecen, para que nadie
                        las elija por error y le regale los créditos dos veces.
                    -->
                    <CampoSelect
                        v-model="form.plan_materia_id"
                        etiqueta="Materia equivalente de su plan"
                        :opciones="materias.map((m) => ({ valor: m.id, texto: `${m.periodo}º · ${m.nombre}` }))"
                        vacio="Selecciona…"
                        ayuda="Sólo aparecen las que aún no tiene aprobadas."
                        :error="form.errors.plan_materia_id"
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto
                            v-model="form.calificacion_equivalente"
                            etiqueta="Calificación equivalente"
                            tipo="number"
                            requerido
                            ayuda="La conversión a nuestra escala es un juicio humano: no hay tabla universal."
                            :error="form.errors.calificacion_equivalente"
                        />
                        <CampoSelect
                            v-model="form.ciclo_id"
                            etiqueta="Ciclo en el que se asienta"
                            :opciones="ciclos.map((c) => ({ valor: c.id, texto: c.clave }))"
                            vacio="Selecciona…"
                            ayuda="Se elige: meter «el actual» la pondría en un semestre en el que no estuvo aquí."
                            :error="form.errors.ciclo_id"
                        />
                    </div>

                    <CampoTextarea v-model="form.notas" etiqueta="Notas" :filas="2" :error="form.errors.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="form.processing" texto="Capturar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="dictaminando" etiqueta="Dictaminar" :formulario="dictamen" @cerrar="dictaminando = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="resolver">
                    <h2 class="text-base font-semibold">{{ dictaminando.materia_externa }}</h2>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Un dictamen que aprueba ESCRIBE en su historial académico, y de ahí sale su
                        certificado. Para corregirlo después hay que revocarlo.
                    </p>

                    <CampoSelect
                        v-model="dictamen.dictamen_id"
                        etiqueta="Dictamen"
                        :opciones="dictamenes.map((d) => ({
                            valor: d.id,
                            texto: d.asienta ? `${d.nombre} · asienta en el historial` : d.nombre,
                        }))"
                        vacio="Selecciona…"
                        :error="dictamen.errors.dictamen_id"
                    />

                    <p v-if="dictaminando.motivo" class="rounded-lg border px-4 py-2 text-xs" :style="{ borderColor: '#d97706', color: '#d97706' }">
                        Ojo: {{ dictaminando.motivo }}
                    </p>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="dictamen.processing" texto="Dictaminar" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
