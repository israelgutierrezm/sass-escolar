<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

interface Solicitud {
    id: number;
    servicio: string | null;
    alumno: string | null;
    matricula: string | null;
    estado: string;
    esperando_pago: boolean;
    saldo: number | null;
    nota_alumno: string | null;
    respuesta: string | null;
    pedida_en: string | null;
}

interface Servicio {
    id: number;
    nombre: string;
    precio: number;
    tiene_costo: boolean;
    solicitable: boolean;
    instrucciones: string | null;
}

const props = defineProps<{ solicitudes: Solicitud[]; servicios: Servicio[] }>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

/** Lo que hay que trabajar hoy: pedido, y sin nada pendiente de cobro. */
const paraTrabajar = computed(() =>
    props.solicitudes.filter((s) => s.estado === 'pedida' && !s.esperando_pago),
);

const esperandoPago = computed(() =>
    props.solicitudes.filter((s) => s.estado === 'pedida' && s.esperando_pago),
);

const cerradas = computed(() => props.solicitudes.filter((s) => s.estado !== 'pedida'));

const respuestas = ref<Record<number, string>>({});

function resolver(solicitud: Solicitud, estado: 'atendida' | 'rechazada'): void {
    router.put(
        `/escolar/servicios/${solicitud.id}`,
        { estado, respuesta: respuestas.value[solicitud.id] ?? null },
        { preserveScroll: true },
    );
}

function ofrecer(servicio: Servicio, solicitable: boolean): void {
    router.put(
        `/escolar/servicios/catalogo/${servicio.id}`,
        { solicitable, instrucciones: servicio.instrucciones },
        { preserveScroll: true },
    );
}

function guardarInstrucciones(servicio: Servicio, instrucciones: string): void {
    router.put(
        `/escolar/servicios/catalogo/${servicio.id}`,
        { solicitable: servicio.solicitable, instrucciones },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Solicitudes de servicio" />

    <AppLayout titulo="Solicitudes de servicio">
        <section class="tarjeta p-6">
            <h2 class="text-base font-semibold">El mostrador</h2>
            <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Aquí decides qué puede pedir el alumno y resuelves lo que pide. El
                <strong>precio</strong> de cada servicio se configura en Finanzas; lo que se ve aquí
                es sólo para saber de qué se está hablando.
            </p>
            <p class="mt-2 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                Un trámite con costo no se puede marcar como atendido hasta que el pago entre. Lo que
                está esperando pago se muestra aparte para que no estorbe en la fila de trabajo.
            </p>
        </section>

        <TarjetaSeccion :titulo="`Por atender (${paraTrabajar.length})`" :icono="ICONOS.ajustes">
            <p v-if="!paraTrabajar.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Nada pendiente. Buen día.
            </p>

            <ul v-else class="space-y-3">
                <li
                    v-for="solicitud in paraTrabajar"
                    :key="solicitud.id"
                    class="border-t pt-3 first:border-0 first:pt-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <p class="text-sm font-medium">
                        {{ solicitud.servicio }}
                        <span class="ml-1 font-normal" :style="{ color: 'var(--color-suave)' }">
                            — {{ solicitud.alumno }} ({{ solicitud.matricula }})
                        </span>
                    </p>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Pedido el {{ solicitud.pedida_en }}
                        <template v-if="solicitud.nota_alumno"> · «{{ solicitud.nota_alumno }}»</template>
                    </p>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <input
                            v-model="respuestas[solicitud.id]"
                            type="text"
                            placeholder="Nota para el alumno (opcional)"
                            class="min-w-0 flex-1 rounded-lg border px-3 py-1.5 text-sm"
                        />
                        <button
                            type="button"
                            class="rounded-full px-3 py-1.5 text-xs font-semibold text-white"
                            :style="{ backgroundColor: 'var(--color-acento)' }"
                            @click="resolver(solicitud, 'atendida')"
                        >
                            Atendida
                        </button>
                        <button
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-xs text-red-600"
                            @click="resolver(solicitud, 'rechazada')"
                        >
                            No procede
                        </button>
                    </div>
                </li>
            </ul>
        </TarjetaSeccion>

        <TarjetaSeccion
            v-if="esperandoPago.length"
            :titulo="`Esperando pago (${esperandoPago.length})`"
            :icono="ICONOS.ajustes"
        >
            <ul class="space-y-1">
                <li
                    v-for="solicitud in esperandoPago"
                    :key="solicitud.id"
                    class="flex flex-wrap items-center gap-3 border-t py-2 first:border-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span class="min-w-0 flex-1 text-sm">
                        {{ solicitud.servicio }}
                        <span :style="{ color: 'var(--color-suave)' }">
                            — {{ solicitud.alumno }} ({{ solicitud.matricula }})
                        </span>
                    </span>
                    <span class="shrink-0 text-sm font-semibold tabular-nums text-red-700">
                        {{ solicitud.saldo !== null ? pesos.format(solicitud.saldo) : '' }}
                    </span>
                    <button
                        type="button"
                        class="shrink-0 rounded-full border px-3 py-1 text-xs text-red-600"
                        @click="resolver(solicitud, 'rechazada')"
                    >
                        No procede
                    </button>
                </li>
            </ul>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Qué se puede pedir" :icono="ICONOS.ajustes">
            <p class="mb-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sólo los servicios activos del catálogo de Finanzas.
            </p>

            <ul class="space-y-2">
                <li
                    v-for="servicio in servicios"
                    :key="servicio.id"
                    class="border-t pt-2 first:border-0 first:pt-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <label class="flex items-center justify-between gap-3">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium">{{ servicio.nombre }}</span>
                            <span class="block text-xs tabular-nums" :style="{ color: 'var(--color-suave)' }">
                                {{ servicio.tiene_costo ? pesos.format(servicio.precio) : 'Sin costo' }}
                            </span>
                        </span>
                        <input
                            type="checkbox"
                            class="h-5 w-5 shrink-0 rounded"
                            :checked="servicio.solicitable"
                            @change="ofrecer(servicio, ($event.target as HTMLInputElement).checked)"
                        />
                    </label>

                    <input
                        v-if="servicio.solicitable"
                        type="text"
                        :value="servicio.instrucciones ?? ''"
                        placeholder="Instrucciones para el alumno (qué traer, cuánto tarda…)"
                        class="mt-1 w-full rounded-lg border px-3 py-1.5 text-xs"
                        @change="guardarInstrucciones(servicio, ($event.target as HTMLInputElement).value)"
                    />
                </li>
            </ul>
        </TarjetaSeccion>

        <TarjetaSeccion v-if="cerradas.length" titulo="Cerradas" :icono="ICONOS.ajustes">
            <ul class="space-y-1">
                <li
                    v-for="solicitud in cerradas"
                    :key="solicitud.id"
                    class="flex flex-wrap items-center gap-3 border-t py-2 text-sm first:border-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span class="min-w-0 flex-1 truncate">
                        {{ solicitud.servicio }}
                        <span :style="{ color: 'var(--color-suave)' }">— {{ solicitud.alumno }}</span>
                    </span>
                    <span class="shrink-0 text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ solicitud.estado }}
                        <template v-if="solicitud.respuesta"> · {{ solicitud.respuesta }}</template>
                    </span>
                </li>
            </ul>
        </TarjetaSeccion>
    </AppLayout>
</template>
