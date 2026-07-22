<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';

interface DocumentoDoc {
    id: number;
    documento: string | null;
    descripcion: string | null;
    estado_id: number;
    estado: string | null;
    estado_clave: string | null;
    vigencia: string | null;
    vencido: boolean;
    observaciones: string | null;
    subido: string | null;
}

const props = defineProps<{
    docente: Record<string, any>;
    persona: Record<string, any>;
    materias: { id: number; clave_en_plan: string | null; materia: string | null; grupo: string | null; ciclo: string | null; campus: string | null; tipo: string | null }[];
    documentos: DocumentoDoc[];
    estadosDocumento: { id: number; clave: string; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
    tipos: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    sexos: { id: number; nombre: string }[];
    generos: { id: number; nombre: string }[];
    puedeGestionar: boolean;
}>();

const pestana = ref<'materias' | 'documentos' | 'datos'>('materias');

const pendientes = computed(() => props.documentos.filter((d) => d.estado_clave === 'pendiente').length);

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
    clave_profesor: props.docente.clave_profesor ?? '',
    cedula_profesional: props.docente.cedula_profesional ?? '',
    tipo_docente_id: props.docente.tipo_docente_id ?? null,
    situacion_id: props.docente.situacion_id ?? null,
    edicion_contenido: props.docente.edicion_contenido ?? 1,
    campus_ids: (props.docente.campus_ids ?? []) as number[],
});

function guardar(): void {
    form.put(`/escolar/docentes/${props.docente.id}`, { preserveScroll: true });
}

/* Revisión de documentos */
const revisando = ref<number | null>(null);
const formRevision = useForm({ estado_documento_id: null as number | null, observaciones: '' });

function abrirRevision(doc: DocumentoDoc): void {
    revisando.value = revisando.value === doc.id ? null : doc.id;
    formRevision.estado_documento_id = doc.estado_id;
    formRevision.observaciones = doc.observaciones ?? '';
}

function revisar(doc: DocumentoDoc): void {
    formRevision.put(`/escolar/docentes/${props.docente.id}/documentos/${doc.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            revisando.value = null;
            formRevision.reset();
        },
    });
}

function colorEstado(clave: string | null): string {
    return {
        aceptado: 'color-mix(in srgb, #16a34a 18%, transparent)',
        rechazado: 'color-mix(in srgb, #dc2626 18%, transparent)',
    }[clave ?? ''] ?? 'color-mix(in srgb, #f59e0b 18%, transparent)';
}

const esRechazo = computed(
    () => props.estadosDocumento.find((e) => e.id === formRevision.estado_documento_id)?.clave === 'rechazado',
);
</script>

<template>
    <Head :title="persona.nombre ? `${persona.nombre} ${persona.primer_apellido}` : 'Docente'" />

    <AppLayout titulo="Ficha del docente">
        <NavEscolar />

        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ docente.clave_profesor ?? 'sin clave' }}
                    </p>
                    <h2 class="text-lg font-semibold">
                        {{ [persona.nombre, persona.primer_apellido, persona.segundo_apellido].filter(Boolean).join(' ') }}
                    </h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ docente.tipo ?? 'sin tipo' }} · {{ docente.situacion }}
                        <span v-if="docente.campus.length"> · {{ docente.campus.join(', ') }}</span>
                    </p>
                    <p v-if="docente.cedula_profesional" class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                        Cédula {{ docente.cedula_profesional }}
                    </p>
                </div>
                <a href="/escolar/docentes" class="text-sm" :style="{ color: 'var(--color-acento)' }">← Docentes</a>
            </div>
        </section>

        <div class="flex flex-wrap gap-1 border-b" :style="{ borderColor: 'var(--color-borde)' }">
            <button
                v-for="opcion in [
                    { clave: 'materias', texto: `Materias (${materias.length})` },
                    { clave: 'documentos', texto: `Documentos${pendientes ? ` · ${pendientes} por revisar` : ''}` },
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

        <!-- Materias -->
        <section v-if="pestana === 'materias'" class="tarjeta overflow-hidden">
            <table v-if="materias.length" class="w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    <tr>
                        <th class="px-6 py-3 font-medium">Clave</th>
                        <th class="px-4 py-3 font-medium">Materia</th>
                        <th class="px-4 py-3 font-medium">Grupo</th>
                        <th class="px-4 py-3 font-medium">Ciclo</th>
                        <th class="px-4 py-3 font-medium">Papel</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in materias" :key="m.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                        <td class="px-6 py-3 font-mono text-xs">{{ m.clave_en_plan }}</td>
                        <td class="px-4 py-3">{{ m.materia }}</td>
                        <td class="px-4 py-3">{{ m.grupo }}<span v-if="m.campus" class="block text-xs" :style="{ color: 'var(--color-suave)' }">{{ m.campus }}</span></td>
                        <td class="px-4 py-3">{{ m.ciclo }}</td>
                        <td class="px-4 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs capitalize"
                                :style="{
                                    backgroundColor:
                                        m.tipo === 'titular'
                                            ? 'color-mix(in srgb, var(--color-acento) 14%, transparent)'
                                            : 'color-mix(in srgb, #64748b 16%, transparent)',
                                }"
                            >{{ m.tipo }}</span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            <a :href="`/escolar/grupos/${m.id}`" class="text-sm" :style="{ color: 'var(--color-acento)' }">Grupo</a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No tiene materias asignadas. Se asignan desde el detalle de cada grupo.
            </p>
        </section>

        <!-- Documentos -->
        <section v-else-if="pestana === 'documentos'" class="tarjeta overflow-hidden">
            <div class="border-b px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                <h2 class="text-base font-semibold">Expediente</h2>
                <p class="mt-0.5 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Lo carga el docente desde su portal; aquí se acepta o se rechaza. Un rechazo tiene
                    que explicar qué corregir.
                </p>
            </div>

            <ul v-if="documentos.length">
                <li v-for="doc in documentos" :key="doc.id" class="border-t px-6 py-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <div>
                            <p class="font-medium">{{ doc.documento }}</p>
                            <p v-if="doc.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ doc.descripcion }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                Subido {{ doc.subido }}
                                <span v-if="doc.vigencia"> · vigencia {{ doc.vigencia }}</span>
                                <span v-if="doc.vencido" class="text-red-600"> · vencido</span>
                            </p>
                            <p v-if="doc.observaciones" class="mt-0.5 text-xs italic text-amber-700">{{ doc.observaciones }}</p>
                        </div>

                        <div class="flex items-center gap-3">
                            <span class="rounded-full px-2 py-0.5 text-xs" :style="{ backgroundColor: colorEstado(doc.estado_clave) }">
                                {{ doc.estado }}
                            </span>
                            <a
                                :href="`/escolar/docentes/${docente.id}/documentos/${doc.id}/descargar`"
                                class="text-sm"
                                :style="{ color: 'var(--color-acento)' }"
                            >
                                Descargar
                            </a>
                            <button
                                v-if="puedeGestionar"
                                type="button"
                                class="text-sm"
                                :style="{ color: 'var(--color-acento)' }"
                                @click="abrirRevision(doc)"
                            >
                                Revisar
                            </button>
                        </div>
                    </div>

                    <div v-if="revisando === doc.id" class="mt-3 grid gap-3 rounded-lg p-3 sm:grid-cols-3" style="background-color: color-mix(in srgb, currentColor 4%, transparent)">
                        <CampoSelect
                            v-model="formRevision.estado_documento_id"
                            etiqueta="Estado"
                            :opciones="estadosDocumento.map((e) => ({ valor: e.id, texto: e.nombre }))"
                            :error="formRevision.errors.estado_documento_id"
                        />
                        <CampoTexto
                            v-model="formRevision.observaciones"
                            etiqueta="Observaciones"
                            :marcador="esRechazo ? 'Qué debe corregir…' : 'Opcional'"
                            :error="formRevision.errors.observaciones"
                            :ayuda="esRechazo ? 'Obligatorio al rechazar.' : undefined"
                        />
                        <div class="flex items-end gap-2">
                            <button
                                type="button"
                                :disabled="formRevision.processing"
                                class="rounded-lg px-3 py-2 text-sm font-medium disabled:opacity-50"
                                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                                @click="revisar(doc)"
                            >
                                Guardar
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-2 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="revisando = null"
                            >
                                Cancelar
                            </button>
                        </div>
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                El docente no ha cargado documentos todavía.
            </p>
        </section>

        <!-- Datos -->
        <form v-else class="tarjeta p-6" @submit.prevent="guardar">
            <h2 class="text-base font-semibold">Identidad</h2>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <CampoTexto v-model="form.nombre" etiqueta="Nombre(s)" requerido :error="form.errors.nombre" :deshabilitado="!puedeGestionar" />
                <CampoTexto v-model="form.primer_apellido" etiqueta="Primer apellido" requerido :error="form.errors.primer_apellido" :deshabilitado="!puedeGestionar" />
                <CampoTexto v-model="form.segundo_apellido" etiqueta="Segundo apellido" :error="form.errors.segundo_apellido" :deshabilitado="!puedeGestionar" />

                <CampoTexto v-model="form.curp" etiqueta="CURP" mono :error="form.errors.curp" :deshabilitado="!puedeGestionar" />
                <CampoTexto v-model="form.rfc" etiqueta="RFC" mono :error="form.errors.rfc" :deshabilitado="!puedeGestionar" />
                <CampoTexto v-model="form.fecha_nacimiento" etiqueta="Fecha de nacimiento" tipo="date" :error="form.errors.fecha_nacimiento" :deshabilitado="!puedeGestionar" />

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
                <div></div>

                <CampoTexto v-model="form.email" etiqueta="Correo personal" tipo="email" :error="form.errors.email" :deshabilitado="!puedeGestionar" />
                <CampoTexto v-model="form.correo_institucional" etiqueta="Correo institucional" tipo="email" :error="form.errors.correo_institucional" :deshabilitado="!puedeGestionar" />
                <CampoTexto v-model="form.celular" etiqueta="Celular" :error="form.errors.celular" :deshabilitado="!puedeGestionar" />
            </div>

            <h2 class="mt-8 text-base font-semibold">Registro docente</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Esto es lo que el docente ve de solo lectura en su portal.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <CampoTexto v-model="form.clave_profesor" etiqueta="Clave de profesor" mono :error="form.errors.clave_profesor" :deshabilitado="!puedeGestionar" />
                <CampoTexto v-model="form.cedula_profesional" etiqueta="Cédula profesional" mono :error="form.errors.cedula_profesional" :deshabilitado="!puedeGestionar" />
                <CampoSelect
                    v-model="form.tipo_docente_id"
                    etiqueta="Tipo de docente"
                    :opciones="tipos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                    vacio="Sin especificar"
                    :error="form.errors.tipo_docente_id"
                />
                <CampoSelect
                    v-model="form.situacion_id"
                    etiqueta="Situación"
                    requerido
                    :opciones="situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    :error="form.errors.situacion_id"
                />
                <CampoSelect
                    v-model="form.edicion_contenido"
                    etiqueta="Edición de contenido"
                    :opciones="[
                        { valor: 0, texto: 'Ninguna' },
                        { valor: 1, texto: 'Solo sus grupos' },
                        { valor: 2, texto: 'Todos los grupos' },
                    ]"
                    :error="form.errors.edicion_contenido"
                />
            </div>

            <div class="mt-5">
                <CampoCasillas
                    v-model="form.campus_ids"
                    etiqueta="Campus donde imparte"
                    :opciones="campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                    :error="form.errors.campus_ids"
                />
            </div>

            <button
                v-if="puedeGestionar"
                type="submit"
                :disabled="form.processing"
                class="mt-6 rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
            >
                {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
            </button>
            <p v-else class="mt-6 text-sm" :style="{ color: 'var(--color-suave)' }">
                Solo consulta: no tienes permiso para gestionar docentes.
            </p>
        </form>
    </AppLayout>
</template>
