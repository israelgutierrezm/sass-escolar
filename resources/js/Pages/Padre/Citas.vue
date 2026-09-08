<script setup lang="ts">
/**
 * Portal de la familia: pedir cita con los docentes del hijo. Se elige un
 * docente, una de sus ventanas de atención, una fecha y un hueco; el servidor
 * valida todo (que el docente le dé clase al hijo, que el hueco exista y no esté
 * ocupado). La lista de abajo muestra las citas y deja cancelar las activas.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';

interface Ventana { id: number; dia_semana: number; hora_inicio: string; hora_fin: string; modalidad: string; duracion_min: number; lugar: string | null }
interface Docente { persona_id: number; nombre: string; materias: string[]; ventanas: Ventana[] }
interface Cita {
    id: number; docente: string | null; inicio: string | null; fin: string | null;
    modalidad: string; motivo: string; lugar: string | null; estado: string;
    respuesta: string | null; ya_paso: boolean;
}

const props = defineProps<{
    hijo: { id: number; nombre: string };
    docentes: Docente[];
    modalidades: Record<string, string>;
    citas: Cita[];
}>();

const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
const ETIQUETAS: Record<string, string> = { solicitada: 'Solicitada', confirmada: 'Confirmada', rechazada: 'Rechazada', cancelada: 'Cancelada', realizada: 'Realizada', no_asistio: 'No asistió' };

const docenteSel = ref<number | null>(null);
const ventanaSel = ref<number | null>(null);

const docente = computed(() => props.docentes.find((d) => d.persona_id === docenteSel.value) ?? null);
const ventana = computed(() => docente.value?.ventanas.find((v) => v.id === ventanaSel.value) ?? null);

function minutos(hhmm: string): number {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + (m || 0);
}
function comoHora(min: number): string {
    return String(Math.floor(min / 60)).padStart(2, '0') + ':' + String(min % 60).padStart(2, '0');
}

// Las próximas 6 fechas que caen en el día de la ventana.
const fechas = computed(() => {
    if (!ventana.value) return [] as { valor: string; etiqueta: string }[];
    const out: { valor: string; etiqueta: string }[] = [];
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    for (let i = 0; i < 60 && out.length < 6; i++) {
        const d = new Date(hoy);
        d.setDate(hoy.getDate() + i);
        const iso = d.getDay() === 0 ? 7 : d.getDay();
        if (iso === ventana.value.dia_semana) {
            const valor = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
            out.push({ valor, etiqueta: d.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' }) });
        }
    }
    return out;
});

// Los huecos dentro de la ventana.
const horas = computed(() => {
    if (!ventana.value) return [] as string[];
    const ini = minutos(ventana.value.hora_inicio);
    const fin = minutos(ventana.value.hora_fin);
    const dur = ventana.value.duracion_min;
    const out: string[] = [];
    for (let m = ini; m + dur <= fin; m += dur) out.push(comoHora(m));
    return out;
});

const form = useForm({ disponibilidad_id: null as number | null, fecha: '', hora_inicio: '', motivo: '' });

function elegirVentana(id: number): void {
    ventanaSel.value = id;
    form.disponibilidad_id = id;
    form.fecha = '';
    form.hora_inicio = '';
}

function solicitar(): void {
    form.post(`/mis-hijos/${props.hijo.id}/citas`, {
        preserveScroll: true,
        onSuccess: () => { form.reset(); ventanaSel.value = null; docenteSel.value = null; },
    });
}

const activas = computed(() => props.citas.filter((c) => ['solicitada', 'confirmada'].includes(c.estado) && !c.ya_paso));
const otras = computed(() => props.citas.filter((c) => !(['solicitada', 'confirmada'].includes(c.estado) && !c.ya_paso)));

function cancelar(c: Cita): void {
    const motivo = prompt('¿Por qué cancelas la cita?');
    if (!motivo || !motivo.trim()) return;
    router.post(`/mis-hijos/citas/${c.id}/cancelar`, { respuesta: motivo }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Citas con docentes" />

    <AppLayout titulo="Citas con docentes">
        <div class="mx-auto max-w-2xl space-y-4">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Pide una reunión con los docentes de {{ hijo.nombre }}. Ellos la confirman o te proponen otra hora.
            </p>

            <!-- Pedir una cita -->
            <section class="tarjeta p-6">
                <h2 class="text-base font-semibold">Pedir una cita</h2>

                <p v-if="!docentes.length" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no aparecen docentes para tu hijo. Aparecen cuando está inscrito en materias con docente asignado.
                </p>

                <template v-else>
                    <label class="mt-3 block text-sm">
                        <span class="mb-1 block font-medium">Docente</span>
                        <select v-model.number="docenteSel" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" @change="ventanaSel = null">
                            <option :value="null">Elige…</option>
                            <option v-for="d in docentes" :key="d.persona_id" :value="d.persona_id">{{ d.nombre }}<span v-if="d.materias.length"> — {{ d.materias.join(', ') }}</span></option>
                        </select>
                    </label>

                    <div v-if="docente" class="mt-3">
                        <p class="mb-1 text-sm font-medium">Horario de atención</p>
                        <p v-if="!docente.ventanas.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">Este docente todavía no publicó horarios de atención.</p>
                        <div v-else class="flex flex-wrap gap-2">
                            <button
                                v-for="v in docente.ventanas" :key="v.id" type="button"
                                class="rounded-lg border px-3 py-1.5 text-sm"
                                :style="ventanaSel === v.id ? { borderColor: 'var(--color-acento)', color: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 8%, transparent)' } : { borderColor: 'var(--color-borde)' }"
                                @click="elegirVentana(v.id)"
                            >{{ DIAS[v.dia_semana] }} {{ v.hora_inicio }}–{{ v.hora_fin }} · {{ modalidades[v.modalidad] }}</button>
                        </div>
                    </div>

                    <form v-if="ventana" class="mt-3 space-y-3" @submit.prevent="solicitar">
                        <div class="flex flex-wrap gap-3">
                            <label class="min-w-0 flex-1 text-sm">
                                <span class="mb-1 block font-medium">Fecha</span>
                                <select v-model="form.fecha" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                    <option value="">Elige…</option>
                                    <option v-for="f in fechas" :key="f.valor" :value="f.valor">{{ f.etiqueta }}</option>
                                </select>
                            </label>
                            <label class="text-sm">
                                <span class="mb-1 block font-medium">Hora</span>
                                <select v-model="form.hora_inicio" required class="rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                                    <option value="">Elige…</option>
                                    <option v-for="h in horas" :key="h" :value="h">{{ h }}</option>
                                </select>
                            </label>
                        </div>
                        <label class="block text-sm">
                            <span class="mb-1 block font-medium">¿Para qué quieres verte?</span>
                            <textarea v-model="form.motivo" required rows="2" maxlength="500" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" placeholder="El motivo, para que el docente sepa si es urgente…" />
                            <span v-if="form.errors.motivo" class="mt-1 block text-xs text-red-600">{{ form.errors.motivo }}</span>
                        </label>
                        <p v-if="ventana.lugar" class="text-xs" :style="{ color: 'var(--color-suave)' }">Lugar: {{ ventana.lugar }}</p>
                        <button type="submit" :disabled="form.processing || !form.fecha || !form.hora_inicio" class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)' }">Solicitar</button>
                    </form>
                </template>
            </section>

            <!-- Mis citas activas -->
            <section v-if="activas.length" class="tarjeta overflow-hidden">
                <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <h2 class="text-base font-semibold">Tus citas</h2>
                </header>
                <ul class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                    <li v-for="c in activas" :key="c.id" class="px-6 py-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ c.inicio }}–{{ c.fin }} · {{ c.docente }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: `color-mix(in srgb, ${c.estado === 'confirmada' ? '#0d9488' : '#a16207'} 14%, transparent)`, color: c.estado === 'confirmada' ? '#0d9488' : '#a16207' }">{{ ETIQUETAS[c.estado] }}</span>
                        </div>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ modalidades[c.modalidad] }}<span v-if="c.lugar"> · {{ c.lugar }}</span></p>
                        <p class="mt-1">{{ c.motivo }}</p>
                        <p v-if="c.respuesta" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">Docente: {{ c.respuesta }}</p>
                        <button type="button" class="mt-2 text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="cancelar(c)">Cancelar</button>
                    </li>
                </ul>
            </section>

            <!-- Historial -->
            <section v-if="otras.length" class="tarjeta overflow-hidden">
                <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <h2 class="text-base font-semibold">Anteriores</h2>
                </header>
                <ul class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                    <li v-for="c in otras" :key="c.id" class="px-6 py-2.5 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ c.inicio }} · {{ c.docente }}</span>
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ ETIQUETAS[c.estado] }}</span>
                        </div>
                        <p v-if="c.respuesta" class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.respuesta }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
