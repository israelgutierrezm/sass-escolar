<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

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
    suplantable: { usuario_id: number; usuario: string } | null;
}>();

// «Ver como»: entrar con la cuenta del aspirante para ver lo que ve. Queda en
// bitácora, y la banda superior lo recuerda mientras dure.
function verComo(): void {
    if (!props.suplantable) return;
    if (!confirm(`Vas a entrar como ${props.suplantable.usuario}. Queda registrado quién lo hizo y cuándo. ¿Continuar?`)) return;
    router.post(`/suplantar/${props.suplantable.usuario_id}`);
}

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
    /*
     * Convertir genera matrícula y no se deshace, así que un expediente
     * incompleto se confirma a mano.
     *
     * No se BLOQUEA a propósito: hay escuelas que inscriben con el acta
     * pendiente y la piden después, y prohibirlo las dejaría sin poder trabajar.
     * Lo que no puede pasar es que ocurra en silencio: antes se convertía a un
     * prospecto sin un solo documento con el mismo clic y sin decir nada.
     */
    if (obligatoriosPendientes.value > 0) {
        const faltan = obligatoriosPendientes.value;
        const aviso = faltan === 1
            ? 'Falta 1 documento obligatorio del expediente.'
            : `Faltan ${faltan} documentos obligatorios del expediente.`;

        if (!confirm(`${aviso}

Se le generará su matrícula de todos modos y eso no se puede deshacer. ¿Continuar?`)) {
            return;
        }
    }

    formConversion.post(`/aspirantes/${props.aspirante.id}/convertir`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="aspirante.nombre_completo" />

    <AppLayout :titulo="aspirante.nombre_completo">
        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Identidad y proceso -->
            <div class="space-y-6 lg:col-span-2">
                <TarjetaSeccion titulo="Datos personales" descripcion="Identidad del aspirante" :icono="ICONOS.persona">
                    <template #insignia>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="suplantable"
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                title="Entrar como este aspirante para ver lo que ve. Queda en bitácora."
                                @click="verComo"
                            >
                                Ver como {{ suplantable.usuario }}
                            </button>
                            <BotonAccion
                                v-if="permisos.editar"
                                variante="editar"
                                :href="`/aspirantes/${aspirante.id}/editar`"
                            />
                        </div>
                    </template>

                    <dl class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-suave">CURP</dt>
                            <dd class="mt-0.5 font-mono text-sm text-contenido">{{ aspirante.curp ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-suave">Género</dt>
                            <dd class="mt-0.5 text-sm text-contenido">{{ aspirante.genero ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-suave">Nacimiento</dt>
                            <dd class="mt-0.5 text-sm text-contenido">
                                {{ aspirante.fecha_nacimiento ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-suave">Correo</dt>
                            <dd class="mt-0.5 text-sm text-contenido">{{ aspirante.email ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-suave">Celular</dt>
                            <dd class="mt-0.5 text-sm text-contenido">{{ aspirante.celular ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-suave">Entidad</dt>
                            <dd class="mt-0.5 text-sm text-contenido">
                                {{ aspirante.entidad_nacimiento ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </TarjetaSeccion>

                <!-- Expediente -->
                <section class="tarjeta">
                    <div class="border-b border-borde p-6 pb-4">
                        <h2 class="text-base font-semibold text-contenido">Expediente documental</h2>
                        <p class="mt-1 text-sm text-suave">
                            <span v-if="obligatoriosPendientes">
                                Faltan {{ obligatoriosPendientes }} documento(s) obligatorio(s).
                            </span>
                            <span v-else>Todos los documentos obligatorios están entregados.</span>
                        </p>
                    </div>

                    <ul v-if="expediente.length" class="divide-y divide-borde">
                        <li v-for="fila in expediente" :key="fila.documento_id" class="p-6 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-contenido">
                                        {{ fila.nombre }}
                                        <span
                                            v-if="fila.obligatorio"
                                            class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700"
                                        >
                                            Obligatorio
                                        </span>
                                    </p>
                                    <p v-if="fila.descripcion" class="mt-0.5 text-xs text-suave">
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
                                            class="rounded-lg border border-borde px-2 py-1 text-xs"
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
                                            class="rounded-full bg-fondo px-2 py-1 text-xs text-contenido"
                                        >
                                            {{ fila.entrega.estado }}
                                        </span>
                                    </template>

                                    <button
                                        v-if="permisos.editar"
                                        type="button"
                                        class="rounded-lg border border-borde px-3 py-1 text-xs text-contenido hover:bg-fondo"
                                        @click="abrirSubida(fila.documento_id)"
                                    >
                                        {{ fila.entrega ? 'Reemplazar' : 'Cargar' }}
                                    </button>
                                </div>
                            </div>

                            <form
                                v-if="subiendoPara === fila.documento_id"
                                class="mt-3 flex flex-wrap items-center gap-3 border-l-2 py-3 pl-3"
                                :style="{ borderColor: 'var(--color-acento)' }"
                                @submit.prevent="subir"
                            >
                                <input
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    required
                                    class="text-xs"
                                    @change="seleccionarArchivo"
                                />
                                <label class="flex items-center gap-1.5 text-xs text-suave">
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
                                    class="text-xs text-suave"
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

                    <p v-else class="p-6 text-sm text-suave">
                        No hay documentos configurados para esta carrera.
                    </p>
                </section>
            </div>

            <!-- Columna lateral: proceso y conversión -->
            <div class="space-y-6">
                <section class="tarjeta p-6">
                    <h2 class="text-base font-semibold text-contenido">Proceso</h2>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-suave">Situación</dt>
                            <dd class="font-medium text-contenido">{{ aspirante.situacion }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-suave">Interés</dt>
                            <dd class="text-right text-contenido">{{ aspirante.oferta ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-suave">Campus</dt>
                            <dd class="text-contenido">{{ aspirante.campus ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-suave">Origen</dt>
                            <dd class="text-contenido">{{ aspirante.origen ?? '—' }}</dd>
                        </div>
                    </dl>

                    <ul class="mt-4 space-y-2 border-t border-borde pt-4 text-sm">
                        <li
                            v-for="bandera in [
                                { texto: 'Aceptó términos', valor: aspirante.acepto_terminos },
                                { texto: 'Información personal completa', valor: aspirante.info_personal_completa },
                                { texto: 'Validado por admin', valor: aspirante.validado_admin },
                            ]"
                            :key="bandera.texto"
                            class="flex items-center gap-2"
                        >
                            <span :class="bandera.valor ? 'text-emerald-600' : 'text-suave'">●</span>
                            <span :class="bandera.valor ? 'text-contenido' : 'text-suave'">
                                {{ bandera.texto }}
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- Conversión a alumno -->
                <section class="tarjeta p-6">
                    <h2 class="text-base font-semibold text-contenido">Conversión a alumno</h2>

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
                        <p class="mt-1 text-sm text-suave">
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
                                <label class="mb-1 block text-xs font-medium text-suave">
                                    Generación (opcional)
                                </label>
                                <input
                                    v-model="formConversion.generacion"
                                    type="text"
                                    placeholder="2026-2030"
                                    class="w-full rounded-lg border border-borde px-3 py-2 text-sm"
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

                        <p v-else-if="!permisos.convertir" class="mt-3 text-xs text-suave">
                            Tu rol no tiene permiso para convertir aspirantes.
                        </p>
                    </template>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
