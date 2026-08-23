<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import ActividadAspirante from '@/Components/ActividadAspirante.vue';
import CobroAspirante from '@/Components/CobroAspirante.vue';
import FormulariosAsignados from '@/Components/FormulariosAsignados.vue';
import { ICONOS } from '@/iconos';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

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
    matriculaSugerida: { matricula: string | null; motivo: string | null };
    permisos: {
        editar: boolean;
        validarExpediente: boolean;
        convertir: boolean;
        cobrar: boolean;
        coordinarPromocion: boolean;
    };
    // El CRM del prospecto: su línea de tiempo y los catálogos con los que se
    // captura. Los arma el controlador para que la pantalla no consulte nada.
    actividad: {
        agendadas: Record<string, any>[];
        historial: Record<string, any>[];
        contactos: number;
    };
    catalogosCrm: {
        tipos: { id: number; nombre: string; exige_proximo_contacto: boolean }[];
        resultados: { id: number; nombre: string; cierra_el_embudo: boolean }[];
        etapas: { id: number; nombre: string }[];
        asesores: { id: number; nombre: string }[];
    };
    asesores: { persona_id: number; nombre: string; titular: boolean }[];
    cobro: Record<string, any>;
    formularios: Record<string, any>[];
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

const formConversion = useForm({ generacion: '', matricula: '' });

/*
 * La matrícula se muestra sugerida y editable.
 *
 * Antes se generaba en silencio al pulsar «Convertir» y el administrador se
 * enteraba del número después, cuando ya no se deshace. Si la regla no cubre
 * un caso —un alumno que regresa y conserva el suyo, uno heredado del sistema
 * anterior— aquí se corrige, y entonces el contador no avanza.
 */
const editandoMatricula = ref(false);

function usarSugerida(): void {
    formConversion.matricula = '';
    editandoMatricula.value = false;
}

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

function seleccionarArchivo(archivo: File | null): void {
    formArchivo.archivo = archivo;
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

/*
 * Descartar es el OTRO desenlace, y por eso el motivo es obligatorio: la razón
 * es justo lo que se pregunta meses después al revisar por qué se cayó un
 * prospecto, y una fila de catálogo («Rechazado») no podía darla.
 *
 * Se deshace —reactivar— porque un descarte es un juicio, no un hecho: el que
 * dejó de contestar en marzo puede llamar en agosto.
 */
const descartando = ref(false);
const formDescarte = useForm({ motivo_descarte: '' });

function descartar(): void {
    formDescarte.post(`/aspirantes/${props.aspirante.id}/descartar`, {
        preserveScroll: true,
        onSuccess: () => {
            descartando.value = false;
            formDescarte.reset();
        },
    });
}

function reactivar(): void {
    router.post(`/aspirantes/${props.aspirante.id}/reactivar`, {}, { preserveScroll: true });
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
                            <!--
                                La situación se calla mientras sea «Prospecto».
                                ─────────────────────────────────────────────
                                Ese valor no dice nada que la etapa no diga
                                mejor: es el estado de todo el que sigue vivo en
                                el embudo. La píldora aparece cuando de verdad
                                informa —Rechazado o Inscrito—, que son
                                desenlaces y merecen verse de lejos. Es la misma
                                regla del panel: lo que no informa no ocupa
                                sitio, porque entrena a no mirarlo.
                            -->
                            <span
                                v-if="aspirante.desenlace !== 'abierto'"
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :style="{
                                    backgroundColor: `color-mix(in srgb, ${aspirante.desenlace === 'inscrito' ? '#16a34a' : '#b45309'} 14%, transparent)`,
                                    color: aspirante.desenlace === 'inscrito' ? '#16a34a' : '#b45309',
                                }"
                            >{{ aspirante.desenlace === 'inscrito' ? 'Inscrito' : 'Descartado' }}</span>
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
                <!--
                    La ACTIVIDAD va primero: es a lo que se entra a diario.
                    Los datos personales se consultan una vez y se corrigen de
                    tarde en tarde; el seguimiento se toca en cada llamada.
                -->
                <TarjetaSeccion
                    titulo="Actividad"
                    descripcion="Lo que falta por hacer y todo lo que se ha hablado"
                    :icono="ICONOS.persona"
                >
                    <ActividadAspirante
                        :aspirante-id="aspirante.id"
                        :actividad="actividad"
                        :catalogos="catalogosCrm"
                        :etapa-actual-id="aspirante.etapa_crm_id"
                        :avance="aspirante.avance_embudo"
                        :asesores="asesores"
                        :puede-reasignar="permisos.coordinarPromocion"
                    />
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
                                class="mt-3 border-l-2 py-3 pl-3"
                                :style="{ borderColor: 'var(--color-acento)' }"
                                @submit.prevent="subir"
                            >
                                <ZonaArchivo
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    texto="Arrastra el documento o haz clic para elegirlo"
                                    ayuda="PDF o imagen"
                                    :cargado="formArchivo.archivo?.name ?? null"
                                    @archivo="seleccionarArchivo"
                                />

                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <label class="flex items-center gap-1.5 text-xs text-suave">
                                    <input v-model="formArchivo.copia_certificada" type="checkbox" />
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
                                </div>
                            </form>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-suave">
                        No hay documentos configurados para esta carrera.
                    </p>
                </TarjetaSeccion>

                <FormulariosAsignados
                    :formularios="formularios"
                    titular="aspirante"
                    :base-captura="`/aspirantes/${aspirante.id}/formularios`"
                    :puede-capturar="permisos.editar"
                />

                <CobroAspirante
                    :aspirante-id="aspirante.id"
                    :cobro="cobro"
                    :puede-cobrar="permisos.cobrar"
                />
            </div>

            <!--
                Columna de CONTEXTO, y se queda PEGADA al desplazarse.
                ─────────────────────────────────────────────────────────────
                Antes se iba con el resto: «Avance del proceso» —que es lo que
                dice qué le falta a este aspirante— desaparecía de la pantalla
                justo al bajar a trabajar su expediente, que es cuando hace
                falta. Y como es más corta que la de trabajo, dejaba 1 444 px
                de hueco a la derecha mientras la izquierda seguía bajando.
                Pegarla resuelve las dos cosas: el resumen acompaña y el hueco
                deja de existir.

                Es lo mismo que ya hace el panel —trabajo a la izquierda,
                contexto a la derecha—, y por eso se siente igual.

                Con su PROPIO desplazamiento, y hizo falta: pegarla a secas no
                bastaba porque la columna mide más que la pantalla, así que
                subía hasta agotarse y «Avance del proceso» terminaba 234 px
                por encima del borde —medido—. Acotada al alto de la ventana,
                nada de lo que acompaña se va.
            -->
            <div
                class="space-y-6 lg:sticky lg:top-20 lg:self-start lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto lg:pr-1"
            >
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
                <TarjetaSeccion titulo="Datos personales" descripcion="Identidad del aspirante" :icono="ICONOS.persona">
                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
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
                            <!--
                                La matrícula, ANTES de pulsar.
                                Se generaba en silencio y el administrador se
                                enteraba del número cuando ya no se deshace.
                            -->
                            <div class="rounded-lg p-3" :style="{ backgroundColor: 'var(--color-fondo)' }">
                                <p class="text-xs font-medium text-suave">Matrícula que se le asignará</p>

                                <template v-if="!editandoMatricula">
                                    <p
                                        v-if="matriculaSugerida.matricula"
                                        class="mt-1 font-mono text-lg font-semibold text-contenido"
                                    >{{ matriculaSugerida.matricula }}</p>
                                    <p v-else class="mt-1 text-sm text-amber-700">{{ matriculaSugerida.motivo }}</p>

                                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                                        <button
                                            type="button"
                                            class="hover:underline"
                                            :style="{ color: 'var(--color-acento)' }"
                                            @click="editandoMatricula = true"
                                        >
                                            Usar otra
                                        </button>
                                        <span class="text-suave">Sale del formato configurado en Admisiones.</span>
                                    </div>
                                </template>

                                <template v-else>
                                    <input
                                        v-model="formConversion.matricula"
                                        type="text"
                                        :placeholder="matriculaSugerida.matricula ?? 'Escribe la matrícula'"
                                        class="mt-1 w-full rounded-lg border border-borde px-3 py-2 font-mono text-sm uppercase"
                                    />
                                    <p class="mt-1.5 text-xs text-suave">
                                        Si la escribes a mano, el consecutivo NO avanza: ese folio no salió de él.
                                    </p>
                                    <button
                                        type="button"
                                        class="mt-1.5 text-xs hover:underline"
                                        :style="{ color: 'var(--color-acento)' }"
                                        @click="usarSugerida"
                                    >
                                        Volver a la sugerida
                                    </button>
                                </template>

                                <p v-if="formConversion.errors.matricula" class="mt-1.5 text-xs text-red-600">
                                    {{ formConversion.errors.matricula }}
                                </p>
                            </div>

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

                <!--
                    El desenlace contrario, al lado del bueno: un prospecto acaba
                    inscrito o descartado, y las dos decisiones se toman aquí.
                -->
                <TarjetaSeccion
                    v-if="permisos.editar"
                    titulo="Descarte"
                    descripcion="Cuando el prospecto se da por perdido. Se puede deshacer."
                    :icono="ICONOS.alerta"
                >
                    <div v-if="aspirante.desenlace === 'descartado'">
                        <p class="text-sm">
                            Descartado el {{ aspirante.descartado_en }}.
                        </p>
                        <p v-if="aspirante.motivo_descarte" class="mt-1 text-sm text-suave">
                            {{ aspirante.motivo_descarte }}
                        </p>

                        <button
                            type="button"
                            class="mt-4 w-full rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="reactivar"
                        >
                            Reactivar prospecto
                        </button>
                    </div>

                    <!--
                        Por qué no se puede, dicho por su nombre. Un botón
                        ausente sin explicación obliga a adivinar.
                    -->
                    <p v-else-if="aspirante.motivo_no_descartable" class="text-sm text-suave">
                        {{ aspirante.motivo_no_descartable }}
                    </p>

                    <form v-else-if="descartando" class="space-y-3" @submit.prevent="descartar">
                        <CampoTextarea
                            v-model="formDescarte.motivo_descarte"
                            etiqueta="¿Por qué se descarta?"
                            :filas="2"
                            ayuda="Se guarda tal cual: es lo que se lee al revisar por qué se perdió."
                            :error="formDescarte.errors.motivo_descarte"
                        />

                        <div class="flex items-center gap-2">
                            <button
                                type="submit"
                                :disabled="formDescarte.processing"
                                class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-700 disabled:opacity-60"
                            >
                                {{ formDescarte.processing ? 'Descartando…' : 'Descartar' }}
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border px-4 py-2 text-sm"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="descartando = false"
                            >
                                Cancelar
                            </button>
                        </div>
                    </form>

                    <template v-else>
                        <p class="text-sm text-suave">
                            Sigue abierto. Descartarlo lo saca del embudo sin borrar nada de lo
                            que ya se le registró.
                        </p>
                        <button
                            type="button"
                            class="mt-4 w-full rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="descartando = true"
                        >
                            Descartar prospecto
                        </button>
                    </template>
                </TarjetaSeccion>
            </div>
        </div>
    </AppLayout>
</template>
