<script setup lang="ts">
/**
 * El control de UN filtro de reporte, según su tipo.
 *
 * ── Escrito una vez porque lo dibujan DOS pantallas ────────────────────────
 * La de correr un reporte y la del CONSTRUCTOR, donde se elige qué valor queda
 * FIJO. Con el `v-if` por tipo copiado en las dos, la segunda nace ya
 * divergida: un tipo nuevo se atiende en una y en la otra cae a caja de texto.
 *
 * ── Y al extraerlo salió un defecto de la primera ──────────────────────────
 * `lista` —un filtro de catálogo de UN valor— no tenía rama: caía al `v-else` y
 * se dibujaba como caja de TEXTO, o sea que había que teclear a mano el valor
 * exacto («descartado», el id de un ciclo) y equivocarse daba un error de
 * validación en vez de una lista. Son tres filtros del sistema —el desenlace
 * del aspirante, el tipo de carga y el ciclo del docente—, y ninguno se podía
 * usar sin adivinar. Ahora es un desplegable con sus opciones.
 */
const props = defineProps<{
    tipo: string;
    /** Opciones del catálogo, por valor. Sólo en `lista` y `lista_multiple`. */
    opciones?: Record<string | number, string>;
    deshabilitado?: boolean;
    /** Texto de la opción vacía en los desplegables de un solo valor. */
    vacio?: string;
}>();

const modelo = defineModel<unknown>();

/** ¿Ese valor está elegido? Se compara como texto: del navegador llegan cadenas. */
function elegido(valor: string | number): boolean {
    const v = modelo.value;

    return Array.isArray(v)
        ? v.some((x) => String(x) === String(valor))
        : String(v ?? '') === String(valor);
}

/*
 * Un `false` explícito y no vacío: el motor sólo salta null, cadena vacía y
 * arreglo vacío, así que un `false` SÍ llega a la closure del filtro.
 */
function alternarBooleano(marcado: boolean): void {
    modelo.value = marcado ? '1' : '';
}

/**
 * Si un filtro booleano está puesto.
 *
 * El motor acepta '1', 1, true y 'true' según de dónde venga —de la URL llega
 * como cadena y de una vista guardada como booleano—, así que se miran todos.
 */
function esVerdadero(valor: unknown): boolean {
    return valor === true || valor === 1 || valor === '1' || valor === 'true';
}
</script>

<template>
    <select
        v-if="tipo === 'lista_multiple'"
        multiple
        class="h-20 w-full rounded-lg border border-borde px-2 py-1 text-xs"
        :disabled="deshabilitado"
        @change="modelo = Array.from(($event.target as HTMLSelectElement).selectedOptions).map((o) => o.value)"
    >
        <option
            v-for="(etiqueta, valor) in opciones ?? {}"
            :key="valor"
            :value="valor"
            :selected="elegido(valor)"
        >{{ etiqueta }}</option>
    </select>

    <!-- Un catálogo de UN valor es un desplegable, no una caja donde adivinar. -->
    <select
        v-else-if="tipo === 'lista'"
        class="w-full rounded-lg border border-borde px-2 py-1.5 text-sm"
        :disabled="deshabilitado"
        :value="modelo ?? ''"
        @change="modelo = ($event.target as HTMLSelectElement).value"
    >
        <option value="">{{ vacio ?? 'Cualquiera' }}</option>
        <option v-for="(etiqueta, valor) in opciones ?? {}" :key="valor" :value="valor">{{ etiqueta }}</option>
    </select>

    <!-- Un sí/no es una CASILLA. Como caja de texto había que adivinar que se
         escribe «1» dentro. -->
    <label
        v-else-if="tipo === 'booleano'"
        class="flex items-center gap-2 py-1.5 text-sm"
        :class="deshabilitado ? 'opacity-60' : 'cursor-pointer'"
    >
        <input
            type="checkbox"
            class="h-4 w-4 rounded border-borde"
            :disabled="deshabilitado"
            :checked="esVerdadero(modelo)"
            @change="alternarBooleano(($event.target as HTMLInputElement).checked)"
        />
        <span>Sí</span>
    </label>

    <input
        v-else
        :type="tipo === 'fecha' ? 'date' : tipo === 'numero' ? 'number' : 'text'"
        step="any"
        class="w-full rounded-lg border border-borde px-2 py-1.5 text-sm"
        :disabled="deshabilitado"
        :value="modelo ?? ''"
        @input="modelo = ($event.target as HTMLInputElement).value"
    />
</template>
