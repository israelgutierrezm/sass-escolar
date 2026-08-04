<script setup lang="ts">
/**
 * Los resultados de una encuesta, pregunta por pregunta.
 *
 * ── Cada tipo se pinta como se lee ─────────────────────────────────────────
 * La escala lleva su promedio Y su distribución, porque un 3.5 puede ser
 * «todos regulares» o «la mitad encantada y la mitad furiosa», y son dos
 * situaciones que piden cosas distintas. Las opciones van con barra y
 * porcentaje. Las abiertas se listan tal cual: no hay forma de resumirlas sin
 * decidir por quien las escribió.
 *
 * Componente aparte porque lo usan la vista general de la aplicación y la de
 * cada docente evaluado, y son exactamente los mismos datos.
 */
defineProps<{ resultados: Record<string, any> }>();
</script>

<template>
    <section v-if="resultados.oculto" class="tarjeta p-6 text-center">
        <p class="text-sm text-contenido">Todavía no se pueden mostrar los resultados.</p>
        <!-- Se dice el motivo, no sólo que está oculto: sin él parece un fallo
             del sistema y alguien va a pedir que se lo enseñen. -->
        <p class="mx-auto mt-1 max-w-lg text-xs text-suave">
            Hay {{ resultados.respuestas }}
            {{ resultados.respuestas === 1 ? 'respuesta' : 'respuestas' }} y hacen falta al menos
            {{ resultados.minimo }}. Con menos, el desglose permitiría deducir quién contestó qué, y
            la encuesta se ofreció como anónima.
        </p>
    </section>

    <section v-else-if="resultados.respuestas === 0" class="tarjeta px-6 py-12 text-center text-sm text-suave">
        Nadie ha contestado todavía.
    </section>

    <section v-else class="space-y-3">
        <article v-for="p in resultados.preguntas" :key="p.id" class="tarjeta p-5">
            <h3 class="font-medium text-contenido">{{ p.texto }}</h3>
            <p class="mt-0.5 text-xs text-suave">{{ p.tipo_etiqueta }}</p>

            <!-- Escala y número: promedio, extremos y reparto. -->
            <div v-if="p.promedio !== undefined" class="mt-3">
                <div class="flex flex-wrap items-baseline gap-3">
                    <span class="text-2xl font-semibold tabular-nums" :style="{ color: 'var(--color-acento)' }">
                        {{ p.promedio ?? '—' }}
                    </span>
                    <span v-if="p.escala_maxima" class="text-sm text-suave">de {{ p.escala_maxima }}</span>
                    <span class="text-xs text-suave">
                        {{ p.contestadas }} {{ p.contestadas === 1 ? 'respuesta' : 'respuestas' }}
                        <template v-if="p.minimo !== null"> · entre {{ p.minimo }} y {{ p.maximo }}</template>
                    </span>
                </div>

                <div v-if="p.distribucion.length" class="mt-3 space-y-1.5">
                    <div v-for="d in p.distribucion" :key="d.valor" class="flex items-center gap-2 text-xs">
                        <span class="w-8 shrink-0 text-right tabular-nums text-suave">{{ d.valor }}</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-[color-mix(in_srgb,var(--color-suave)_15%,transparent)]">
                            <div
                                class="h-full rounded-full"
                                :style="{
                                    width: `${Math.round((d.total / p.contestadas) * 100)}%`,
                                    backgroundColor: 'var(--color-acento)',
                                }"
                            />
                        </div>
                        <span class="w-10 shrink-0 tabular-nums text-suave">{{ d.total }}</span>
                    </div>
                </div>
            </div>

            <!-- Opciones: cuántos y qué proporción. -->
            <ul v-else-if="p.opciones" class="mt-3 space-y-1.5">
                <li v-for="(o, i) in p.opciones" :key="i" class="flex items-center gap-2 text-xs">
                    <span class="w-40 shrink-0 truncate">{{ o.texto }}</span>
                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-[color-mix(in_srgb,var(--color-suave)_15%,transparent)]">
                        <div
                            class="h-full rounded-full"
                            :style="{ width: `${o.porcentaje}%`, backgroundColor: 'var(--color-acento)' }"
                        />
                    </div>
                    <span class="w-20 shrink-0 text-right tabular-nums text-suave">
                        {{ o.total }} ({{ o.porcentaje }}%)
                    </span>
                </li>
            </ul>

            <!-- Abiertas: se leen. -->
            <template v-else-if="p.textos">
                <ul v-if="p.textos.length" class="mt-3 space-y-2">
                    <li
                        v-for="(t, i) in p.textos"
                        :key="i"
                        class="rounded-lg border-l-2 bg-[color-mix(in_srgb,var(--color-suave)_5%,transparent)] px-3 py-2 text-sm"
                        :style="{ borderLeftColor: 'var(--color-borde)' }"
                    >
                        {{ t }}
                    </li>
                </ul>
                <p v-else class="mt-3 text-xs text-suave">Sin respuestas escritas.</p>
            </template>
        </article>
    </section>
</template>
