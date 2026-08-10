<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

interface Servicio {
    id: number;
    nombre: string;
    descripcion: string | null;
    precio: number;
    tiene_costo: boolean;
    instrucciones: string | null;
}

interface Solicitud {
    id: number;
    servicio: string | null;
    estado: string;
    esperando_pago: boolean;
    saldo: number | null;
    adeudo_id: number | null;
    matricula_oferta_id: number;
    respuesta: string | null;
    cancelable: boolean;
    pedida_en: string | null;
}

const props = defineProps<{
    catalogo: Servicio[];
    matriculas: { id: number; matricula: string }[];
    solicitudes: Solicitud[];
    /** Falso cuando quien mira es la escuela revisando su propio catálogo. */
    puedePedir: boolean;
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const eligiendo = ref<Servicio | null>(null);

const formulario = useForm({
    servicio_id: 0,
    // Casi siempre hay una sola; quien estudia dos carreras elige.
    matricula_oferta_id: props.matriculas[0]?.id ?? 0,
    nota: '',
});

function pedir(servicio: Servicio): void {
    eligiendo.value = servicio;
    formulario.servicio_id = servicio.id;
    formulario.nota = '';
    formulario.clearErrors();
}

function confirmar(): void {
    formulario.post('/servicios', {
        preserveScroll: true,
        onSuccess: () => {
            eligiendo.value = null;
        },
    });
}

function cancelar(solicitud: Solicitud): void {
    router.delete(`/servicios/${solicitud.id}`, { preserveScroll: true });
}

/** Lo que hay que decirle a la persona sobre CADA trámite suyo. */
function comoVa(solicitud: Solicitud): string {
    if (solicitud.estado === 'atendida') return 'Listo';
    if (solicitud.estado === 'rechazada') return 'No procedió';
    if (solicitud.estado === 'cancelada') return 'Cancelada';

    return solicitud.esperando_pago ? 'Falta tu pago' : 'En proceso';
}
</script>

<template>
    <Head title="Servicios y trámites" />

    <AppLayout titulo="Servicios y trámites">
        <!--
            Primero lo suyo y después el catálogo.

            Quien entra aquí casi siempre viene a ver cómo va lo que ya pidió
            —es lo que anuncia la tarjeta del panel—, no a pedir algo nuevo.
        -->
        <TarjetaSeccion v-if="solicitudes.length" titulo="Mis trámites" :icono="ICONOS.ajustes">
            <ul class="space-y-1">
                <li
                    v-for="solicitud in solicitudes"
                    :key="solicitud.id"
                    class="flex flex-wrap items-center gap-3 border-t py-2 first:border-0"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ solicitud.servicio }}</span>
                        <span class="block text-xs" :style="{ color: 'var(--color-suave)' }">
                            Pedido el {{ solicitud.pedida_en }}
                            <template v-if="solicitud.respuesta"> · {{ solicitud.respuesta }}</template>
                        </span>
                    </span>

                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="solicitud.esperando_pago ? 'bg-red-50 text-red-700' : ''"
                        :style="solicitud.esperando_pago ? {} : { color: 'var(--color-suave)' }"
                    >
                        {{ comoVa(solicitud) }}
                    </span>

                    <!--
                        El pago no se resuelve aquí: se manda al estado de
                        cuenta, que es donde ya viven la pasarela y la carga del
                        comprobante. Repetir ese flujo en esta pantalla sería
                        mantener dos caminos para cobrar lo mismo.
                    -->
                    <a
                        v-if="solicitud.esperando_pago"
                        :href="`/finanzas/cuentas/${solicitud.matricula_oferta_id}`"
                        class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                    >
                        Pagar {{ solicitud.saldo !== null ? pesos.format(solicitud.saldo) : '' }}
                    </a>

                    <button
                        v-if="solicitud.cancelable"
                        type="button"
                        class="shrink-0 rounded-full border px-3 py-1 text-xs"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="cancelar(solicitud)"
                    >
                        Cancelar
                    </button>
                </li>
            </ul>
        </TarjetaSeccion>

        <TarjetaSeccion
            :titulo="puedePedir ? 'Qué puedes pedir' : 'Qué ve el alumno'"
            :icono="ICONOS.ajustes"
        >
            <!--
                Quien atiende el mostrador entra aquí a revisar cómo le quedó su
                catálogo, así que se le dice desde dónde está mirando en vez de
                dejarle un botón que le rebotaría.
            -->
            <p v-if="!puedePedir" class="mb-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                Estás viendo el catálogo tal como le aparece al alumno. Para cambiar qué se ofrece o
                sus instrucciones, ve a <strong>Solicitudes de servicio</strong> en Control Escolar.
            </p>

            <p v-if="!catalogo.length" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Tu escuela todavía no ofrece trámites por aquí.
            </p>

            <div v-else class="cuadricula-listado">
                <div v-for="servicio in catalogo" :key="servicio.id" class="tarjeta flex flex-col gap-2 p-4">
                    <p class="text-sm font-medium">{{ servicio.nombre }}</p>
                    <p v-if="servicio.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ servicio.descripcion }}
                    </p>
                    <p class="mt-auto text-sm font-semibold tabular-nums">
                        {{ servicio.tiene_costo ? pesos.format(servicio.precio) : 'Sin costo' }}
                    </p>
                    <button
                        v-if="puedePedir"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-xs font-semibold text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        @click="pedir(servicio)"
                    >
                        Solicitar
                    </button>
                    <p v-if="servicio.instrucciones" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ servicio.instrucciones }}
                    </p>
                </div>
            </div>
        </TarjetaSeccion>

        <!-- La confirmación, con lo que va a pasar dicho antes de pulsar. -->
        <TarjetaSeccion v-if="eligiendo" :titulo="`Solicitar: ${eligiendo.nombre}`" :icono="ICONOS.ajustes">
            <form class="space-y-4" @submit.prevent="confirmar">
                <p v-if="eligiendo.instrucciones" class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{ eligiendo.instrucciones }}
                </p>

                <p v-if="eligiendo.tiene_costo" class="text-sm">
                    Al solicitarlo se te genera un cargo de
                    <strong>{{ pesos.format(eligiendo.precio) }}</strong> en tu estado de cuenta. El
                    trámite empieza cuando el pago quede registrado; puedes pagarlo en línea o subir
                    tu comprobante desde ahí.
                </p>
                <p v-else class="text-sm">
                    Este trámite no tiene costo: en cuanto lo solicites entra a la fila.
                </p>

                <label v-if="matriculas.length > 1" class="block">
                    <span class="text-sm font-medium">¿A cuál de tus matrículas?</span>
                    <select
                        v-model.number="formulario.matricula_oferta_id"
                        class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                    >
                        <option v-for="m in matriculas" :key="m.id" :value="m.id">{{ m.matricula }}</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-sm font-medium">¿Algo que debamos saber? (opcional)</span>
                    <input v-model="formulario.nota" type="text" class="mt-1 w-full rounded-lg border px-3 py-2 text-sm" />
                </label>

                <p v-if="formulario.errors.servicio_id" class="text-xs text-red-600">
                    {{ formulario.errors.servicio_id }}
                </p>

                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="rounded-full px-4 py-2 text-sm font-semibold text-white"
                        :style="{ backgroundColor: 'var(--color-acento)' }"
                        :disabled="formulario.processing"
                    >
                        Confirmar solicitud
                    </button>
                    <button
                        type="button"
                        class="rounded-full border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="eligiendo = null"
                    >
                        Cancelar
                    </button>
                </div>
            </form>
        </TarjetaSeccion>
    </AppLayout>
</template>
