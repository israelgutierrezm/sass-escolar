<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Campo {
    clave: string;
    etiqueta: string;
    requerido: boolean;
    ayuda: string | null;
}

/** Lo que una pasarela ofrece: un interruptor, o los plazos de MSI. */
interface Opcion {
    clave: string;
    etiqueta: string;
    tipo: 'interruptor' | 'meses';
    valores: number[];
    ayuda: string | null;
}

interface Pasarela {
    clave: string;
    nombre: string;
    descripcion: string;
    color: string;
    campos: Campo[];
    activa: boolean;
    ambiente: 'pruebas' | 'produccion';
    puestos_pruebas: Record<string, boolean>;
    puestos_produccion: Record<string, boolean>;
    completa_pruebas: boolean;
    completa_produccion: boolean;
    opciones_disponibles: Opcion[];
    opciones: Record<string, boolean | number[]>;
    /** ¿Ya sabemos cobrar con ella? Si no, se configura pero no cobra. */
    cobra: boolean;
}

const props = defineProps<{ pasarelas: Pasarela[] }>();

// Un formulario por pasarela, con las credenciales SIEMPRE vacías (los valores
// guardados no se muestran; capturar de nuevo pisa, dejar en blanco conserva).
const forms = reactive<Record<string, ReturnType<typeof useForm>>>({});

for (const p of props.pasarelas) {
    forms[p.clave] = useForm({
        ambiente: p.ambiente,
        activa: p.activa,
        credenciales: Object.fromEntries(p.campos.map((c) => [c.clave, ''])) as Record<string, string>,
        // Las opciones SÍ se precargan: no son secretas y hay que poder ver qué
        // está encendido sin tener que volver a elegirlo todo.
        opciones: JSON.parse(JSON.stringify(p.opciones)) as Record<string, boolean | number[]>,
    });
}

/** ¿Está marcado este plazo de meses sin intereses? */
function tieneMes(clave: string, opcion: string, mes: number): boolean {
    const meses = (forms[clave].opciones as Record<string, boolean | number[]>)[opcion];

    return Array.isArray(meses) && meses.includes(mes);
}

function alternarMes(clave: string, opcion: string, mes: number): void {
    const opciones = forms[clave].opciones as Record<string, boolean | number[]>;
    const meses = Array.isArray(opciones[opcion]) ? [...(opciones[opcion] as number[])] : [];
    const i = meses.indexOf(mes);

    i === -1 ? meses.push(mes) : meses.splice(i, 1);
    opciones[opcion] = meses.sort((a, b) => a - b);
}

/**
 * Los meses sin intereses no existen sin tarjeta: los pone la tarjeta de
 * crédito, no la pasarela. Se atenúan en vez de esconderse para que se entienda
 * que están ahí y por qué no se pueden usar.
 */
function hayTarjeta(p: Pasarela): boolean {
    const opciones = forms[p.clave].opciones as Record<string, boolean | number[]>;

    return !p.opciones_disponibles.some((o) => o.clave === 'tarjeta') || opciones.tarjeta === true;
}

/** Sin ninguna forma de pago encendida no hay con qué pagar. El servidor lo rechaza. */
function sinFormasDePago(p: Pasarela): boolean {
    const interruptores = p.opciones_disponibles.filter((o) => o.tipo === 'interruptor');

    if (!interruptores.length) return false;

    const opciones = forms[p.clave].opciones as Record<string, boolean | number[]>;

    return interruptores.every((o) => opciones[o.clave] !== true);
}

// ¿El ambiente elegido en el form (aún sin guardar) está completo? Combina lo ya
// guardado con lo que se está capturando ahora, para habilitar el switch.
function completaAhora(p: Pasarela): boolean {
    const form = forms[p.clave];
    const ambiente = form.ambiente as 'pruebas' | 'produccion';
    const puestos = ambiente === 'produccion' ? p.puestos_produccion : p.puestos_pruebas;

    return p.campos
        .filter((c) => c.requerido)
        .every((c) => puestos[c.clave] || (form.credenciales as Record<string, string>)[c.clave]?.trim());
}

function guardar(p: Pasarela): void {
    forms[p.clave].put(`/plataforma/configuraciones/pasarelas/${p.clave}`, {
        preserveScroll: true,
        onSuccess: () => {
            // Se limpian las credenciales capturadas (ya viajaron cifradas).
            const cred = forms[p.clave].credenciales as Record<string, string>;
            Object.keys(cred).forEach((k) => (cred[k] = ''));
        },
    });
}

const activas = computed(() => props.pasarelas.filter((p) => p.activa).length);
</script>

<template>
    <Head title="Pasarelas de pago" />

    <AppLayout titulo="Pasarelas de pago">
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">Cómo cobran a los alumnos</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Captura las credenciales de cada pasarela y enciende las que quieras usar. Puedes activar una,
                dos o todas. Una pasarela solo se puede <strong>activar</strong> si su ambiente trae completos
                sus datos; si le faltan, se guardan pero no se enciende.
                <span v-if="activas"> Ahora hay <strong>{{ activas }}</strong> activa(s).</span>
            </p>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section v-for="p in pasarelas" :key="p.clave" class="tarjeta flex flex-col p-6">
                <!-- Encabezado: marca + switch de activación -->
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-sm font-bold text-white" :style="{ backgroundColor: p.color }">
                            {{ p.nombre.slice(0, 2) }}
                        </span>
                        <div>
                            <h3 class="flex flex-wrap items-center gap-2 font-semibold">
                                {{ p.nombre }}
                                <!--
                                    Que se sepa ANTES de configurarla. Encenderla
                                    sin saber esto acaba con un alumno delante de
                                    un botón que no puede completar su pago.
                                -->
                                <span
                                    v-if="!p.cobra"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-normal"
                                    :style="{ backgroundColor: 'color-mix(in srgb, #d97706 14%, transparent)', color: '#b45309' }"
                                >
                                    Aún no cobra en línea
                                </span>
                            </h3>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ p.descripcion }}</p>
                        </div>
                    </div>

                    <div class="flex flex-col items-end gap-1">
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="forms[p.clave].activa"
                            :disabled="!completaAhora(p) && !forms[p.clave].activa"
                            class="relative h-6 w-11 rounded-full transition disabled:cursor-not-allowed disabled:opacity-40"
                            :style="{ backgroundColor: forms[p.clave].activa ? p.color : 'var(--color-borde)' }"
                            @click="forms[p.clave].activa = !forms[p.clave].activa"
                        >
                            <span class="absolute top-1 h-4 w-4 rounded-full bg-superficie transition-all" :style="{ left: forms[p.clave].activa ? '1.5rem' : '0.25rem' }" />
                        </button>
                        <span class="text-[11px]" :style="{ color: forms[p.clave].activa ? p.color : 'var(--color-suave)' }">
                            {{ forms[p.clave].activa ? 'Activa' : 'Inactiva' }}
                        </span>
                    </div>
                </div>

                <!-- Ambiente -->
                <div class="mt-5 flex items-center gap-2">
                    <span class="text-xs font-medium" :style="{ color: 'var(--color-suave)' }">Ambiente:</span>
                    <div class="inline-flex rounded-lg border p-0.5 text-xs" :style="{ borderColor: 'var(--color-borde)' }">
                        <button
                            v-for="amb in (['pruebas', 'produccion'] as const)"
                            :key="amb"
                            type="button"
                            class="rounded-md px-3 py-1 font-medium capitalize transition"
                            :style="forms[p.clave].ambiente === amb ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' } : { color: 'var(--color-suave)' }"
                            @click="forms[p.clave].ambiente = amb"
                        >
                            {{ amb === 'pruebas' ? 'Pruebas' : 'Producción' }}
                        </button>
                    </div>
                </div>

                <!-- Credenciales del ambiente elegido -->
                <div class="mt-4 grid gap-3">
                    <label v-for="campo in p.campos" :key="campo.clave" class="text-sm">
                        <span class="mb-1 flex items-center gap-1 font-medium">
                            {{ campo.etiqueta }}
                            <span v-if="campo.requerido" class="text-red-500">*</span>
                        </span>
                        <input
                            v-model="(forms[p.clave].credenciales as Record<string, string>)[campo.clave]"
                            type="password"
                            autocomplete="off"
                            class="w-full rounded-lg border px-3 py-2 font-mono text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            :placeholder="(forms[p.clave].ambiente === 'produccion' ? p.puestos_produccion : p.puestos_pruebas)[campo.clave] ? '•••••••• (guardada)' : ''"
                        />
                        <span v-if="campo.ayuda" class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ campo.ayuda }}</span>
                    </label>
                </div>

                <!--
                    Qué ofrece la pasarela. Sólo lo que ella soporta de verdad:
                    PayPal no aparece aquí porque en México no cobra en efectivo
                    ni da meses sin intereses por su cuenta.
                -->
                <div v-if="p.opciones_disponibles.length" class="mt-5 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <p class="mb-3 text-xs font-medium uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        Formas de pago que se le ofrecen al alumno
                    </p>

                    <div class="grid gap-3">
                        <template v-for="op in p.opciones_disponibles" :key="op.clave">
                            <!-- Interruptor: se acepta o no. -->
                            <!--
                                Sin aserción de tipo dentro del `v-model`: con
                                ella el compilador de plantillas genera un
                                acceso que LEE pero no escribe, así que la
                                casilla se marcaba en pantalla y el valor nunca
                                llegaba al formulario. Se veía guardar bien y no
                                guardaba nada.
                            -->
                            <label v-if="op.tipo === 'interruptor'" class="flex items-start gap-2 text-sm">
                                <input
                                    v-model="forms[p.clave].opciones[op.clave]"
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded"
                                />
                                <span>
                                    {{ op.etiqueta }}
                                    <span v-if="op.ayuda" class="mt-0.5 block text-xs" :style="{ color: 'var(--color-suave)' }">{{ op.ayuda }}</span>
                                </span>
                            </label>

                            <!-- Meses sin intereses: qué plazos se ofrecen. -->
                            <div v-else :class="hayTarjeta(p) ? '' : 'opacity-50'">
                                <p class="text-sm font-medium">{{ op.etiqueta }}</p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    <button
                                        v-for="mes in op.valores"
                                        :key="mes"
                                        type="button"
                                        class="rounded-full border px-3 py-1 text-xs font-medium transition"
                                        :disabled="!hayTarjeta(p)"
                                        :style="tieneMes(p.clave, op.clave, mes)
                                            ? { backgroundColor: p.color, borderColor: p.color, color: '#fff' }
                                            : { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                                        @click="alternarMes(p.clave, op.clave, mes)"
                                    >
                                        {{ mes }} meses
                                    </button>
                                </div>
                                <p v-if="!hayTarjeta(p)" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    Los meses sin intereses los pone la tarjeta de crédito: enciende la tarjeta para poder ofrecerlos.
                                </p>
                                <p v-else-if="op.ayuda" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ op.ayuda }}</p>
                            </div>
                        </template>
                    </div>

                    <p v-if="sinFormasDePago(p)" class="mt-3 text-xs text-red-600">
                        Deja encendida al menos una forma de pago: sin ninguna, quien vaya a pagar no tendría con qué.
                    </p>
                </div>

                <div class="mt-5 flex items-center gap-3 border-t pt-4" :style="{ borderColor: 'var(--color-borde)' }">
                    <button
                        type="button"
                        :disabled="forms[p.clave].processing || sinFormasDePago(p)"
                        class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50"
                        :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                        @click="guardar(p)"
                    >
                        {{ forms[p.clave].processing ? 'Guardando…' : 'Guardar' }}
                    </button>
                    <span v-if="!completaAhora(p)" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Faltan datos requeridos para activar en {{ forms[p.clave].ambiente === 'produccion' ? 'producción' : 'pruebas' }}.
                    </span>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
