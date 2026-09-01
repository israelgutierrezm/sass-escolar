<script setup lang="ts">
import { computed } from 'vue';
import BotonAccion from '@/Components/BotonAccion.vue';

/**
 * El encabezado de la ficha de una persona.
 *
 * Alumno y docente tenían el mismo bloque copiado —foto, nombre y la rejilla de
 * datos generales—, y el padre de familia no tenía ninguno: su ficha empezaba
 * con una tarjeta de «Identidad» que listaba tres datos sueltos y se veía como
 * otra pantalla del sistema. Toda persona tiene estos datos, así que el
 * encabezado es el mismo se entre por donde se entre.
 *
 * Lo que cambia entre fichas NO son los datos sino lo que las acompaña —la
 * clave de profesor, la matrícula, las píldoras de situación, el cumpleaños—, y
 * eso entra por slots. Si en su lugar se hubiera parametrizado con banderas
 * («muestraClave», «muestraSituacion»), cada ficha nueva agregaría una.
 */
interface PersonaEncabezado {
    nombre?: string | null;
    primer_apellido?: string | null;
    segundo_apellido?: string | null;
    /** Algunas pantallas ya traen el nombre armado; sirve para las iniciales. */
    nombre_completo?: string | null;
    curp?: string | null;
    rfc?: string | null;
    email?: string | null;
    correo_institucional?: string | null;
    celular?: string | null;
    telefono_local?: string | null;
    fecha_nacimiento?: string | null;
    entidad_nacimiento?: string | null;
    foto?: string | null;
}

const props = withDefaults(defineProps<{
    persona: PersonaEncabezado;
    /** Con esto aparecen «Subir foto» y «Quitar la foto»; sin esto, sólo se ve. */
    puedeEditarFoto?: boolean;
    errorFoto?: string | null;
}>(), {
    puedeEditarFoto: false,
    errorFoto: null,
});

/*
 * La foto la administra cada ficha: el alumno la sube a una ruta y el docente a
 * otra. El encabezado sólo avisa; no sabe a dónde va.
 */
const emit = defineEmits<{
    (e: 'subir-foto', archivo: File): void;
    (e: 'quitar-foto'): void;
}>();

const nombreCompleto = computed(() => {
    const armado = [props.persona.nombre, props.persona.primer_apellido, props.persona.segundo_apellido]
        .filter(Boolean)
        .join(' ');

    return armado || (props.persona.nombre_completo ?? '');
});

/** Las iniciales del avatar cuando no hay foto. */
const iniciales = computed(() => {
    const partes = nombreCompleto.value.trim().split(/\s+/).filter(Boolean);

    return ((partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? '')).toUpperCase();
});

/** Sólo los datos que la persona tiene: una ficha con «—» en todo no informa. */
const datos = computed(() => [
    { etiqueta: 'CURP', valor: props.persona.curp, mono: true },
    { etiqueta: 'RFC', valor: props.persona.rfc, mono: true },
    { etiqueta: 'Correo', valor: props.persona.email },
    { etiqueta: 'Correo institucional', valor: props.persona.correo_institucional },
    { etiqueta: 'Celular', valor: props.persona.celular },
    { etiqueta: 'Teléfono', valor: props.persona.telefono_local },
].filter((d) => Boolean(d.valor)));

function alElegirFoto(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];

    if (archivo) {
        emit('subir-foto', archivo);
    }
}
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col items-center gap-2">
            <img v-if="persona.foto" :src="persona.foto" alt="" class="h-24 w-24 rounded-full object-cover" />
            <span
                v-else
                class="flex h-24 w-24 items-center justify-center rounded-full text-2xl font-semibold"
                :style="{
                    backgroundColor: 'color-mix(in srgb, var(--color-acento) 14%, transparent)',
                    color: 'var(--color-acento)',
                }"
            >
                {{ iniciales }}
            </span>

            <div v-if="puedeEditarFoto" class="flex gap-2 text-xs">
                <label class="cursor-pointer" :style="{ color: 'var(--color-acento)' }">
                    {{ persona.foto ? 'Cambiar' : 'Subir foto' }}
                    <input type="file" accept="image/*" class="hidden" @change="alElegirFoto" />
                </label>
                <BotonAccion v-if="persona.foto" variante="eliminar" texto="Quitar la foto" @click="emit('quitar-foto')" />
            </div>
            <p v-if="errorFoto" class="text-xs text-red-600">{{ errorFoto }}</p>
        </div>

        <div class="min-w-0 flex-1">
            <!-- La clave con la que se le nombra: matrícula, clave de profesor. -->
            <slot name="identificador" />

            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-lg font-semibold">{{ nombreCompleto }}</h2>
                <slot name="insignias" />
            </div>

            <slot name="bajo-titulo" />

            <dl v-if="datos.length" class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div v-for="d in datos" :key="d.etiqueta" class="min-w-0">
                    <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ d.etiqueta }}</dt>
                    <dd class="truncate" :class="d.mono ? 'font-mono text-xs' : ''" :title="d.valor ?? undefined">{{ d.valor }}</dd>
                </div>

                <!-- La fecha va aparte porque arrastra la entidad de nacimiento. -->
                <div v-if="persona.fecha_nacimiento" class="min-w-0">
                    <dt class="text-xs" :style="{ color: 'var(--color-suave)' }">Nacimiento</dt>
                    <dd>
                        {{ persona.fecha_nacimiento }}
                        <span v-if="persona.entidad_nacimiento" :style="{ color: 'var(--color-suave)' }">
                            · {{ persona.entidad_nacimiento }}
                        </span>
                    </dd>
                </div>

                <slot name="datos-extra" />
            </dl>
        </div>
    </div>
</template>
