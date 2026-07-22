<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Renglon {
    id: number;
    clave_en_plan: string | null;
    materia: string | null;
    creditos: number | null;
    ciclo: string | null;
    calificacion: string | null;
    estatus: string | null;
    estatus_clave: string | null;
    tipo_evaluacion: string | null;
    acta_folio: string | null;
    observacion: string | null;
}

const props = defineProps<{
    alumno: Record<string, any>;
    persona: Record<string, any>;
    otrasMatriculas: { id: number; matricula: string; carrera: string | null; estatus: string }[];
    historial: Renglon[];
    resumen: Record<string, any>;
    carga: { ciclo: string; materias: any[] }[];
    situaciones: { id: number; nombre: string }[];
    sexos: { id: number; nombre: string }[];
    generos: { id: number; nombre: string }[];
    puedeEditar: boolean;
}>();

const pestana = ref<'kardex' | 'carga' | 'datos'>('kardex');

const form = useForm({
    nombre: props.persona.nombre ?? '',
    primer_apellido: props.persona.primer_apellido ?? '',
    segundo_apellido: props.persona.segundo_apellido ?? '',
    curp: props.persona.curp ?? '',
    rfc: props.persona.rfc ?? '',
    fecha_nacimiento: props.persona.fecha_nacimiento ?? '',
    sexo_id: props.persona.sexo_id ?? null,
    genero_id: props.persona.genero_id ?? null,
    email: props.persona.email ?? '',
    correo_institucional: props.persona.correo_institucional ?? '',
    celular: props.persona.celular ?? '',
    situacion_id: props.alumno.situacion_id ?? null,
    estatus: props.alumno.estatus ?? 'activo',
    generacion: props.alumno.generacion ?? '',
});

function guardar(): void {
    form.put(`/escolar/alumnos/${props.alumno.id}`, { preserveScroll: true });
}

const avance = computed(() => {
    const total = Number(props.resumen.creditos_del_plan ?? 0);

    if (!total) {
        return null;
    }

    return Math.min(100, Math.round((Number(props.resumen.creditos ?? 0) / total) * 100));
});

function colorCalificacion(r: Renglon): string {
    if (r.estatus_clave === 'aprobada') return '#16a34a';
    if (r.estatus_clave === 'reprobada') return '#dc2626';
    return 'var(--color-suave)';
}
</script>

<template>
    <Head :title="persona.nombre ? `${persona.nombre} ${persona.primer_apellido}` : 'Alumno'" />

    <AppLayout titulo="Expediente del alumno">
        <NavEscolar />

        <!-- Cabecera -->
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">{{ alumno.matricula }}</p>
                    <h2 class="text-lg font-semibold">
                        {{ [persona.nombre, persona.primer_apellido, persona.segundo_apellido].filter(Boolean).join(' ') }}
                    </h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ alumno.carrera }}
                        <span v-if="alumno.plan"> · {{ alumno.plan }}</span>
                        <span v-if="alumno.campus"> · {{ alumno.campus }}</span>
                        <span v-if="alumno.turno"> · {{ alumno.turno }}</span>
                    </p>
                    <p class="mt-1 text-sm">
                        <span class="rounded-full px-2 py-0.5 text-xs capitalize" style="background-color: color-mix(in srgb, #16a34a 14%, transparent)">
                            {{ alumno.estatus }}
                        </span>
                        <span class="ml-2" :style="{ color: 'var(--color-suave)' }">{{ alumno.situacion }}</span>
                        <span v-if="alumno.generacion" :style="{ color: 'var(--color-suave)' }"> · generación {{ alumno.generacion }}</span>
                        <span v-if="alumno.fecha_ingreso" :style="{ color: 'var(--color-suave)' }"> · ingresó {{ alumno.fecha_ingreso }}</span>
                    </p>
                </div>
                <a href="/escolar/alumnos" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Alumnos</a>
            </div>

            <!-- La misma persona puede tener otra carrera en curso -->
            <p v-if="otrasMatriculas.length" class="mt-4 border-t pt-4 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                <span :style="{ color: 'var(--color-suave)' }">Esta persona también está matriculada en:</span>
                <a
                    v-for="otra in otrasMatriculas"
                    :key="otra.id"
                    :href="`/escolar/alumnos/${otra.id}`"
                    class="ml-2"
                    :style="{ color: 'var(--color-acento)' }"
                >
                    {{ otra.carrera }} ({{ otra.matricula }})
                </a>
            </p>
        </section>

        <!-- Resumen académico -->
        <section class="tarjeta p-6">
            <div class="grid gap-4 text-sm sm:grid-cols-5">
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Materias cursadas</p>
                    <p class="mt-0.5 text-xl font-semibold">{{ resumen.materias_cursadas }}</p>
                </div>
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Aprobadas</p>
                    <p class="mt-0.5 text-xl font-semibold text-green-600">{{ resumen.aprobadas }}</p>
                </div>
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Reprobadas</p>
                    <p class="mt-0.5 text-xl font-semibold" :class="resumen.reprobadas ? 'text-red-600' : ''">
                        {{ resumen.reprobadas }}
                    </p>
                </div>
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Promedio</p>
                    <p class="mt-0.5 text-xl font-semibold">{{ resumen.promedio ?? '—' }}</p>
                </div>
                <div>
                    <p :style="{ color: 'var(--color-suave)' }">Créditos</p>
                    <p class="mt-0.5 text-xl font-semibold">
                        {{ resumen.creditos }}<span v-if="resumen.creditos_del_plan" class="text-sm font-normal" :style="{ color: 'var(--color-suave)' }">
                            / {{ resumen.creditos_del_plan }}</span>
                    </p>
                </div>
            </div>

            <div v-if="avance !== null" class="mt-4">
                <div class="h-2 overflow-hidden rounded-full" style="background-color: color-mix(in srgb, currentColor 10%, transparent)">
                    <div class="h-full rounded-full" :style="{ width: `${avance}%`, backgroundColor: 'var(--color-acento)' }"></div>
                </div>
                <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ avance }}% de créditos del plan</p>
            </div>
        </section>

        <!-- Pestañas -->
        <div class="flex flex-wrap gap-1 border-b" :style="{ borderColor: 'var(--color-borde)' }">
            <button
                v-for="opcion in [
                    { clave: 'kardex', texto: 'Kárdex' },
                    { clave: 'carga', texto: 'Carga por ciclo' },
                    { clave: 'datos', texto: 'Datos' },
                ]"
                :key="opcion.clave"
                type="button"
                class="rounded-t-lg px-4 py-2 text-sm"
                :class="pestana === opcion.clave ? 'font-medium' : ''"
                :style="
                    pestana === opcion.clave
                        ? { color: 'var(--color-acento)', borderBottom: '2px solid var(--color-acento)' }
                        : { color: 'var(--color-suave)' }
                "
                @click="pestana = opcion.clave as any"
            >
                {{ opcion.texto }}
            </button>
        </div>

        <!-- Kárdex -->
        <section v-if="pestana === 'kardex'" class="tarjeta overflow-hidden">
            <table v-if="historial.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Materia</th>
                        <th class="px-4 py-3 font-medium">Ciclo</th>
                        <th class="px-4 py-3 font-medium">Tipo</th>
                        <th class="px-4 py-3 font-medium">Calif.</th>
                        <th class="px-4 py-3 font-medium">Estatus</th>
                        <th class="px-6 py-3 font-medium">Acta</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="renglon in historial"
                        :key="renglon.id"
                        class="border-t"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <td class="px-6 py-2 font-mono text-xs">{{ renglon.clave_en_plan }}</td>
                        <td class="px-4 py-2">
                            {{ renglon.materia }}
                            <span v-if="renglon.observacion && renglon.observacion !== 'Sin observación'" class="block text-xs italic" :style="{ color: 'var(--color-suave)' }">
                                {{ renglon.observacion }}
                            </span>
                        </td>
                        <td class="px-4 py-2">{{ renglon.ciclo }}</td>
                        <td class="px-4 py-2 text-xs">{{ renglon.tipo_evaluacion }}</td>
                        <td class="px-4 py-2 font-medium" :style="{ color: colorCalificacion(renglon) }">
                            {{ renglon.calificacion ?? '—' }}
                        </td>
                        <td class="px-4 py-2">{{ renglon.estatus }}</td>
                        <td class="px-6 py-2 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ renglon.acta_folio ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Sin materias asentadas en el kárdex todavía.
            </p>
        </section>

        <!-- Carga por ciclo -->
        <section v-else-if="pestana === 'carga'" class="space-y-4">
            <article v-for="bloque in carga" :key="bloque.ciclo" class="tarjeta overflow-hidden">
                <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <h3 class="text-sm font-semibold">Ciclo {{ bloque.ciclo }} ({{ bloque.materias.length }} materias)</h3>
                </div>
                <ul>
                    <li
                        v-for="materia in bloque.materias"
                        :key="materia.id"
                        class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-2 text-sm"
                        :class="materia.de_baja ? 'opacity-50' : ''"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <span>
                            <span class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ materia.clave_en_plan }}</span>
                            · {{ materia.materia }}
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">grupo {{ materia.grupo }}</span>
                        </span>
                        <span class="flex items-center gap-3">
                            <span
                                v-if="materia.tipo === 'recursamiento'"
                                class="rounded-full px-2 py-0.5 text-xs"
                                style="background-color: color-mix(in srgb, #f59e0b 18%, transparent)"
                            >recursa</span>
                            <span class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ materia.situacion }}</span>
                            <span class="font-medium">{{ materia.calificacion_final ?? '—' }}</span>
                        </span>
                    </li>
                </ul>
            </article>

            <p v-if="!carga.length" class="tarjeta px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No tiene materias inscritas.
            </p>
        </section>

        <!-- Datos -->
        <form v-else class="tarjeta p-6" @submit.prevent="guardar">
            <h2 class="text-base font-semibold">Identidad</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Estos datos son de la PERSONA: corregirlos alcanza también a sus otras matrículas.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <CampoTexto v-model="form.nombre" etiqueta="Nombre(s)" requerido :error="form.errors.nombre" :deshabilitado="!puedeEditar" />
                <CampoTexto v-model="form.primer_apellido" etiqueta="Primer apellido" requerido :error="form.errors.primer_apellido" :deshabilitado="!puedeEditar" />
                <CampoTexto v-model="form.segundo_apellido" etiqueta="Segundo apellido" :error="form.errors.segundo_apellido" :deshabilitado="!puedeEditar" />

                <CampoTexto v-model="form.curp" etiqueta="CURP" mono :error="form.errors.curp" :deshabilitado="!puedeEditar" />
                <CampoTexto v-model="form.rfc" etiqueta="RFC" mono :error="form.errors.rfc" :deshabilitado="!puedeEditar" />
                <CampoTexto v-model="form.fecha_nacimiento" etiqueta="Fecha de nacimiento" tipo="date" :error="form.errors.fecha_nacimiento" :deshabilitado="!puedeEditar" />

                <CampoSelect
                    v-model="form.sexo_id"
                    etiqueta="Sexo"
                    requerido
                    :opciones="sexos.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    vacio="Selecciona…"
                    :error="form.errors.sexo_id"
                />
                <CampoSelect
                    v-model="form.genero_id"
                    etiqueta="Género"
                    :opciones="generos.map((g) => ({ valor: g.id, texto: g.nombre }))"
                    vacio="Sin especificar"
                    :error="form.errors.genero_id"
                />
                <div>
                    <p class="text-sm font-medium">Entidad de nacimiento</p>
                    <p class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ persona.entidad_nacimiento ?? '—' }}
                    </p>
                </div>

                <CampoTexto v-model="form.email" etiqueta="Correo personal" tipo="email" :error="form.errors.email" :deshabilitado="!puedeEditar" />
                <CampoTexto v-model="form.correo_institucional" etiqueta="Correo institucional" tipo="email" :error="form.errors.correo_institucional" :deshabilitado="!puedeEditar" />
                <CampoTexto v-model="form.celular" etiqueta="Celular" :error="form.errors.celular" :deshabilitado="!puedeEditar" />
            </div>

            <h2 class="mt-8 text-base font-semibold">Situación escolar</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Aplica solo a esta matrícula, no a las otras carreras de la persona.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <CampoSelect
                    v-model="form.situacion_id"
                    etiqueta="Situación"
                    requerido
                    :opciones="situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    vacio="Selecciona…"
                    :error="form.errors.situacion_id"
                />
                <CampoSelect
                    v-model="form.estatus"
                    etiqueta="Estatus"
                    requerido
                    :opciones="[
                        { valor: 'activo', texto: 'Activo' },
                        { valor: 'egresado', texto: 'Egresado' },
                        { valor: 'baja', texto: 'Baja' },
                    ]"
                    :error="form.errors.estatus"
                />
                <CampoTexto v-model="form.generacion" etiqueta="Generación" :error="form.errors.generacion" :deshabilitado="!puedeEditar" />
            </div>

            <button
                v-if="puedeEditar"
                type="submit"
                :disabled="form.processing"
                class="mt-6 rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
            >
                {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
            </button>
            <p v-else class="mt-6 text-sm" :style="{ color: 'var(--color-suave)' }">
                Solo consulta: no tienes permiso para editar alumnos.
            </p>
        </form>
    </AppLayout>
</template>
