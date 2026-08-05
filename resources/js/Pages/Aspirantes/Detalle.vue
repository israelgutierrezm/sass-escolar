<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
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

const entregados = computed(() => props.expediente.filter((fila) => fila.entrega !== null).length);

/** Para la barra de avance del expediente. Sin documentos configurados, 100%. */
const avanceExpediente = computed(
    () => (props.expediente.length === 0 ? 100 : Math.round((entregados.value / props.expediente.length) * 100)),
);

/** Respaldo de la foto en la cabecera, igual que en el resto de expedientes. */
const iniciales = computed(() => {
    const partes = (props.aspirante.nombre_completo ?? '').trim().split(/\s+/);

    return ((partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? '')).toUpperCase() || '—';
});

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
        <BotonVolver href="/aspirantes" texto="Aspirantes" class="mb-4" />

        <!--
            Cabecera de persona, como en alumnos y docentes.
            Antes se entraba directo a «Datos personales» y había que leer la
            ficha entera para saber en qué punto del embudo está y si le falta
            algo. Aquí arriba está lo que se pregunta primero: quién es, en qué
            etapa va, a qué aspira y qué le falta del expediente.
        -->
        <section class="tarjeta mb-6 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-center gap-4">
                    <img
                        v-if="aspirante.foto"
                        :src="aspirante.foto"
                        alt=""
                        class="h-16 w-16 rounded-full object-cover ring-1 ring-black/5"
                    >
                    <span
                        v-else
                        class="grid h-16 w-16 shrink-0 place-items-center rounded-full text-lg font-semibold"
                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                    >{{ iniciales }}</span>

                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-semibold text-contenido">{{ aspirante.nombre_completo }}</h2>
                        <p class="mt-0.5 text-sm text-suave">
                            <span v-if="aspirante.oferta">{{ aspirante.oferta }}</span>
                            <span v-else class="text-amber-700">Sin programa de interés</span>
                            <template v-if="aspirante.campus"> · {{ aspirante.campus }}</template>
                        </p>
                        <p class="mt-1 flex flex-wrap items-center gap-1.5">
                            <span
                                v-if="aspirante.situacion"
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }"
                            >{{ aspirante.situacion }}</span>
                            <span
                                v-if="aspirante.etapa"
                                class="rounded-full px-2.5 py-0.5 text-xs"
                                :style="{ backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                            >{{ aspirante.etapa }}</span>
                            <!-- Lo que le falta, en la cabecera: es el motivo por
                                 el que la mayoría abre esta pantalla. -->
                            <span
                                v-if="obligatoriosPendientes"
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                style="background-color: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309"
                            >Faltan {{ obligatoriosPendientes }} documento(s)</span>
                            <span
                                v-else
                                class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                                style="background-color: color-mix(in srgb, #16a34a 12%, transparent); color: #16a34a"
                            >Expediente completo</span>
                        </p>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
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
                    <BotonAccion v-if="permisos.editar" variante="editar" :href="`/aspirantes/${aspirante.id}/editar`" />
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Identidad y proceso -->
            <div class="space-y-6 lg:col-span-2">
                <TarjetaSeccion titulo="Datos personales" descripcion="Identidad del aspirante" :icono="ICONOS.persona">
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
                <TarjetaSeccion
                    titulo="Expediente documental"
                    :descripcion="obligatoriosPendientes
                        ? `Faltan ${obligatoriosPendientes} documento(s) obligatorio(s) por entregar.`
                        : 'Todos los documentos obligatorios están entregados.'"
                    :icono="ICONOS.documento"
                >
                    <template #insignia>
                        <span class="text-xs text-suave">{{ entregados }} de {{ expediente.length }}</span>
                    </template>

                    <!--
                        La barra dice de un vistazo si vale la pena leer la lista.
                        Ámbar mientras falte algo obligatorio, verde cuando ya no.
                    -->
                    <div
                        v-if="expediente.length"
                        class="mb-4 h-1.5 w-full overflow-hidden rounded-full"
                        style="background-color: var(--color-fondo)"
                    >
                        <div
                            class="h-full rounded-full transition-all"
                            :style="{
                                width: `${avanceExpediente}%`,
                                backgroundColor: obligatoriosPendientes ? '#f59e0b' : '#16a34a',
                            }"
                        />
                    </div>

                    <ul v-if="expediente.length" class="divide-y divide-borde">
                        <li v-for="fila in expediente" :key="fila.documento_id" class="py-3 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <!-- Entregado o no, antes que el nombre: es lo
                                         que se busca al recorrer la lista. -->
                                    <span
                                        class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-semibold"
                                        :style="fila.entrega
                                            ? { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' }
                                            : { backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                                    >{{ fila.entrega ? '✓' : '·' }}</span>

                                    <div class="min-w-0">
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
                                </div>

                                <div class="flex items-center gap-2">
                                    <template v-if="fila.entrega">
                                        <a
                                            :href="`/aspirantes/${aspirante.id}/expediente/${fila.entrega.id}/descargar`"
                                            class="text-sm hover:underline"
                                            :style="{ color: 'var(--color-acento)' }"
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
                                    class="rounded-lg px-3 py-1 text-xs font-medium text-white disabled:opacity-60"
                                    :style="{ backgroundColor: 'var(--color-acento)' }"
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

                    <p v-else class="text-sm text-suave">
                        No hay documentos configurados para esta carrera.
                    </p>
                </TarjetaSeccion>
            </div>

            <!-- Columna lateral: proceso y conversión -->
            <div class="space-y-6">
                <TarjetaSeccion
                    titulo="Avance del proceso"
                    descripcion="Lo que ya cumplió y lo que le falta antes de inscribirlo."
                    :icono="ICONOS.checkCirculo"
                >
                    <!--
                        Situación, interés y campus se movieron a la cabecera: aquí
                        se repetían palabra por palabra. Lo que queda es lo que la
                        cabecera no dice: de dónde vino y qué pasos lleva cumplidos.
                    -->
                    <ul class="space-y-2.5 text-sm">
                        <li
                            v-for="paso in [
                                { texto: 'Aceptó términos', valor: aspirante.acepto_terminos },
                                { texto: 'Información personal completa', valor: aspirante.info_personal_completa },
                                { texto: 'Validado por admin', valor: aspirante.validado_admin },
                                {
                                    texto: obligatoriosPendientes
                                        ? `Expediente completo (faltan ${obligatoriosPendientes})`
                                        : 'Expediente completo',
                                    valor: obligatoriosPendientes === 0,
                                },
                            ]"
                            :key="paso.texto"
                            class="flex items-start gap-2.5"
                        >
                            <span
                                class="mt-px grid h-5 w-5 shrink-0 place-items-center rounded-full text-xs font-semibold"
                                :style="paso.valor
                                    ? { backgroundColor: 'color-mix(in srgb, #16a34a 12%, transparent)', color: '#16a34a' }
                                    : { backgroundColor: 'var(--color-fondo)', color: 'var(--color-suave)' }"
                            >{{ paso.valor ? '✓' : '·' }}</span>
                            <span :class="paso.valor ? 'text-contenido' : 'text-suave'">{{ paso.texto }}</span>
                        </li>
                    </ul>

                    <p class="mt-4 border-t border-borde pt-3 text-sm text-suave">
                        Llegó por <span class="text-contenido">{{ aspirante.origen ?? 'origen sin registrar' }}</span>.
                    </p>
                </TarjetaSeccion>

                <!-- Conversión a alumno -->
                <TarjetaSeccion
                    titulo="Conversión a alumno"
                    descripcion="El paso que genera su matrícula. No se deshace."
                    :icono="ICONOS.birrete"
                >
                    <div v-if="matricula" class="rounded-lg bg-emerald-50 p-4 ring-1 ring-emerald-200">
                        <p class="text-xs uppercase tracking-wide text-emerald-700">Matrícula asignada</p>
                        <p class="mt-1 font-mono text-lg font-semibold text-emerald-900">
                            {{ matricula.matricula }}
                        </p>
                        <p class="mt-1 text-xs text-emerald-700">
                            {{ matricula.oferta }} · ingreso {{ matricula.fecha_ingreso }}
                        </p>
                    </div>

                    <template v-else>
                        <p class="text-sm text-suave">
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
                </TarjetaSeccion>
            </div>
        </div>
    </AppLayout>
</template>
