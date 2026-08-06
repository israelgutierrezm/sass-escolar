<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import { ICONOS } from '@/iconos';

/**
 * Contestar un formulario del expediente.
 *
 * Las preguntas y su tipo los definió la escuela en el constructor, así que
 * esta pantalla no sabe de antemano qué va a pintar: recorre los campos y
 * elige el control por su tipo.
 */
interface Campo {
    id: number;
    pregunta: string;
    descripcion: string | null;
    tipo: string | null;
    obligatorio: boolean;
    min: number | null;
    max: number | null;
    campo_padre_id: number | null;
    condicional: string | null;
    opciones: { valor: string; etiqueta: string }[];
}

const props = defineProps<{
    contexto: { titulo: string | null; volver: string };
    formulario: { id: number; titulo: string; instruccion: string | null };
    campos: Campo[];
    /** `documento` es el id de la respuesta, con el que se pide la descarga. */
    respuestas: Record<string, { valor: unknown; documento: number | null }>;
    accion: string;
    baseDescarga: string;
}>();

/** Lo ya contestado prellena; lo demás nace vacío según su tipo. */
const form = useForm<{ campos: Record<string, unknown> }>({
    campos: Object.fromEntries(
        props.campos.map((c) => {
            const guardado = props.respuestas[String(c.id)]?.valor;

            if (guardado !== undefined && guardado !== null) {
                return [String(c.id), guardado];
            }

            return [String(c.id), c.tipo === 'multiselect' ? [] : c.tipo === 'checkbox' ? false : ''];
        }),
    ),
});

/**
 * Un campo condicional sólo se muestra si su padre tiene el valor que lo
 * dispara.
 *
 * El servidor vuelve a mirarlo al validar: un campo que no está en pantalla no
 * puede ser obligatorio, y confiar sólo en el navegador dejaría el formulario
 * imposible de enviar en cuanto alguien tuviera JavaScript a medias.
 */
function visible(campo: Campo): boolean {
    if (campo.campo_padre_id === null) {
        return true;
    }

    return String(form.campos[String(campo.campo_padre_id)] ?? '') === String(campo.condicional);
}

const visibles = computed(() => props.campos.filter(visible));

const faltantes = computed(
    () => visibles.value.filter((c) => {
        if (!c.obligatorio) return false;

        const valor = form.campos[String(c.id)];

        // El documento cuenta como contestado si ya había uno subido: no se
        // vuelve a pedir cada vez que se corrige otra pregunta.
        if (c.tipo === 'documento') {
            return !valor && !props.respuestas[String(c.id)]?.documento;
        }

        return Array.isArray(valor) ? valor.length === 0 : valor === '' || valor === null;
    }).length,
);

function alternarOpcion(campo: Campo, valor: string): void {
    const actuales = (form.campos[String(campo.id)] as string[]) ?? [];

    form.campos[String(campo.id)] = actuales.includes(valor)
        ? actuales.filter((v) => v !== valor)
        : [...actuales, valor];
}

function tomarArchivo(campo: Campo, evento: Event): void {
    form.campos[String(campo.id)] = (evento.target as HTMLInputElement).files?.[0] ?? '';
}

function guardar(): void {
    // `forceFormData` siempre: un campo de tipo documento manda archivos, y
    // decidirlo según si hay uno haría que el mismo formulario viajara de dos
    // maneras distintas.
    form.post(props.accion, { forceFormData: true, preserveScroll: true });
}

/** El error de un campo, que el servidor devuelve como `campos.12`. */
function errorDe(campo: Campo): string | undefined {
    return (form.errors as Record<string, string>)[`campos.${campo.id}`];
}

function opcionesDe(campo: Campo) {
    return campo.opciones.map((o) => ({ valor: o.valor, texto: o.etiqueta }));
}
</script>

<template>
    <Head :title="formulario.titulo" />

    <AppLayout :titulo="formulario.titulo">
        <BotonVolver :href="contexto.volver" :texto="contexto.titulo ?? 'Volver'" class="mb-4" />

        <form @submit.prevent="guardar">
            <TarjetaSeccion
                :titulo="formulario.titulo"
                :descripcion="formulario.instruccion ?? undefined"
                :icono="ICONOS.tareaCheck"
            >
                <template #insignia>
                    <span
                        v-if="faltantes"
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        style="background-color: color-mix(in srgb, #f59e0b 14%, transparent); color: #b45309"
                    >Faltan {{ faltantes }} por contestar</span>
                    <span
                        v-else
                        class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                        style="background-color: color-mix(in srgb, #16a34a 12%, transparent); color: #16a34a"
                    >Completo</span>
                </template>

                <div v-if="campos.length" class="space-y-5">
                    <div v-for="campo in campos" :key="campo.id">
                        <!-- Los condicionales desaparecen en vez de deshabilitarse:
                             un campo gris que no se puede tocar hace preguntarse
                             qué falta para habilitarlo. -->
                        <template v-if="visible(campo)">
                            <!-- Casilla: la etiqueta va al lado, no encima. -->
                            <label v-if="campo.tipo === 'checkbox'" class="fila-casilla">
                                <input v-model="form.campos[String(campo.id)]" type="checkbox" class="mt-0.5" />
                                <span>
                                    <span class="block text-sm text-contenido">
                                        {{ campo.pregunta }}
                                        <span v-if="campo.obligatorio" class="text-red-500">*</span>
                                    </span>
                                    <span v-if="campo.descripcion" class="block text-xs text-suave">
                                        {{ campo.descripcion }}
                                    </span>
                                </span>
                            </label>

                            <!-- Opción única y selección múltiple: se pintan todas
                                 las opciones, no un desplegable. Con pocas, verlas
                                 es más rápido que abrirlas. -->
                            <div v-else-if="['radio', 'multiselect'].includes(campo.tipo ?? '')">
                                <p class="mb-1 text-sm font-medium">
                                    {{ campo.pregunta }}
                                    <span v-if="campo.obligatorio" class="text-red-500">*</span>
                                </p>
                                <p v-if="campo.descripcion" class="mb-2 text-xs text-suave">{{ campo.descripcion }}</p>

                                <label
                                    v-for="opcion in campo.opciones"
                                    :key="opcion.valor"
                                    class="fila-casilla text-sm"
                                >
                                    <input
                                        v-if="campo.tipo === 'radio'"
                                        v-model="form.campos[String(campo.id)]"
                                        type="radio"
                                        class="mt-0.5"
                                        :value="opcion.valor"
                                        :name="`campo-${campo.id}`"
                                    />
                                    <input
                                        v-else
                                        type="checkbox"
                                        class="mt-0.5"
                                        :checked="((form.campos[String(campo.id)] as string[]) ?? []).includes(opcion.valor)"
                                        @change="alternarOpcion(campo, opcion.valor)"
                                    />
                                    <span>{{ opcion.etiqueta }}</span>
                                </label>
                            </div>

                            <!-- Documento -->
                            <div v-else-if="campo.tipo === 'documento'">
                                <p class="mb-1 text-sm font-medium">
                                    {{ campo.pregunta }}
                                    <span v-if="campo.obligatorio" class="text-red-500">*</span>
                                </p>
                                <p v-if="campo.descripcion" class="mb-2 text-xs text-suave">{{ campo.descripcion }}</p>

                                <input
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    class="text-sm"
                                    @change="tomarArchivo(campo, $event)"
                                />
                                <p v-if="respuestas[String(campo.id)]?.documento" class="mt-1 text-xs text-suave">
                                    <!-- El archivo entraba y no salía: quedaba
                                         en el disco privado sin forma de verlo,
                                         y quien revisa necesita abrirlo. -->
                                    <a
                                        :href="`${baseDescarga}/${respuestas[String(campo.id)]!.documento}/documento`"
                                        class="hover:underline"
                                        :style="{ color: 'var(--color-acento)' }"
                                    >Ver el que está cargado</a>
                                    · sube otro sólo si quieres reemplazarlo.
                                </p>
                            </div>

                            <CampoSelect
                                v-else-if="campo.tipo === 'select'"
                                v-model="form.campos[String(campo.id)]"
                                :etiqueta="campo.pregunta"
                                :requerido="campo.obligatorio"
                                :ayuda="campo.descripcion ?? undefined"
                                vacio="Elige…"
                                :opciones="opcionesDe(campo)"
                                :error="errorDe(campo)"
                            />

                            <!-- Texto largo: `CampoTexto` es siempre un <input>
                                 de una línea, y una respuesta abierta necesita
                                 varias. Se pinta aquí en vez de agregarle un
                                 modo al componente que usa media aplicación. -->
                            <div v-else-if="campo.tipo === 'textarea'">
                                <label class="mb-1 block text-sm font-medium text-contenido">
                                    {{ campo.pregunta }}
                                    <span v-if="campo.obligatorio" class="text-red-500">*</span>
                                </label>
                                <textarea
                                    v-model="form.campos[String(campo.id)]"
                                    rows="4"
                                    class="w-full rounded-lg border px-3 py-2 text-sm"
                                    :style="{ borderColor: 'var(--color-borde)' }"
                                />
                                <p v-if="campo.descripcion && !errorDe(campo)" class="mt-1 text-xs text-suave">
                                    {{ campo.descripcion }}
                                </p>
                                <p v-if="errorDe(campo)" class="mt-1 text-xs text-red-600">{{ errorDe(campo) }}</p>
                            </div>

                            <CampoTexto
                                v-else
                                v-model="form.campos[String(campo.id)]"
                                :etiqueta="campo.pregunta"
                                :requerido="campo.obligatorio"
                                :ayuda="campo.descripcion ?? undefined"
                                :tipo="campo.tipo === 'numero' ? 'number'
                                    : campo.tipo === 'fecha' ? 'date'
                                    : campo.tipo === 'email' ? 'email'
                                    : campo.tipo === 'telefono' ? 'tel'
                                    : 'text'"
                                :error="errorDe(campo)"
                            />

                            <!-- Los controles que no son CampoTexto/CampoSelect
                                 no pintan su propio error. -->
                            <p
                                v-if="errorDe(campo) && ['checkbox', 'radio', 'multiselect', 'documento'].includes(campo.tipo ?? '')"
                                class="mt-1 text-xs text-red-600"
                            >{{ errorDe(campo) }}</p>
                        </template>
                    </div>
                </div>

                <p v-else class="text-sm text-amber-700">
                    Este formulario todavía no tiene preguntas, así que no hay nada que contestar.
                    Agrégalas desde su constructor.
                </p>

                <template v-if="campos.length" #pie>
                    <div class="flex items-center gap-3">
                        <BotonPrincipal tipo="button" :procesando="form.processing" texto="Guardar" @click="guardar" />
                        <a
                            :href="contexto.volver"
                            class="rounded-lg border border-borde px-4 py-2 text-sm text-contenido hover:bg-fondo"
                        >
                            Cancelar
                        </a>
                        <!-- Se puede guardar a medias: quien atiende en ventanilla
                             captura lo que el interesado trae y completa después.
                             Bloquearlo hasta tenerlo todo obligaría a apuntarlo en
                             un papel mientras tanto. -->
                        <span v-if="faltantes" class="text-xs text-suave">
                            Puedes guardar incompleto y terminar después.
                        </span>
                    </div>
                </template>
            </TarjetaSeccion>
        </form>
    </AppLayout>
</template>
