<script setup lang="ts">
import { computed, ref } from 'vue';

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
}

const props = withDefaults(
    defineProps<{
        matriculaId: number;
        adeudos: AdeudoPagable[];
        pasarelas: PasarelaDisponible[];
        /** Cargos marcados. Vacío = se pagan todos los que tengan saldo. */
        seleccionados?: number[];
    }>(),
    { seleccionados: () => [] },
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

/**
 * Manda a la pasarela.
 *
 * La respuesta trae una URL de OTRO dominio, así que el servidor no puede
 * redirigir: Inertia intentaría renderizar la página de la pasarela como si
 * fuera nuestra. Se pide la liga y se navega a mano.
 */
async function pagar(clave: string): Promise<void> {
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
            <strong>{{ pesos.format(total) }}</strong>
            <span :style="{ color: 'var(--color-suave)' }">
                ({{ aPagar.length === 1 ? '1 cargo' : `${aPagar.length} cargos` }}).
                <slot name="nota" />
            </span>
        </p>

        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <div v-for="p in pasarelas" :key="p.clave">
                <button
                    type="button"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition hover:brightness-110 disabled:opacity-60"
                    :style="{ backgroundColor: p.color ?? 'var(--color-acento)' }"
                    :disabled="yendoAPagar !== null || total <= 0"
                    @click="pagar(p.clave)"
                >
                    {{ yendoAPagar === p.clave ? 'Abriendo…' : `Pagar con ${p.nombre}` }}
                    <!-- Que se sepa que no es dinero real. -->
                    <span v-if="p.pruebas" class="rounded-full bg-white/25 px-1.5 py-0.5 text-xs">pruebas</span>
                </button>

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

        <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>

        <p class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">
            El cargo se aplica cuando la pasarela confirma el pago. Si se paga por SPEI o en tienda,
            puede tardar unas horas en reflejarse.
        </p>
    </div>
</template>
