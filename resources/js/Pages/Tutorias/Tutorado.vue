<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * Un tutorado: cómo va y qué se ha hablado con él.
 *
 * ── Por qué la bitácora ────────────────────────────────────────────────────
 * Una tutoría sin registro es una plática que se olvida. Lo que la vuelve útil
 * es abrir esta ficha tres meses después y ver que en septiembre se acordó que
 * entregaría el trabajo pendiente y que en octubre seguía sin entregarlo.
 * También protege al tutor: cuando alguien pregunta por qué no se detectó a
 * tiempo un rezago, la bitácora responde.
 */
interface Sesion {
    id: number;
    fecha: string | null;
    modalidad: string;
    motivo: string;
    motivo_clave: string;
    tema: string;
    acuerdos: string | null;
    asistio: boolean;
    confidencial: boolean;
}

const props = defineProps<{
    alumno: { id: number; nombre: string; foto: string | null; matricula: string | null; carreras: string[] };
    estado: { promedio: number | null; reprobadas: number | null };
    sesiones: Sesion[];
    catalogos: {
        motivos: { valor: string; texto: string }[];
        modalidades: { valor: string; texto: string }[];
    };
}>();

const form = useForm({
    // Hoy por omisión: se anota al terminar la sesión, no una semana después.
    fecha: new Date().toISOString().slice(0, 10),
    modalidad: 'presencial',
    motivo: 'seguimiento',
    tema: '',
    acuerdos: '',
    asistio: true,
    confidencial: false,
});

function guardar(): void {
    form.post(`/mis-tutorados/${props.alumno.id}/sesiones`, {
        preserveScroll: true,
        onSuccess: () => form.reset('tema', 'acuerdos'),
    });
}

function iniciales(nombre: string): string {
    return nombre.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');
}

function colorPromedio(p: number | null): string | undefined {
    if (p === null) return undefined;
    if (p < 6) return '#dc2626';
    if (p < 8) return '#d97706';

    return '#16a34a';
}
</script>

<template>
    <Head :title="alumno.nombre" />

    <AppLayout :titulo="alumno.nombre">
        <BotonVolver href="/mis-tutorados" texto="Mis tutorados" class="mb-4" />

        <section class="tarjeta mb-4 p-6">
            <div class="flex flex-wrap items-center gap-5">
                <img v-if="alumno.foto" :src="alumno.foto" alt="" class="h-16 w-16 rounded-full object-cover" />
                <span
                    v-else
                    class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full text-lg font-semibold"
                    :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                >
                    {{ iniciales(alumno.nombre) }}
                </span>

                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-xl font-semibold text-contenido">{{ alumno.nombre }}</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        <span v-if="alumno.matricula" class="font-mono">{{ alumno.matricula }}</span>
                        <span v-if="alumno.carreras.length"> · {{ alumno.carreras.join(' · ') }}</span>
                    </p>
                </div>

                <div class="flex gap-6">
                    <div>
                        <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Promedio</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums" :style="{ color: colorPromedio(estado.promedio) }">
                            {{ estado.promedio ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Reprobadas</p>
                        <p
                            class="mt-1 text-2xl font-semibold tabular-nums"
                            :style="{ color: (estado.reprobadas ?? 0) > 0 ? '#dc2626' : undefined }"
                        >
                            {{ estado.reprobadas ?? 0 }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <TarjetaSeccion
            titulo="Anotar una sesión"
            descripcion="Lo que se habló y en qué quedaron. Los acuerdos son lo que revisarás la próxima vez."
            :icono="ICONOS.documentoTexto"
        >
            <form @submit.prevent="guardar">
                <div class="grid gap-4 sm:grid-cols-3">
                    <CampoTexto v-model="form.fecha" etiqueta="Fecha" tipo="date" requerido :error="form.errors.fecha" />
                    <CampoSelect
                        v-model="form.motivo"
                        etiqueta="Motivo"
                        requerido
                        :opciones="catalogos.motivos.map((m) => ({ valor: m.valor, texto: m.texto }))"
                        :error="form.errors.motivo"
                    />
                    <CampoSelect
                        v-model="form.modalidad"
                        etiqueta="Modalidad"
                        requerido
                        :opciones="catalogos.modalidades.map((m) => ({ valor: m.valor, texto: m.texto }))"
                        :error="form.errors.modalidad"
                    />
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <CampoTextarea v-model="form.tema" etiqueta="Qué se habló" requerido :filas="4" :error="form.errors.tema" />
                    <CampoTextarea v-model="form.acuerdos" etiqueta="Acuerdos" :filas="4" :error="form.errors.acuerdos" />
                </div>

                <!--
                    Si no llegó, la sesión igual se anota: que faltó a tres citas
                    seguidas es información, y no registrarla dejaría la ausencia
                    sin rastro.
                -->
                <div class="mt-4 space-y-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.asistio" type="checkbox" />
                        El alumno asistió
                    </label>

                    <!--
                        Confidencial no es invisible: la sesión sigue contando
                        para el seguimiento —y de constancia de que la diste—,
                        pero lo que se habló no sale de aquí. Para lo que no
                        debería leer nadie más aunque tenga el permiso.
                    -->
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.confidencial" type="checkbox" class="mt-0.5" />
                        <span>
                            Confidencial
                            <span class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">
                                Control escolar verá que la sesión ocurrió, con su fecha y su motivo,
                                pero no lo que anotaste. Úsalo para situaciones personales delicadas.
                            </span>
                        </span>
                    </label>
                </div>

                <BotonPrincipal :procesando="form.processing" texto="Anotar en la bitácora" class="mt-4" />
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion
            titulo="Bitácora"
            :descripcion="sesiones.length === 1 ? '1 sesión registrada.' : `${sesiones.length} sesiones registradas.`"
            :icono="ICONOS.lista"
            sin-relleno
            class="mt-4"
        >
            <ol v-if="sesiones.length">
                <li
                    v-for="s in sesiones"
                    :key="s.id"
                    class="border-t px-6 py-4"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="font-medium tabular-nums">{{ s.fecha }}</span>
                        <span
                            class="rounded-full px-2 py-0.5"
                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                        >
                            {{ s.motivo }}
                        </span>
                        <span :style="{ color: 'var(--color-suave)' }">{{ s.modalidad }}</span>
                        <span v-if="!s.asistio" class="font-medium text-red-600">No asistió</span>
                        <span
                            v-if="s.confidencial"
                            class="rounded-full px-2 py-0.5 font-medium"
                            :style="{ backgroundColor: 'color-mix(in srgb, #7c3aed 12%, transparent)', color: '#7c3aed' }"
                            title="Sólo tú puedes leer lo que anotaste en esta sesión"
                        >
                            Confidencial
                        </span>
                    </div>

                    <p class="mt-2 whitespace-pre-line text-sm">{{ s.tema }}</p>

                    <!--
                        Los acuerdos se destacan: son lo único de la sesión que
                        hay que revisar la próxima vez, y enterrados en el mismo
                        párrafo que el resto no se encuentran.
                    -->
                    <p
                        v-if="s.acuerdos"
                        class="mt-2 whitespace-pre-line rounded-lg border-l-2 px-3 py-2 text-sm"
                        :style="{ borderLeftColor: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 5%, transparent)' }"
                    >
                        <strong class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            Acuerdos
                        </strong>
                        <span class="mt-0.5 block">{{ s.acuerdos }}</span>
                    </p>
                </li>
            </ol>

            <p v-else class="px-6 py-10 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay sesiones anotadas con este alumno.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
