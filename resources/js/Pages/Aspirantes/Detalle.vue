<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Entrega {
    id: number;
    estado: string | null;
    estado_id: number;
    copia_certificada: boolean;
    documento_fisico: boolean;
}

interface FilaExpediente {
    documento_id: number;
    nombre: string;
    descripcion: string | null;
    obligatorio: boolean;
    entrega: Entrega | null;
}

const props = defineProps<{
    aspirante: Record<string, any>;
    expediente: FilaExpediente[];
    estadosDocumento: { id: number; nombre: string }[];
    matricula: { matricula: string; oferta: string | null; fecha_ingreso: string | null } | null;
    impedimentosConversion: string[];
    permisos: { editar: boolean; validarExpediente: boolean; convertir: boolean };
}>();

const subiendoPara = ref<number | null>(null);

const formArchivo = useForm<{ documento_id: number | null; archivo: File | null; copia_certificada: boolean }>({
    documento_id: null,
    archivo: null,
    copia_certificada: false,
});

const formConversion = useForm({ generacion: '' });

/** Solo se puede convertir si no hay impedimentos y aún no tiene matrícula. */
const puedeConvertir = computed(
    () => props.permisos.convertir && props.impedimentosConversion.length === 0 && props.matricula === null,
);

const obligatoriosPendientes = computed(
    () => props.expediente.filter((fila) => fila.obligatorio && fila.entrega === null).length,
);

function abrirSubida(documentoId: number): void {
    subiendoPara.value = documentoId;
    formArchivo.documento_id = documentoId;
    formArchivo.archivo = null;
    formArchivo.copia_certificada = false;
}

function seleccionarArchivo(evento: Event): void {
    const input = evento.target as HTMLInputElement;
    formArchivo.archivo = input.files?.[0] ?? null;
}

function subir(): void {
    formArchivo.post(`/aspirantes/${props.aspirante.id}/expediente`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            subiendoPara.value = null;
            formArchivo.reset();
        },
    });
}

function cambiarEstado(entregaId: number, estadoId: number): void {
    router.put(
        `/aspirantes/${props.aspirante.id}/expediente/${entregaId}/estado`,
        { estado_documento_id: estadoId },
        { preserveScroll: true },
    );
}

function convertir(): void {
    formConversion.post(`/aspirantes/${props.aspirante.id}/convertir`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="aspirante.nombre_completo" />

    <AppLayout :titulo="aspirante.nombre_completo">
        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Identidad y proceso -->
            <div class="space-y-6 lg:col-span-2">
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <div class="flex items-start justify-between">
                        <h2 class="text-base font-semibold text-slate-800">Datos personales</h2>
                        <a
                            v-if="permisos.editar"
                            :href="`/aspirantes/${aspirante.id}/editar`"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-700"
                        >
                            Editar
                        </a>
                    </div>

                    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">CURP</dt>
                            <dd class="mt-0.5 font-mono text-sm text-slate-700">{{ aspirante.curp ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Sexo</dt>
                            <dd class="mt-0.5 text-sm text-slate-700">{{ aspirante.sexo ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Nacimiento</dt>
                            <dd class="mt-0.5 text-sm text-slate-700">
                                {{ aspirante.fecha_nacimiento ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Correo</dt>
                            <dd class="mt-0.5 text-sm text-slate-700">{{ aspirante.email ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Celular</dt>
                            <dd class="mt-0.5 text-sm text-slate-700">{{ aspirante.celular ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Entidad</dt>
                            <dd class="mt-0.5 text-sm text-slate-700">
                                {{ aspirante.entidad_nacimiento ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Expediente -->
                <section class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                    <div class="border-b border-slate-100 p-6 pb-4">
                        <h2 class="text-base font-semibold text-slate-800">Expediente documental</h2>
                        <p class="mt-1 text-sm text-slate-500">
                            <span v-if="obligatoriosPendientes">
                                Faltan {{ obligatoriosPendientes }} documento(s) obligatorio(s).
                            </span>
                            <span v-else>Todos los documentos obligatorios están entregados.</span>
                        </p>
                    </div>

                    <ul v-if="expediente.length" class="divide-y divide-slate-100">
                        <li v-for="fila in expediente" :key="fila.documento_id" class="p-6 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-slate-800">
                                        {{ fila.nombre }}
                                        <span
                                            v-if="fila.obligatorio"
                                            class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700"
                                        >
                                            Obligatorio
                                        </span>
                                    </p>
                                    <p v-if="fila.descripcion" class="mt-0.5 text-xs text-slate-500">
                                        {{ fila.descripcion }}
                                    </p>
                                </div>

                                <div class="flex items-center gap-2">
                                    <template v-if="fila.entrega">
                                        <a
                                            :href="`/aspirantes/${aspirante.id}/expediente/${fila.entrega.id}/descargar`"
                                            class="text-sm text-indigo-600 hover:text-indigo-700"
                                        >
                                            Descargar
                                        </a>
                                        <select
                                            v-if="permisos.validarExpediente"
                                            :value="fila.entrega.estado_id"
                                            class="rounded-lg border border-slate-300 px-2 py-1 text-xs"
                                            @change="
                                                cambiarEstado(
                                                    fila.entrega!.id,
                                                    Number(($event.target as HTMLSelectElement).value),
                                                )
                                            "
                                        >
                                            <option
                                                v-for="estado in estadosDocumento"
                                                :key="estado.id"
                                                :value="estado.id"
                                            >
                                                {{ estado.nombre }}
                                            </option>
                                        </select>
                                        <span
                                            v-else
                                            class="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700"
                                        >
                                            {{ fila.entrega.estado }}
                                        </span>
                                    </template>

                                    <button
                                        v-if="permisos.editar"
                                        type="button"
                                        class="rounded-lg border border-slate-300 px-3 py-1 text-xs text-slate-700 hover:bg-slate-50"
                                        @click="abrirSubida(fila.documento_id)"
                                    >
                                        {{ fila.entrega ? 'Reemplazar' : 'Cargar' }}
                                    </button>
                                </div>
                            </div>

                            <form
                                v-if="subiendoPara === fila.documento_id"
                                class="mt-3 flex flex-wrap items-center gap-3 rounded-lg bg-slate-50 p-3"
                                @submit.prevent="subir"
                            >
                                <input
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    required
                                    class="text-xs"
                                    @change="seleccionarArchivo"
                                />
                                <label class="flex items-center gap-1.5 text-xs text-slate-600">
                                    <input v-model="formArchivo.copia_certificada" type="checkbox" class="rounded" />
                                    Copia certificada
                                </label>
                                <button
                                    type="submit"
                                    :disabled="formArchivo.processing"
                                    class="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-60"
                                >
                                    {{ formArchivo.processing ? 'Subiendo…' : 'Subir' }}
                                </button>
                                <button
                                    type="button"
                                    class="text-xs text-slate-500"
                                    @click="subiendoPara = null"
                                >
                                    Cancelar
                                </button>
                                <p v-if="formArchivo.errors.archivo" class="w-full text-xs text-red-600">
                                    {{ formArchivo.errors.archivo }}
                                </p>
                            </form>
                        </li>
                    </ul>

                    <p v-else class="p-6 text-sm text-slate-500">
                        No hay documentos configurados para esta carrera.
                    </p>
                </section>
            </div>

            <!-- Columna lateral: proceso y conversión -->
            <div class="space-y-6">
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-base font-semibold text-slate-800">Proceso</h2>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Situación</dt>
                            <dd class="font-medium text-slate-800">{{ aspirante.situacion }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Interés</dt>
                            <dd class="text-right text-slate-700">{{ aspirante.oferta ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Campus</dt>
                            <dd class="text-slate-700">{{ aspirante.campus ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Origen</dt>
                            <dd class="text-slate-700">{{ aspirante.origen ?? '—' }}</dd>
                        </div>
                    </dl>

                    <ul class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm">
                        <li
                            v-for="bandera in [
                                { texto: 'Aceptó términos', valor: aspirante.acepto_terminos },
                                { texto: 'Información personal completa', valor: aspirante.info_personal_completa },
                                { texto: 'Test Cleaver terminado', valor: aspirante.cleaver_completo },
                                { texto: 'Validado por admin', valor: aspirante.validado_admin },
                            ]"
                            :key="bandera.texto"
                            class="flex items-center gap-2"
                        >
                            <span :class="bandera.valor ? 'text-emerald-600' : 'text-slate-300'">●</span>
                            <span :class="bandera.valor ? 'text-slate-700' : 'text-slate-400'">
                                {{ bandera.texto }}
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- Conversión a alumno -->
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                    <h2 class="text-base font-semibold text-slate-800">Conversión a alumno</h2>

                    <div v-if="matricula" class="mt-4 rounded-lg bg-emerald-50 p-4 ring-1 ring-emerald-200">
                        <p class="text-xs uppercase tracking-wide text-emerald-700">Matrícula asignada</p>
                        <p class="mt-1 font-mono text-lg font-semibold text-emerald-900">
                            {{ matricula.matricula }}
                        </p>
                        <p class="mt-1 text-xs text-emerald-700">
                            {{ matricula.oferta }} · ingreso {{ matricula.fecha_ingreso }}
                        </p>
                    </div>

                    <template v-else>
                        <p class="mt-1 text-sm text-slate-500">
                            La matrícula se genera en este paso, no antes.
                        </p>

                        <ul
                            v-if="impedimentosConversion.length"
                            class="mt-3 space-y-1 rounded-lg bg-amber-50 p-3 text-xs text-amber-800"
                        >
                            <li v-for="impedimento in impedimentosConversion" :key="impedimento">
                                {{ impedimento }}
                            </li>
                        </ul>

                        <form v-if="puedeConvertir" class="mt-4 space-y-3" @submit.prevent="convertir">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600">
                                    Generación (opcional)
                                </label>
                                <input
                                    v-model="formConversion.generacion"
                                    type="text"
                                    placeholder="2026-2030"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                                />
                            </div>
                            <button
                                type="submit"
                                :disabled="formConversion.processing"
                                class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                            >
                                {{ formConversion.processing ? 'Convirtiendo…' : 'Convertir en alumno' }}
                            </button>
                        </form>

                        <p v-else-if="!permisos.convertir" class="mt-3 text-xs text-slate-400">
                            Tu rol no tiene permiso para convertir aspirantes.
                        </p>
                    </template>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
