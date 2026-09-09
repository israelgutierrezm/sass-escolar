<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

/**
 * Pagar en línea: elegir con qué e ir a la pasarela.
 *
 * ── Por qué es un componente ───────────────────────────────────────────────
 * Lo usan dos pantallas que no se parecen —el estado de cuenta de control
 * escolar y el portal del padre de familia— y la parte delicada es la misma en
 * las dos: qué cargos se están pagando, cómo se pide la liga y qué se le dice a
 * quien paga cuando algo sale mal. Escrito dos veces, una de las copias se
 * quedaría atrás; y la que se quede atrás es la que manda dinero.
 */
interface AdeudoPagable {
    id: number;
    saldo: number;
}

interface PasarelaDisponible {
    clave: string;
    nombre: string;
    color: string | null;
    pruebas: boolean;
    meses: number[];
    efectivo: boolean;
    /**
     * Con qué hay que elegir ANTES de salir. Vacío en las pasarelas que
     * presentan su propio checkout —casi todas—; con opciones en las que cobran
     * por cargo y necesitan saberlo de antemano, como OpenPay.
     */
    metodos: { clave: string; etiqueta: string }[];
}

/** Una cuenta de la escuela para transferir sin pasarela. */
interface CuentaBancaria {
    id: number;
    nombre: string;
    banco: string;
    titular: string;
    clabe: string | null;
    numero_cuenta: string | null;
    instrucciones: string | null;
}

const props = withDefaults(
    defineProps<{
        matriculaId: number;
        adeudos: AdeudoPagable[];
        pasarelas: PasarelaDisponible[];
        /** Cargos marcados. Vacío = se pagan todos los que tengan saldo. */
        seleccionados?: number[];
        /**
         * Cuentas para transferir directo. Vacío = la escuela no ofrece esta
         * vía y el bloque ni aparece.
         */
        cuentas?: CuentaBancaria[];
        /** Mínimo para abonar en línea. 0 = sin mínimo. */
        abonoMinimo?: number;
    }>(),
    { seleccionados: () => [], cuentas: () => [], abonoMinimo: 0 },
);

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const yendoAPagar = ref<string | null>(null);
const error = ref<string | null>(null);

/** Sin marcar nada se pagan TODOS los cargos abiertos, que es lo que se espera. */
const aPagar = computed(() => {
    const abiertos = props.adeudos.filter((a) => a.saldo > 0);

    return props.seleccionados.length
        ? abiertos.filter((a) => props.seleccionados.includes(a.id))
        : abiertos;
});

const total = computed(() => aPagar.value.reduce((suma, a) => suma + a.saldo, 0));

/*
 * ── Abonar una cantidad menor ──────────────────────────────────────────────
 * Por omisión se paga el saldo entero de lo elegido. Quien no puede pagarlo
 * todo abona un importe: el motor lo reparte del cargo más vencido al menos, y
 * nunca puede pasar del saldo. El mínimo lo pone la escuela; el servidor lo
 * vuelve a exigir, esto es sólo para decirlo antes de salir a la pasarela.
 */
const abonando = ref(false);
const importe = ref<number | string>('');
const importeNum = computed(() => Math.round((Number(importe.value) || 0) * 100) / 100);

/** Lo que de verdad se va a cobrar: el abono si está activo, si no el total. */
const aCobrar = computed(() => (abonando.value ? importeNum.value : total.value));

/** Por qué no se puede abonar todavía, para decirlo y bloquear el botón. */
const problemaAbono = computed<string | null>(() => {
    if (!abonando.value) return null;
    if (importeNum.value <= 0) return 'Escribe cuánto quieres abonar.';
    if (importeNum.value > total.value) return `No puedes abonar más que el saldo elegido (${pesos.format(total.value)}).`;
    if (props.abonoMinimo > 0 && importeNum.value < props.abonoMinimo) return `El abono mínimo es ${pesos.format(props.abonoMinimo)}.`;
    return null;
});

const puedePagar = computed(() => aCobrar.value > 0 && problemaAbono.value === null);

/**
 * Manda a la pasarela.
 *
 * La respuesta trae una URL de OTRO dominio, así que el servidor no puede
 * redirigir: Inertia intentaría renderizar la página de la pasarela como si
 * fuera nuestra. Se pide la liga y se navega a mano.
 */
/*
 * ── La otra forma de pagar ─────────────────────────────────────────────────
 * Transferir a la cuenta de la escuela y subir el comprobante. No liquida nada
 * al mandarlo: alguien de la escuela tiene que validarlo, y eso se dice claro
 * para que nadie se vaya creyendo que ya está pagado.
 */
const transfiriendo = ref(false);
const cuentaElegida = ref<CuentaBancaria | null>(props.cuentas[0] ?? null);
const copiado = ref<string | null>(null);
const enviandoComprobante = ref(false);
const errorComprobante = ref<string | null>(null);

const comprobante = useForm({
    cuenta_bancaria_id: null as number | null,
    monto: '' as string | number,
    fecha_transferencia: '',
    referencia: '',
    adeudo_ids: [] as number[],
    archivo: null as File | null,
});

async function copiar(campo: string, valor: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(valor);
        copiado.value = campo;
        setTimeout(() => { copiado.value = null; }, 2000);
    } catch {
        // Sin permiso de portapapeles el dato sigue a la vista.
    }
}

function elegirArchivo(archivo: File | null): void {
    comprobante.archivo = archivo;
}

function mandarComprobante(): void {
    errorComprobante.value = null;

    if (!comprobante.archivo) {
        errorComprobante.value = 'Adjunta el comprobante de la transferencia.';

        return;
    }

    comprobante.cuenta_bancaria_id = cuentaElegida.value?.id ?? null;
    // Los mismos cargos que se están pagando: la elección es una sola.
    comprobante.adeudo_ids = aPagar.value.map((a) => a.id);
    enviandoComprobante.value = true;

    comprobante.post(`/comprobantes/${props.matriculaId}`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            comprobante.reset('monto', 'fecha_transferencia', 'referencia', 'archivo');
            transfiriendo.value = false;
        },
        onFinish: () => { enviandoComprobante.value = false; },
    });
}

/**
 * La pasarela cuyo método se está eligiendo.
 *
 * Sólo aparece con las que cobran por cargo —OpenPay— y necesitan saber de
 * antemano si es tarjeta, tienda o transferencia. Con las demás se sale directo
 * a su checkout, que es donde se elige.
 */
const eligiendo = ref<PasarelaDisponible | null>(null);

function pulsar(p: PasarelaDisponible): void {
    error.value = null;

    if (p.metodos.length) {
        eligiendo.value = eligiendo.value?.clave === p.clave ? null : p;

        return;
    }

    pagar(p.clave);
}

async function pagar(clave: string, metodo?: string): Promise<void> {
    // El botón ya se deshabilita, pero el método puede llegar por otra vía.
    if (!puedePagar.value) return;

    yendoAPagar.value = clave;
    error.value = null;

    try {
        const respuesta = await fetch(`/pagos/iniciar/${props.matriculaId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                pasarela: clave,
                adeudo_ids: aPagar.value.map((a) => a.id),
                metodo: metodo ?? null,
                // Sólo cuando se abona menos que el total: el servidor lo toma
                // como el importe a cobrar y lo valida contra el mínimo.
                importe: abonando.value ? importeNum.value : null,
            }),
        });

        const datos = await respuesta.json();

        if (!respuesta.ok || !datos.url) {
            // El motivo viene del servidor cuando se puede explicar (faltan
            // credenciales, la pasarela no está lista); si no, algo genérico
            // antes que un botón que no hace nada.
            error.value = datos.motivo ?? datos.message
                ?? 'No se pudo iniciar el pago. Inténtalo de nuevo en un momento.';

            return;
        }

        window.location.href = datos.url;
    } catch {
        error.value = 'No se pudo contactar con la pasarela de pago.';
    } finally {
        yendoAPagar.value = null;
    }
}
</script>

<template>
    <div>
        <p class="text-sm">
            Vas a pagar
            <strong>{{ pesos.format(aCobrar) }}</strong>
            <span :style="{ color: 'var(--color-suave)' }">
                <template v-if="abonando">a cuenta de {{ pesos.format(total) }} en </template>
                <template v-else>(</template>{{ aPagar.length === 1 ? '1 cargo' : `${aPagar.length} cargos` }}{{ abonando ? '.' : ').' }}
                <slot name="nota" />
            </span>
        </p>

        <!--
            Abonar menos que el total. El motor reparte el importe del cargo más
            vencido al menos entre los que se están pagando; el tope es el saldo.
        -->
        <div class="mt-2">
            <label class="inline-flex items-center gap-2 text-sm">
                <input v-model="abonando" type="checkbox" />
                <span>Abonar otra cantidad</span>
            </label>

            <div v-if="abonando" class="mt-2 max-w-xs">
                <label class="flex items-center gap-2 rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: problemaAbono ? 'var(--color-peligro)' : 'var(--color-borde)' }">
                    <span :style="{ color: 'var(--color-suave)' }">$</span>
                    <input
                        v-model="importe"
                        type="number"
                        step="0.01"
                        min="0"
                        :max="total"
                        class="w-full bg-transparent focus:outline-none"
                        placeholder="0.00"
                    />
                </label>
                <p class="mt-1 text-xs" :style="{ color: problemaAbono ? 'var(--color-peligro)' : 'var(--color-suave)' }">
                    <template v-if="problemaAbono">{{ problemaAbono }}</template>
                    <template v-else>
                        Se aplica del cargo más vencido al menos.
                        <template v-if="abonoMinimo > 0"> Mínimo {{ pesos.format(abonoMinimo) }}.</template>
                    </template>
                </p>
            </div>
        </div>

        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <div v-for="p in pasarelas" :key="p.clave">
                <button
                    type="button"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition hover:brightness-110 disabled:opacity-60"
                    :style="{ backgroundColor: p.color ?? 'var(--color-acento)' }"
                    :disabled="yendoAPagar !== null || !puedePagar"
                    @click="pulsar(p)"
                >
                    {{ yendoAPagar === p.clave ? 'Abriendo…' : `Pagar con ${p.nombre}` }}
                    <!-- Que se sepa que no es dinero real. -->
                    <span v-if="p.pruebas" class="rounded-full bg-white/25 px-1.5 py-0.5 text-xs">pruebas</span>
                </button>

                <!--
                    Elegir la forma de pago antes de salir.

                    Sólo con las pasarelas que cobran por cargo: no tienen una
                    pantalla propia donde elegir, así que la elección ocurre
                    aquí o no ocurre.
                -->
                <div v-if="eligiendo?.clave === p.clave" class="mt-2 space-y-1.5 rounded-lg border p-2" :style="{ borderColor: 'var(--color-borde)' }">
                    <p class="px-1 text-xs" :style="{ color: 'var(--color-suave)' }">¿Con qué vas a pagar?</p>
                    <button
                        v-for="m in p.metodos"
                        :key="m.clave"
                        type="button"
                        class="w-full rounded-lg border px-3 py-2 text-left text-sm transition hover:brightness-105 disabled:opacity-60"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        :disabled="yendoAPagar !== null || !puedePagar"
                        @click="pagar(p.clave, m.clave)"
                    >
                        {{ m.etiqueta }}
                    </button>
                </div>

                <!--
                    Los meses sin intereses y el pago en tienda cambian la
                    decisión de quien va a pagar. Descubrirlos hasta dentro de la
                    pasarela es descubrirlos tarde.
                -->
                <p v-if="p.meses.length || p.efectivo" class="mt-1 text-center text-xs" :style="{ color: 'var(--color-suave)' }">
                    <template v-if="p.meses.length">Hasta {{ p.meses[0] }} meses sin intereses</template>
                    <template v-if="p.meses.length && p.efectivo"> · </template>
                    <template v-if="p.efectivo">También en efectivo</template>
                </p>
            </div>
        </div>

        <!--
            Transferencia directa, sin pasarela.

            Se presenta como lo que es: más barato para la escuela y más lento
            para quien paga, porque alguien tiene que mirar el comprobante. Decir
            eso por adelantado evita la llamada de «ya pagué y sigue apareciendo
            el cargo».
        -->
        <div v-if="cuentas.length" class="mt-4 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
            <button
                v-if="!transfiriendo"
                type="button"
                class="rounded-lg border px-4 py-2 text-sm font-medium"
                :style="{ borderColor: 'var(--color-borde)' }"
                @click="transfiriendo = true"
            >
                O transferir a la cuenta de la escuela
            </button>

            <template v-else>
                <p class="text-sm font-medium">Transferencia a la cuenta de la escuela</p>

                <!-- Con varias cuentas hay que decir a cuál. -->
                <label v-if="cuentas.length > 1" class="mt-2 block text-sm">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">¿A qué cuenta?</span>
                    <select
                        v-model="cuentaElegida"
                        class="w-full rounded-lg border bg-transparent px-3 py-1.5"
                        :style="{ borderColor: 'var(--color-borde)' }"
                    >
                        <option v-for="c in cuentas" :key="c.id" :value="c">{{ c.nombre }} · {{ c.banco }}</option>
                    </select>
                </label>

                <!-- Los datos se copian a mano: un dígito de más arruina el pago. -->
                <dl v-if="cuentaElegida" class="mt-3 divide-y rounded-lg border px-3" :style="{ borderColor: 'var(--color-borde)' }">
                    <div
                        v-for="d in [
                            { etiqueta: 'Banco', valor: cuentaElegida.banco },
                            { etiqueta: 'Titular', valor: cuentaElegida.titular },
                            { etiqueta: 'CLABE', valor: cuentaElegida.clabe },
                            { etiqueta: 'Cuenta', valor: cuentaElegida.numero_cuenta },
                        ].filter((d) => d.valor)"
                        :key="d.etiqueta"
                        class="flex items-center justify-between gap-3 py-2"
                    >
                        <div class="min-w-0">
                            <dt class="text-[11px] uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">{{ d.etiqueta }}</dt>
                            <dd class="break-all font-mono text-sm">{{ d.valor }}</dd>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded-lg border px-2.5 py-1 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="copiar(d.etiqueta, String(d.valor))"
                        >
                            {{ copiado === d.etiqueta ? 'Copiado' : 'Copiar' }}
                        </button>
                    </div>
                </dl>

                <p v-if="cuentaElegida?.instrucciones" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                    {{ cuentaElegida.instrucciones }}
                </p>

                <div class="mt-3 grid gap-2 sm:grid-cols-3">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Cuánto transferiste</span>
                        <input v-model="comprobante.monto" type="number" step="0.01" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Cuándo</span>
                        <input v-model="comprobante.fecha_transferencia" type="date" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Referencia (opcional)</span>
                        <input v-model="comprobante.referencia" type="text" class="w-full rounded-lg border bg-transparent px-3 py-1.5" :style="{ borderColor: 'var(--color-borde)' }" />
                    </label>
                </div>

                <div class="mt-3">
                    <span class="mb-1 block text-xs" :style="{ color: 'var(--color-suave)' }">Comprobante</span>
                    <ZonaArchivo
                        accept="image/*,application/pdf"
                        texto="Arrastra el comprobante o haz clic para elegirlo"
                        ayuda="Imagen o PDF de la transferencia"
                        :cargado="comprobante.archivo?.name ?? null"
                        @archivo="elegirArchivo"
                    />
                </div>

                <p v-if="errorComprobante || comprobante.errors.archivo || comprobante.errors.monto || comprobante.errors.fecha_transferencia"
                   class="mt-2 text-sm text-red-600">
                    {{ errorComprobante || comprobante.errors.archivo || comprobante.errors.monto || comprobante.errors.fecha_transferencia }}
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-60"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        :disabled="enviandoComprobante"
                        @click="mandarComprobante"
                    >
                        {{ enviandoComprobante ? 'Enviando…' : 'Enviar comprobante' }}
                    </button>
                    <button type="button" class="text-sm" :style="{ color: 'var(--color-suave)' }" @click="transfiriendo = false">
                        Cancelar
                    </button>
                </div>

                <p class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                    El cargo NO se liquida al enviarlo: la escuela revisa el comprobante y lo aplica.
                    Suele tardar un día hábil.
                </p>
            </template>
        </div>

        <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>

        <p class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
            El cargo se aplica cuando la pasarela confirma el pago. Si se paga por SPEI o en tienda,
            puede tardar unas horas en reflejarse.
        </p>
    </div>
</template>
