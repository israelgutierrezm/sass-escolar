<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Con qué paga la escuela sus XML, dicho donde se firma.
 *
 * ── Por qué aquí y no sólo en la pantalla de créditos ──────────────────────
 * Firmar un lote es lo que gasta. Si el saldo sólo se ve en otra pantalla, uno
 * arma un lote de cuarenta egresados y descubre al pulsar «Firmar» que le
 * quedaban doce créditos. La cuenta cabe en una línea: enseñarla antes cuesta
 * nada y evita rehacer el trabajo.
 *
 * En postpago e ilimitado no hay contador que enseñar —no hay nada que se
 * agote—, así que se dice la modalidad y ya. Un «0 créditos» junto a una
 * escuela que no paga por documento sólo asusta.
 */
const props = defineProps<{
    saldo: {
        modalidad: string;
        etiqueta: string;
        creditos: number;
        cuenta_creditos: boolean;
        explicacion: string;
    };
    /** Cuántos XML emitiría el lote que se está viendo, si se sabe. */
    porEmitir?: number;
}>();

/** Sin créditos no se firma: es el único caso que hay que destacar. */
const enRojo = computed(() => props.saldo.cuenta_creditos && props.saldo.creditos <= 0);

const noAlcanza = computed(() =>
    props.saldo.cuenta_creditos
    && props.porEmitir !== undefined
    && props.porEmitir > props.saldo.creditos);

const color = computed(() => (enRojo.value || noAlcanza.value ? '#dc2626' : 'var(--color-contenido)'));
</script>

<template>
    <div
        class="tarjeta flex flex-wrap items-center justify-between gap-4 px-5 py-4"
        :style="enRojo || noAlcanza
            ? { borderColor: '#dc2626', backgroundColor: 'color-mix(in srgb, #dc2626 5%, transparent)' }
            : {}"
    >
        <div class="flex items-center gap-6">
            <div>
                <p class="text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)' }">
                    Modalidad
                </p>
                <p class="text-lg font-semibold">{{ saldo.etiqueta }}</p>
            </div>

            <div v-if="saldo.cuenta_creditos">
                <p class="text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)' }">
                    XML disponibles
                </p>
                <p class="text-lg font-semibold tabular-nums" :style="{ color }">{{ saldo.creditos }}</p>
            </div>
        </div>

        <div class="min-w-0 flex-1 text-right">
            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ saldo.explicacion }}</p>

            <!--
                Sólo cuando el lote no cabe en el saldo: decirlo con el número
                exacto ahorra la resta y el susto.
            -->
            <p v-if="noAlcanza" class="mt-0.5 text-xs font-medium text-red-600">
                Este lote necesita {{ porEmitir }} y quedan {{ saldo.creditos }}:
                faltan {{ (porEmitir ?? 0) - saldo.creditos }}.
            </p>

            <!--
                Sólo el prepago compra. A quien no paga por documento ofrecerle
                «comprar» le hace pensar que sí, y a los dos les sirve ver el
                consumo, así que ese es el destino en ambos casos.
            -->
            <Link href="/plataforma/creditos" class="mt-1 inline-block text-xs font-medium" :style="{ color: 'var(--color-acento)' }">
                {{ saldo.cuenta_creditos ? 'Ver créditos y comprar' : 'Ver lo emitido' }}
            </Link>
        </div>
    </div>
</template>
