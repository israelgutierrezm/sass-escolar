<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PestanasSeccion from '@/Components/PestanasSeccion.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import PestanasPagina from '@/Components/PestanasPagina.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import CamposIdentidad from '@/Components/CamposIdentidad.vue';
import TitulosDocente from '@/Components/TitulosDocente.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import FormulariosAsignados from '@/Components/FormulariosAsignados.vue';
import EncabezadoPersona from '@/Components/EncabezadoPersona.vue';
import DisponibilidadSemanal from '@/Components/DisponibilidadSemanal.vue';
import AptitudesDocente from '@/Components/AptitudesDocente.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import { ICONOS } from '@/iconos';

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
    generos: { id: number; nombre: string }[];
    entidades: { id: number; nombre: string }[];
    entidadExtranjero: { id: number; nombre: string } | null;
    paises: { id: number; nombre: string }[];
    mexicoId: number | null;
    titulos: { id: number; grado: string; titulo_obtenido: string; cedula: string | null; institucion: string | null; anio: number | null; archivo: string | null }[];
    /** Los formularios que le tocan, de `ResolutorFormularios`. */
    formularios: Record<string, any>[];
    /** El insumo de la generación de horarios: cuándo puede y qué sabe dar. */
    franjas: Record<string, any>[];
    ciclos: { id: number; nombre: string }[];
    asignaturasQuePuedeImpartir: { asignatura_id: number; nombre: string; clave: string; preferencia: number }[];
    catalogoAsignaturas: { id: number; nombre: string; clave: string }[];
    puedeGestionarHorarios: boolean;
    puedeGestionar: boolean;
    suplantable: { usuario_id: number; usuario: string } | null;
}>();

const pestana = ref<'materias' | 'documentos' | 'titulos' | 'formularios' | 'horarios' | 'datos'>('materias');

const pendientes = computed(() => props.documentos.filter((d) => d.estado_clave === 'pendiente').length);

/*
 * Lo obligatorio que le falta de sus formularios, para el número de la pestaña.
 * La pestaña no aparece si no le toca ninguno: una vacía sólo hace preguntarse
 * qué debería haber ahí.
 */
const formulariosPendientes = computed(
    () => props.formularios.filter((f: any) => f.obligatorio && !f.completo).length,
);

const form = useForm({
    nombre: props.persona.nombre ?? '',
    primer_apellido: props.persona.primer_apellido ?? '',
    segundo_apellido: props.persona.segundo_apellido ?? '',
    curp: props.persona.curp ?? '',
    rfc: props.persona.rfc ?? '',
    fecha_nacimiento: props.persona.fecha_nacimiento ?? '',
    genero_id: props.persona.genero_id ?? null,
    entidad_nacimiento_id: props.persona.entidad_nacimiento_id ?? null,
    pais_nacimiento_id: props.persona.pais_nacimiento_id ?? null,
    email: props.persona.email ?? '',
    correo_institucional: props.persona.correo_institucional ?? '',
    celular: props.persona.celular ?? '',
    telefono_local: props.persona.telefono_local ?? '',
    clave_profesor: props.docente.clave_profesor ?? '',
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

// Color SÓLIDO por estado del documento (para PildoraEstado).
function colorEstado(clave: string | null): string {
    return {
        aceptado: '#16a34a',
        rechazado: '#dc2626',
    }[clave ?? ''] ?? '#d97706';
}

const esRechazo = computed(
    () => props.estadosDocumento.find((e) => e.id === formRevision.estado_documento_id)?.clave === 'rechazado',
);

/* Foto de perfil */
const formFoto = useForm({ foto: null as File | null });

// El archivo llega ya elegido: quien lo lee del input es el encabezado.
function subirFoto(archivo: File): void {
    formFoto.foto = archivo;
    formFoto.post(`/personas/${props.docente.id}/foto`, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => formFoto.reset(),
    });
}

function quitarFoto(): void {
    if (!confirm('Quitar la foto?')) return;
    router.delete(`/personas/${props.docente.id}/foto`, { preserveScroll: true });
}

/*
 * "Ver como": entrar con la cuenta de esta persona para reproducir lo que ella
 * ve. Queda registrado en la bitacora, y la banda superior lo recuerda todo el
 * tiempo mientras dure.
 */
function verComo(): void {
    if (!props.suplantable) {
        return;
    }

    if (!confirm(`Vas a entrar como ${props.suplantable.usuario}. Queda registrado quien lo hizo y cuando. Continuar?`)) {
        return;
    }

    router.post(`/suplantar/${props.suplantable.usuario_id}`);
}
</script>

<template>
    <Head :title="persona.nombre ? `${persona.nombre} ${persona.primer_apellido}` : 'Docente'" />

    <!-- «Expediente», no «Ficha»: es la misma palabra con la que se entra desde
         el listado, y la misma pantalla que la del alumno. -->
    <AppLayout titulo="Expediente del docente">
        <PestanasSeccion />

        <section class="tarjeta p-6">
            <BotonVolver href="/escolar/docentes" texto="Docentes" class="mb-4" />

            <EncabezadoPersona
                :persona="{ ...persona, entidad_nacimiento: null }"
                :puede-editar-foto="puedeGestionar"
                :error-foto="formFoto.errors.foto"
                @subir-foto="subirFoto"
                @quitar-foto="quitarFoto"
            >
                <template #identificador>
                    <p class="font-mono text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ docente.clave_profesor ?? 'sin clave' }}
                    </p>
                </template>

                <template #insignias>
                    <PildoraEstado :texto="docente.situacion" />
                </template>

                <template #bajo-titulo>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ docente.tipo ?? 'sin tipo' }}
                        <span v-if="docente.campus.length"> · {{ docente.campus.join(', ') }}</span>
                    </p>
                </template>
            </EncabezadoPersona>

            <div v-if="suplantable" class="mt-4 flex justify-end border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                <BotonAccion variante="ver" :texto="`Ver como ${suplantable.usuario}`" @click="verComo" />
            </div>
        </section>

        <PestanasPagina
            :pestanas="[
                { clave: 'materias', etiqueta: `Materias (${materias.length})` },
                { clave: 'documentos', etiqueta: `Documentos${pendientes ? ` · ${pendientes} por revisar` : ''}` },
                { clave: 'titulos', etiqueta: `Títulos (${titulos.length})` },
                ...(formularios.length
                    ? [{ clave: 'formularios', etiqueta: `Formularios${formulariosPendientes ? ` (${formulariosPendientes})` : ''}` }]
                    : []),
                { clave: 'horarios', etiqueta: 'Disponibilidad' },
                { clave: 'datos', etiqueta: 'Datos' },
            ]"
            :model-value="pestana"
            @update:model-value="pestana = $event as any"
        />

        <!-- Materias -->
        <section v-if="pestana === 'materias'" class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="materias.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Materia</th>
                            <th class="px-4 py-3 font-semibold">Grupo</th>
                            <th class="px-4 py-3 font-semibold">Ciclo</th>
                            <th class="px-4 py-3 font-semibold">Papel</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in materias" :key="m.id" class="fila-nueva border-t transition-colors" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4">
                                <span class="block font-semibold text-contenido">{{ m.materia }}</span>
                                <span class="mt-0.5 block font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ m.clave_en_plan }}</span>
                            </td>
                            <td class="px-4 py-4">
                                {{ m.grupo }}<span v-if="m.campus" class="block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ m.campus }}</span>
                            </td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ m.ciclo }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="m.tipo" :color="m.tipo === 'titular' ? 'var(--color-acento)' : 'var(--color-suave)'" />
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a :href="`/escolar/grupos/${m.id}`" class="text-sm font-medium" :style="{ color: 'var(--color-acento)' }">Grupo</a>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    No tiene materias asignadas. Se asignan desde el detalle de cada grupo.
                </p>
            </div>
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
                            <PildoraEstado :texto="doc.estado" :color="colorEstado(doc.estado_clave)" />
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

        <!-- Títulos / grados (CV) -->
        <div v-else-if="pestana === 'titulos'">
            <TitulosDocente
                :titulos="titulos"
                :base="`/escolar/docentes/${docente.id}/titulos`"
                :puede-editar="puedeGestionar"
            />
        </div>

        <!-- Disponibilidad y perfil: el insumo para armarle horario -->
        <section v-else-if="pestana === 'horarios'" class="space-y-4">
            <DisponibilidadSemanal
                :franjas="franjas"
                :ciclos="ciclos"
                :accion="`/escolar/docentes/${docente.id}/disponibilidad`"
                :puede-editar="puedeGestionarHorarios"
            />
            <AptitudesDocente
                :asignaturas="asignaturasQuePuedeImpartir"
                :catalogo="catalogoAsignaturas"
                :accion="`/escolar/docentes/${docente.id}/asignaturas`"
                :puede-editar="puedeGestionarHorarios"
            />
        </section>

        <!-- Formularios -->
        <section v-else-if="pestana === 'formularios'">
            <FormulariosAsignados
                :formularios="formularios"
                titular="docente"
                :base-captura="`/escolar/docentes/${docente.id}/formularios`"
                :puede-capturar="puedeGestionar"
            />
        </section>

        <!-- Datos -->
        <div v-else>
            <form v-if="puedeGestionar" class="space-y-4 sm:space-y-6" @submit.prevent="guardar">
                <TarjetaSeccion
                    titulo="Identidad"
                    descripcion="La CURP autollena fecha, género y entidad. CURP y correo son obligatorios."
                    :icono="ICONOS.persona"
                >
                    <CamposIdentidad
                        :form="form"
                        :generos="generos"
                        :entidades="entidades"
                        :entidad-extranjero="entidadExtranjero"
                        :paises="paises"
                        :mexico-id="mexicoId"
                        :persona-id="persona.id"
                        con-rfc
                        correo-requerido
                        curp-requerido
                    />
                </TarjetaSeccion>

                <TarjetaSeccion
                    titulo="Registro docente"
                    descripcion="Esto es lo que el docente ve de solo lectura en su portal."
                    :icono="ICONOS.birrete"
                >
                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoTexto v-model="form.clave_profesor" etiqueta="Clave de profesor" mono :error="form.errors.clave_profesor" />
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

                    <template #pie>
                        <BotonPrincipal :procesando="form.processing" texto="Guardar cambios" />
                    </template>
                </TarjetaSeccion>
            </form>

            <p v-else class="tarjeta p-6 text-sm" :style="{ color: 'var(--color-suave)' }">
                Solo consulta: no tienes permiso para gestionar docentes.
            </p>
        </div>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
