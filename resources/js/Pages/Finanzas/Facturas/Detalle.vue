<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

interface Concepto {
    id: number;
    clave_sat: string;
    descripcion: string;
    cantidad: number;
    valor_unitario: number;
    importe: number;
    iva: number;
    pago_id: number | null;
    pago_metodo: string | null;
    disponible: number;
}

const props = defineProps<{
    factura: {
        id: number;
        uuid: string | null;
        estatus: string;
        emisor_rfc: string | null;
        emisor_razon_social: string | null;
        emisor_regimen_fiscal: string | null;
        emisor_cp: string | null;
        receptor_rfc: string;
        receptor_razon_social: string;
        receptor_uso_cfdi: string;
        receptor_regimen_fiscal: string;
        receptor_cp: string;
        forma_pago_sat: string | null;
        metodo_pago_sat: string;
        moneda: string;
        subtotal: number;
        iva: number;
        total: number;
        pac: string | null;
        intentos: number;
        ultimo_error: string | null;
        fecha_timbrado: string | null;
        cancelada_en: string | null;
        motivo_cancelacion: string | null;
        iedu: {
            nombre_alumno: string;
            curp: string;
            nivel_educativo: string;
            aut_rvoe: string;
        } | null;
        iedu_motivo: string | null;
        tipo: string;
        motivo_egreso: string | null;
        origen: { id: number; uuid: string | null; total: number } | null;
        acreditado: number;
        total_efectivo: number;
        notas_credito: {
            id: number;
            uuid: string | null;
            estatus: string;
            total: number;
            motivo: string | null;
            fecha: string | null;
        }[];
        acreditable: boolean;
        editable: boolean;
        fiscal: boolean;
        tiene_xml: boolean;
        tiene_pdf: boolean;
        matricula_id: number | null;
        matricula: string | null;
        alumno: string | null;
        sustituye: { id: number; uuid: string | null } | null;
        sustituida_por: { id: number; uuid: string | null }[];
    };
    conceptos: Concepto[];
    motivos: { valor: string; etiqueta: string }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const cancelando = ref(false);
const cancelacion = useForm({ motivo: '02', sustituta_id: null as number | null });

const refacturando = ref(false);
const refactura = useForm({
    rfc: props.factura.receptor_rfc,
    razon_social: props.factura.receptor_razon_social,
    uso_cfdi: props.factura.receptor_uso_cfdi,
    regimen_fiscal: props.factura.receptor_regimen_fiscal,
    cp: props.factura.receptor_cp,
});

function refacturar(): void {
    refactura.post(`/finanzas/facturas/${props.factura.id}/refacturar`);
}

function cancelar(): void {
    cancelacion.post(`/finanzas/facturas/${props.factura.id}/cancelar`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelando.value = false;
        },
    });
}

function reintentar(): void {
    router.post(`/finanzas/facturas/${props.factura.id}/reintentar`, {}, { preserveScroll: true });
}

function eliminar(): void {
    router.delete(`/finanzas/facturas/${props.factura.id}`);
}

/*
 * La nota de crédito: reduce la factura sin cancelarla.
 *
 * Se acredita RENGLÓN POR RENGLÓN y no por un importe total, que es la misma
 * razón por la que la factura desglosa el IVA por concepto: en un comprobante
 * conviven la colegiatura exenta y la constancia gravada, y un importe global
 * no diría cuánto impuesto se reversa.
 *
 * El tope de cada renglón lo manda el SERVIDOR (`disponible`): calcularlo aquí
 * sería una segunda cuenta que el día que difiera ofrecería un importe que el
 * servidor rechaza.
 */
const acreditando = ref(false);
const nota = useForm({
    motivo: '',
    renglones: props.conceptos.map((c) => ({ concepto_id: c.id, importe: 0 })),
});

const totalNota = computed(() =>
    nota.renglones.reduce((s, r) => s + (Number(r.importe) || 0), 0),
);

function acreditarTodo(): void {
    nota.renglones.forEach((r, i) => (r.importe = props.conceptos[i].disponible));
}

function emitirNota(): void {
    nota.post(`/finanzas/facturas/${props.factura.id}/nota-credito`, {
        preserveScroll: true,
    });
}

/*
 * Al cambiar de factura hay que CERRAR los tres formularios y volver a
 * sembrarlos.
 *
 * Inertia reutiliza el componente cuando la pantalla siguiente es la misma, y
 * sólo intercambia las props: los `ref` y los `useForm` sobreviven a la
 * navegación. Se vio emitiendo una nota de crédito —la pantalla salta al
 * comprobante nuevo— y ahí seguía abierto el formulario de acreditar,
 * ofreciendo acreditar la nota de crédito recién emitida y con los renglones
 * de la factura anterior. Lo mismo le pasaba a los de refacturar y cancelar,
 * que arrastraban el RFC del receptor de la factura de antes.
 */
watch(
    () => props.factura.id,
    () => {
        acreditando.value = false;
        refacturando.value = false;
        cancelando.value = false;

        nota.defaults({
            motivo: '',
            renglones: props.conceptos.map((c) => ({ concepto_id: c.id, importe: 0 })),
        });
        nota.reset();

        refactura.defaults({
            rfc: props.factura.receptor_rfc,
            razon_social: props.factura.receptor_razon_social,
            uso_cfdi: props.factura.receptor_uso_cfdi,
            regimen_fiscal: props.factura.receptor_regimen_fiscal,
            cp: props.factura.receptor_cp,
        });
        refactura.reset();

        cancelacion.reset();
    },
);


</script>

<template>
    <Head :title="`Factura ${factura.uuid ?? factura.id}`" />

    <AppLayout titulo="Factura">
        <section class="tarjeta p-6">
            <BotonVolver href="/finanzas/facturas" texto="Facturas" class="mb-4" />

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <PildoraEstado :texto="factura.estatus" />
                        <span v-if="factura.uuid" class="font-mono text-sm">{{ factura.uuid }}</span>
                        <span v-else class="text-sm" :style="{ color: 'var(--color-suave)' }">Sin folio fiscal todavía</span>
                    </div>
                    <p v-if="factura.alumno" class="mt-2 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ factura.alumno }} · <span class="font-mono">{{ factura.matricula }}</span>
                    </p>
                </div>
            </div>

            <p v-if="factura.ultimo_error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                <strong>El PAC la rechazó</strong> (intento {{ factura.intentos }}): {{ factura.ultimo_error }}
            </p>

            <p v-if="factura.estatus === 'timbrando'" class="mt-4 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800">
                En la cola, esperando al PAC. Recarga en un momento.
            </p>

            <div
                v-if="factura.cancelada_en"
                class="mt-4 rounded-lg border px-4 py-3 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                Cancelada el {{ factura.cancelada_en }} con motivo {{ factura.motivo_cancelacion }}.
                Sus pagos volvieron a poderse facturar.
            </div>

            <!--
                El complemento educativo. Se enseña con sus cuatro datos y no
                como un «sí»: son los que viajaron al SAT y los que un padre
                necesita para deducir, así que si alguno salió mal se ve aquí y
                no en abril.
            -->
            <div
                v-if="factura.iedu"
                class="mt-4 rounded-lg border-l-4 px-4 py-3 text-sm"
                :style="{ borderLeftColor: '#16a34a', backgroundColor: 'color-mix(in srgb, #16a34a 8%, transparent)' }"
            >
                <p class="font-medium">Con complemento educativo (IEDU) — deducible</p>
                <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2" :style="{ color: 'var(--color-suave)' }">
                    <div><dt class="inline">Alumno: </dt><dd class="inline">{{ factura.iedu.nombre_alumno }}</dd></div>
                    <div><dt class="inline">CURP: </dt><dd class="inline font-mono">{{ factura.iedu.curp }}</dd></div>
                    <div><dt class="inline">Nivel: </dt><dd class="inline">{{ factura.iedu.nivel_educativo }}</dd></div>
                    <div><dt class="inline">RVOE: </dt><dd class="inline font-mono">{{ factura.iedu.aut_rvoe }}</dd></div>
                </dl>
            </div>

            <!--
                Y cuando NO lo lleva habiéndole tocado. El motivo se guardó al
                emitir y no se recalcula: derivarlo ahora diría «no le falta
                nada» sobre una factura que salió sin complemento, en cuanto
                alguien capture el dato que faltaba.
            -->
            <div
                v-else-if="factura.iedu_motivo"
                class="mt-4 rounded-lg border-l-4 px-4 py-3 text-sm"
                :style="{ borderLeftColor: '#f59e0b', backgroundColor: 'color-mix(in srgb, #f59e0b 8%, transparent)' }"
            >
                <p class="font-medium">Salió sin complemento educativo</p>
                <p class="mt-1" :style="{ color: 'var(--color-suave)' }">{{ factura.iedu_motivo }}</p>
                <p class="mt-1" :style="{ color: 'var(--color-suave)' }">
                    Quien la recibió no puede deducirla. Para corregirlo hay que cancelarla y refacturar.
                </p>
            </div>

            <!--
                Una nota de crédito no se lee sola: sin decir a qué factura
                reduce, es un documento con un importe y sin sentido.
            -->
            <div
                v-if="factura.tipo === 'E'"
                class="mt-4 rounded-lg border-l-4 px-4 py-3 text-sm"
                :style="{ borderLeftColor: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 8%, transparent)' }"
            >
                <p class="font-medium">Nota de crédito</p>
                <p class="mt-1" :style="{ color: 'var(--color-suave)' }">
                    Reduce
                    <a
                        v-if="factura.origen"
                        :href="`/finanzas/facturas/${factura.origen.id}`"
                        :style="{ color: 'var(--color-acento)' }"
                    >la factura {{ factura.origen.uuid ?? factura.origen.id }}</a>
                    <span v-else>una factura que ya no está</span>
                    en {{ pesos.format(factura.total) }}. La original sigue vigente.
                </p>
                <p v-if="factura.motivo_egreso" class="mt-1" :style="{ color: 'var(--color-suave)' }">
                    Motivo: {{ factura.motivo_egreso }}
                </p>
            </div>

            <!--
                Y al revés: en la factura, lo que ya se le acreditó. El total de
                arriba sigue siendo el que se timbró —eso no cambia nunca—, así
                que sin esta línea la pantalla diría que se cobraron 2 500
                cuando de verdad se cobraron 2 000.
            -->
            <div
                v-if="factura.notas_credito.length"
                class="mt-4 rounded-lg border px-4 py-3 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
            >
                <p class="font-medium">
                    Acreditada en {{ pesos.format(factura.acreditado) }} · vale hoy
                    {{ pesos.format(factura.total_efectivo) }}
                </p>
                <ul class="mt-2 space-y-1" :style="{ color: 'var(--color-suave)' }">
                    <li v-for="n in factura.notas_credito" :key="n.id">
                        <a :href="`/finanzas/facturas/${n.id}`" :style="{ color: 'var(--color-acento)' }">
                            Nota {{ n.uuid ?? n.id }}</a>
                        · {{ pesos.format(n.total) }}
                        <span v-if="n.estatus !== 'timbrada'">· {{ n.estatus }}</span>
                        <span v-if="n.motivo">· {{ n.motivo }}</span>
                    </li>
                </ul>
            </div>

            <p v-if="factura.sustituye" class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sustituye a la
                <a :href="`/finanzas/facturas/${factura.sustituye.id}`" :style="{ color: 'var(--color-acento)' }">
                    factura {{ factura.sustituye.uuid ?? factura.sustituye.id }}</a>.
            </p>
            <p v-for="s in factura.sustituida_por" :key="s.id" class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sustituida por la
                <a :href="`/finanzas/facturas/${s.id}`" :style="{ color: 'var(--color-acento)' }">
                    factura {{ s.uuid ?? s.id }}</a>.
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                <a
                    v-if="factura.tiene_xml"
                    :href="`/finanzas/facturas/${factura.id}/descargar/xml`"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Descargar XML
                </a>
                <a
                    v-if="factura.tiene_pdf"
                    :href="`/finanzas/facturas/${factura.id}/descargar/pdf`"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    Descargar PDF
                </a>
                <button
                    v-if="!factura.fiscal && factura.estatus !== 'timbrando'"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="reintentar"
                >
                    Reintentar timbrado
                </button>
                <button
                    v-if="factura.estatus === 'timbrada'"
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="refacturando = !refacturando"
                >
                    Refacturar con datos corregidos
                </button>
                <button
                    v-if="factura.acreditable"
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="acreditando = !acreditando"
                >
                    Emitir nota de crédito
                </button>
                <button
                    v-if="factura.estatus === 'timbrada'"
                    type="button"
                    class="rounded-lg border px-4 py-2 text-sm text-red-600"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="cancelando = !cancelando"
                >
                    Cancelar ante el SAT
                </button>
                <BotonAccion v-if="factura.editable" variante="eliminar" texto="Eliminar el borrador" @click="eliminar" />
            </div>

            <!--
                Corregir una factura timbrada son DOS pasos y en este orden: el
                SAT pide el folio de la sustituta al cancelar, así que primero
                nace la nueva y solo entonces se cancela la vieja con motivo 01.
                Al revés, la escuela se queda sin comprobante vigente en el
                hueco entre las dos operaciones.
            -->
            <!--
                Acreditar es renglón por renglón: el tope de cada uno lo manda el
                servidor, porque acreditar de más declararía al SAT un ingreso
                negativo que nunca existió.
            -->
            <form
                v-if="acreditando && factura.acreditable"
                class="mt-4 border-t pt-4"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="emitirNota"
            >
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    Reduce esta factura sin cancelarla. Es lo que corresponde cuando el importe estaba bien
                    al emitirla y cambió después —una beca autorizada tarde, un cobro de más—, o
                    cuando ya venció el plazo para cancelar. No modifica la cartera del alumno.
                </p>

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            <tr>
                                <th class="py-2 pr-4 font-medium">Concepto</th>
                                <th class="py-2 pr-4 text-right font-medium">Por acreditar</th>
                                <th class="py-2 text-right font-medium">Acreditar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(r, i) in nota.renglones"
                                :key="r.concepto_id"
                                class="border-t"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                <td class="py-2 pr-4">{{ conceptos[i].descripcion }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                    {{ pesos.format(conceptos[i].disponible) }}
                                </td>
                                <td class="py-2 text-right">
                                    <input
                                        v-model.number="r.importe"
                                        type="number"
                                        step="any"
                                        min="0"
                                        :max="conceptos[i].disponible"
                                        class="w-32 rounded-lg border px-3 py-2 text-right text-sm tabular-nums"
                                        :style="{ borderColor: 'var(--color-borde)' }"
                                    />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="2" class="py-2 pr-4 text-right font-medium">Total de la nota</td>
                                <td class="py-2 text-right font-semibold tabular-nums">{{ pesos.format(totalNota) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <button
                    type="button"
                    class="mt-2 text-xs underline"
                    :style="{ color: 'var(--color-acento)' }"
                    @click="acreditarTodo"
                >
                    Acreditar todo lo disponible
                </button>

                <div class="mt-3">
                    <CampoTexto
                        v-model="nota.motivo"
                        etiqueta="Motivo"
                        requerido
                        :error="nota.errors.motivo"
                        ayuda="Queda en el comprobante. Dentro de un año es lo único que explica por qué la escuela declaró menos ingreso."
                    />
                </div>

                <BotonPrincipal
                    :procesando="nota.processing"
                    :deshabilitado="totalNota <= 0 || nota.motivo.trim() === ''"
                    texto="Emitir y timbrar la nota"
                    cargando="Timbrando…"
                    class="mt-3"
                />
            </form>

            <form
                v-if="refacturando"
                class="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="refacturar"
            >
                <p class="text-sm sm:col-span-2" :style="{ color: 'var(--color-suave)' }">
                    Se emite un comprobante nuevo por los mismos pagos, ligado a éste. Cuando el PAC le dé
                    folio, vuelve aquí y cancela éste con motivo 01.
                </p>
                <CampoTexto v-model="refactura.rfc" etiqueta="RFC" requerido mono :maximo="13" :error="refactura.errors.rfc" />
                <CampoTexto v-model="refactura.razon_social" etiqueta="Razón social" requerido :error="refactura.errors.razon_social" />
                <CampoTexto v-model="refactura.uso_cfdi" etiqueta="Uso del CFDI" requerido :maximo="5" :error="refactura.errors.uso_cfdi" />
                <CampoTexto v-model="refactura.regimen_fiscal" etiqueta="Régimen fiscal" requerido :maximo="5" :error="refactura.errors.regimen_fiscal" />
                <CampoTexto v-model="refactura.cp" etiqueta="CP fiscal" requerido :maximo="5" :error="refactura.errors.cp" />
                <BotonPrincipal texto="Emitir la sustituta" class="self-end" />
            </form>

            <form v-if="cancelando" class="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-[1fr_auto_auto]" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="cancelar">
                <div>
                    <CampoSelect
                        v-model="cancelacion.motivo"
                        etiqueta="Motivo de cancelación (SAT)"
                        :opciones="motivos.map((m) => ({ valor: m.valor, texto: m.etiqueta }))"
                        :error="cancelacion.errors.motivo"
                    />
                    <p v-if="cancelacion.motivo === '01'" class="mt-1 text-xs text-amber-700">
                        El motivo 01 exige que ya exista la factura que la sustituye. Emítela primero y
                        captura su id aquí.
                    </p>
                </div>
                <label v-if="cancelacion.motivo === '01'" class="text-sm">
                    <span class="mb-1 block font-medium">Id de la sustituta</span>
                    <input
                        v-model.number="cancelacion.sustituta_id"
                        type="number"
                        class="w-full rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    />
                </label>
                <button
                    type="submit"
                    class="self-end rounded-lg px-4 py-2 text-sm font-medium text-white"
                    style="background-color: #dc2626"
                >
                    Cancelar factura
                </button>
            </form>
        </section>

        <!--
            El emisor se muestra porque la escuela puede tener varias razones
            sociales: sin verlo, "por qué esta factura salió con el RFC de la
            otra" no tiene respuesta en pantalla.
        -->
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Emisor</h2>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">RFC</dt>
                    <dd class="font-mono">{{ factura.emisor_rfc ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt :style="{ color: 'var(--color-suave)' }">Razón social</dt>
                    <dd>{{ factura.emisor_razon_social ?? '—' }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Régimen · CP</dt>
                    <dd>{{ factura.emisor_regimen_fiscal ?? '—' }} · {{ factura.emisor_cp ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Receptor</h2>
            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">RFC</dt>
                    <dd class="font-mono">{{ factura.receptor_rfc }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt :style="{ color: 'var(--color-suave)' }">Razón social</dt>
                    <dd>{{ factura.receptor_razon_social }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Uso del CFDI</dt>
                    <dd>{{ factura.receptor_uso_cfdi }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Régimen fiscal</dt>
                    <dd>{{ factura.receptor_regimen_fiscal }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">CP fiscal</dt>
                    <dd>{{ factura.receptor_cp }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Forma de pago</dt>
                    <dd>{{ factura.forma_pago_sat ?? '—' }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">Método de pago</dt>
                    <dd>{{ factura.metodo_pago_sat }}</dd>
                </div>
                <div>
                    <dt :style="{ color: 'var(--color-suave)' }">PAC</dt>
                    <dd>{{ factura.pac ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        <section class="tarjeta overflow-hidden">
            <header class="px-6 py-4">
                <h2 class="text-base font-semibold">Conceptos</h2>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Un renglón por pago. La descripción y la clave del SAT se copiaron al emitir: si la
                    escuela renombra el concepto, este comprobante sigue diciendo lo que se timbró.
                </p>
            </header>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Clave SAT</th>
                            <th class="px-4 py-3 font-medium">Descripción</th>
                            <th class="px-4 py-3 font-medium">Pago</th>
                            <th class="px-4 py-3 text-right font-medium">Cantidad</th>
                            <th class="px-4 py-3 text-right font-medium">Importe</th>
                            <th class="px-6 py-3 text-right font-medium">IVA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in conceptos" :key="c.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-3 font-mono text-xs">{{ c.clave_sat }}</td>
                            <td class="px-4 py-3">{{ c.descripcion }}</td>
                            <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                                #{{ c.pago_id }} <span v-if="c.pago_metodo">· {{ c.pago_metodo }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ c.cantidad }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(c.importe) }}</td>
                            <td class="px-6 py-3 text-right tabular-nums">{{ pesos.format(c.iva) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td colspan="4" class="px-6 py-2 text-right" :style="{ color: 'var(--color-suave)' }">Subtotal</td>
                            <td colspan="2" class="px-6 py-2 text-right tabular-nums">{{ pesos.format(factura.subtotal) }}</td>
                        </tr>
                        <tr>
                            <td colspan="4" class="px-6 py-2 text-right" :style="{ color: 'var(--color-suave)' }">IVA</td>
                            <td colspan="2" class="px-6 py-2 text-right tabular-nums">{{ pesos.format(factura.iva) }}</td>
                        </tr>
                        <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td colspan="4" class="px-6 py-3 text-right font-semibold">Total</td>
                            <td colspan="2" class="px-6 py-3 text-right text-base font-semibold tabular-nums">
                                {{ pesos.format(factura.total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
