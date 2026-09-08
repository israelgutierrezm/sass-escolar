<script setup lang="ts">
/**
 * Portal del docente para las citas con familias: sus horarios de atención a
 * padres (aparte de los de dar clase) y las solicitudes de sus alumnos.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';

interface Ventana { id: number; dia_semana: number; hora_inicio: string; hora_fin: string; modalidad: string; duracion_min: number; lugar: string | null }
interface Cita {
    id: number; alumno: string | null; solicitante: string | null;
    inicio: string | null; fin: string | null; modalidad: string; motivo: string;
    lugar: string | null; estado: string; respuesta: string | null; ya_paso: boolean;
}

const props = defineProps<{
    disponibilidad: Ventana[];
    citas: Cita[];
    modalidades: Record<string, string>;
}>();

const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

const solicitadas = computed(() => props.citas.filter((c) => c.estado === 'solicitada'));
const proximas = computed(() => props.citas.filter((c) => c.estado === 'confirmada' && !c.ya_paso));
const porMarcar = computed(() => props.citas.filter((c) => c.estado === 'confirmada' && c.ya_paso));
const historial = computed(() => props.citas.filter((c) => ['rechazada', 'cancelada', 'realizada', 'no_asistio'].includes(c.estado)));

const nueva = useForm({ dia_semana: 1, hora_inicio: '', hora_fin: '', modalidad: 'presencial', duracion_min: 20, lugar: '' });

function agregar(): void {
    nueva.post('/docencia/citas/disponibilidad', { preserveScroll: true, onSuccess: () => nueva.reset() });
}

function quitar(id: number): void {
    if (!confirm('¿Quitar este horario de atención?')) return;
    router.delete(`/docencia/citas/disponibilidad/${id}`, { preserveScroll: true });
}

// Un formulario de respuesta por cita, abierto bajo demanda.
const abierto = reactive<Record<number, string>>({});
const nota = reactive<Record<number, string>>({});

function confirmar(c: Cita): void {
    router.post(`/docencia/citas/${c.id}/confirmar`, { respuesta: nota[c.id] ?? '' }, { preserveScroll: true, onSuccess: () => { delete abierto[c.id]; delete nota[c.id]; } });
}
function enviarMotivo(c: Cita, accion: 'rechazar' | 'cancelar'): void {
    if (!(nota[c.id] ?? '').trim()) return;
    router.post(`/docencia/citas/${c.id}/${accion}`, { respuesta: nota[c.id] }, { preserveScroll: true, onSuccess: () => { delete abierto[c.id]; delete nota[c.id]; } });
}
function marcar(c: Cita, estado: 'realizada' | 'no_asistio'): void {
    router.post(`/docencia/citas/${c.id}/marcar`, { estado }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Citas con familias" />

    <AppLayout titulo="Citas con familias">
        <div class="mx-auto max-w-3xl space-y-4">
            <!-- Horarios de atención -->
            <section class="tarjeta overflow-hidden">
                <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <h2 class="text-base font-semibold">Mis horarios de atención a padres</h2>
                    <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Aparte de tus horas de clase: las familias sólo pueden pedir cita dentro de estas ventanas.
                    </p>
                </header>

                <ul v-if="disponibilidad.length" class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                    <li v-for="v in disponibilidad" :key="v.id" class="flex flex-wrap items-center justify-between gap-2 px-6 py-2.5 text-sm">
                        <span>{{ DIAS[v.dia_semana] }} · {{ v.hora_inicio }}–{{ v.hora_fin }}
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">· {{ modalidades[v.modalidad] }} · citas de {{ v.duracion_min }} min<span v-if="v.lugar"> · {{ v.lugar }}</span></span>
                        </span>
                        <button type="button" class="text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="quitar(v.id)">Quitar</button>
                    </li>
                </ul>
                <p v-else class="px-6 py-4 text-sm" :style="{ color: 'var(--color-suave)' }">Todavía no ofreces horarios de atención.</p>

                <form class="flex flex-wrap items-end gap-2 border-t px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="agregar">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Día</span>
                        <select v-model.number="nueva.dia_semana" class="rounded-lg border px-2 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                            <option v-for="n in 7" :key="n" :value="n">{{ DIAS[n] }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">De</span>
                        <input v-model="nueva.hora_inicio" type="time" required class="rounded-lg border px-2 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">A</span>
                        <input v-model="nueva.hora_fin" type="time" required class="rounded-lg border px-2 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Modalidad</span>
                        <select v-model="nueva.modalidad" class="rounded-lg border px-2 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                            <option v-for="(etq, clave) in modalidades" :key="clave" :value="clave">{{ etq }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Cada</span>
                        <select v-model.number="nueva.duracion_min" class="rounded-lg border px-2 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                            <option v-for="m in [10, 15, 20, 30, 45, 60]" :key="m" :value="m">{{ m }} min</option>
                        </select>
                    </label>
                    <label class="min-w-0 flex-1 text-sm">
                        <span class="mb-1 block font-medium">Lugar (opcional)</span>
                        <input v-model="nueva.lugar" type="text" maxlength="200" class="w-full rounded-lg border px-2 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" placeholder="Sala de maestros, enlace…" />
                    </label>
                    <button type="submit" :disabled="nueva.processing" class="rounded-lg px-4 py-2 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }">Agregar</button>
                </form>
            </section>

            <!-- Solicitudes por responder -->
            <section v-if="solicitadas.length" class="tarjeta overflow-hidden">
                <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <h2 class="text-base font-semibold">Por responder <span class="text-sm font-normal" :style="{ color: 'var(--color-suave)' }">({{ solicitadas.length }})</span></h2>
                </header>
                <ul class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                    <li v-for="c in solicitadas" :key="c.id" class="px-6 py-3 text-sm">
                        <p class="font-medium">{{ c.inicio }}–{{ c.fin }} · {{ c.alumno }}</p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Pide: {{ c.solicitante }} · {{ modalidades[c.modalidad] }}<span v-if="c.lugar"> · {{ c.lugar }}</span></p>
                        <p class="mt-1">{{ c.motivo }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-medium text-white" :style="{ backgroundColor: 'var(--color-acento)' }" @click="confirmar(c)">Confirmar</button>
                            <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :style="{ borderColor: '#b91c1c', color: '#b91c1c' }" @click="abierto[c.id] = abierto[c.id] === 'rechazar' ? '' : 'rechazar'">{{ abierto[c.id] === 'rechazar' ? 'Cerrar' : 'Rechazar' }}</button>
                        </div>
                        <div v-if="abierto[c.id] === 'rechazar'" class="mt-2">
                            <textarea v-model="nota[c.id]" rows="2" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" placeholder="Motivo (puedes sugerir otra hora)…" />
                            <button type="button" :disabled="!(nota[c.id] ?? '').trim()" class="mt-1 rounded-lg px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50" :style="{ backgroundColor: '#b91c1c' }" @click="enviarMotivo(c, 'rechazar')">Enviar rechazo</button>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Próximas confirmadas -->
            <section v-if="proximas.length" class="tarjeta overflow-hidden">
                <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <h2 class="text-base font-semibold">Próximas</h2>
                </header>
                <ul class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                    <li v-for="c in proximas" :key="c.id" class="px-6 py-3 text-sm">
                        <p class="font-medium">{{ c.inicio }}–{{ c.fin }} · {{ c.alumno }}</p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Con: {{ c.solicitante }} · {{ modalidades[c.modalidad] }}<span v-if="c.lugar"> · {{ c.lugar }}</span></p>
                        <p class="mt-1">{{ c.motivo }}</p>
                        <button type="button" class="mt-2 text-xs underline" :style="{ color: 'var(--color-acento)' }" @click="abierto[c.id] = abierto[c.id] === 'cancelar' ? '' : 'cancelar'">{{ abierto[c.id] === 'cancelar' ? 'Cerrar' : 'Cancelar' }}</button>
                        <div v-if="abierto[c.id] === 'cancelar'" class="mt-2">
                            <textarea v-model="nota[c.id]" rows="2" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" placeholder="Motivo de la cancelación…" />
                            <button type="button" :disabled="!(nota[c.id] ?? '').trim()" class="mt-1 rounded-lg px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50" :style="{ backgroundColor: '#b91c1c' }" @click="enviarMotivo(c, 'cancelar')">Cancelar la cita</button>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Por marcar (confirmadas ya pasadas) -->
            <section v-if="porMarcar.length" class="tarjeta overflow-hidden">
                <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <h2 class="text-base font-semibold">¿Qué pasó?</h2>
                </header>
                <ul class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                    <li v-for="c in porMarcar" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 px-6 py-3 text-sm">
                        <span>{{ c.inicio }} · {{ c.alumno }}</span>
                        <span class="flex gap-2">
                            <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }" @click="marcar(c, 'realizada')">Se realizó</button>
                            <button type="button" class="rounded-lg border px-3 py-1.5 text-sm" :style="{ borderColor: 'var(--color-suave)', color: 'var(--color-suave)' }" @click="marcar(c, 'no_asistio')">No asistió</button>
                        </span>
                    </li>
                </ul>
            </section>

            <!-- Historial -->
            <section v-if="historial.length" class="tarjeta overflow-hidden">
                <header class="border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <h2 class="text-base font-semibold">Historial</h2>
                </header>
                <ul class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                    <li v-for="c in historial" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 px-6 py-2.5 text-sm">
                        <span>{{ c.inicio }} · {{ c.alumno }}</span>
                        <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ c.estado }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
