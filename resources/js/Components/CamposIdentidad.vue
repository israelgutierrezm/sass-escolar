<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import { consultar } from '@/consultas';

/**
 * El bloque de identidad que comparten TODOS los formularios de personas.
 *
 * Existe porque la misma captura estaba escrita seis veces —aspirante, alumno,
 * docente, expediente docente, usuario, formulario público— y cada copia
 * preguntaba cosas distintas y validaba distinto. Tres decisiones viven aquí:
 *
 * 1. **La CURP se lee, no solo se guarda.** Al teclearla completa se llenan
 *    solos fecha de nacimiento, género y entidad, porque la CURP los trae
 *    dentro y su dígito verificador dice si está bien escrita. Lo llenado queda
 *    EDITABLE: hay CURP mal emitidas y actas que las corrigen.
 * 2. **`EXTRANJERO` es una respuesta válida.** Quien no tiene CURP la escribe y
 *    entonces —y solo entonces— aparece el país de nacimiento. Con CURP el país
 *    se obvia: es México.
 * 3. **No se pregunta el sexo.** Se deriva de la CURP o del género en el
 *    servidor. Preguntar sexo Y género era pedir dos veces lo mismo.
 */
interface Opcion {
    id: number;
    nombre: string;
}

const props = defineProps<{
    /** El objeto de `useForm`; se leen y escriben sus claves de persona. */
    form: Record<string, any>;
    generos: Opcion[];
    entidades: Opcion[];
    entidadExtranjero: Opcion | null;
    paises: Opcion[];
    /** Al editar, para no reportarse como duplicado de sí mismo. */
    personaId?: number | null;
    /** El correo es la credencial de acceso; casi siempre obligatorio. */
    correoRequerido?: boolean;
}>();

interface Ficha {
    id: number;
    nombre_completo: string;
    curp: string | null;
    email: string | null;
    coincide_por: string;
}

interface Analisis {
    estado: 'valida' | 'invalida' | 'extranjero' | 'vacia';
    mensaje?: string;
    fecha_nacimiento?: string | null;
    genero_id?: number | null;
    entidad_nacimiento_id?: number | null;
    pais_nacimiento_id?: number | null;
    persona_existente?: Ficha | null;
}

const analisis = ref<Analisis | null>(null);
const duplicados = ref<Ficha[]>([]);

const esExtranjero = computed(
    () =>
        analisis.value?.estado === 'extranjero' ||
        (props.entidadExtranjero !== null &&
            props.form.entidad_nacimiento_id === props.entidadExtranjero.id),
);

/** Con CURP no se pregunta el país: tenerla implica registro en México. */
const pidePais = computed(() => esExtranjero.value);

const opcionesEntidad = computed(() => [
    // El extranjero va ARRIBA, pegado a «sin especificar», no perdido en la N
    // entre Nayarit y Nuevo León: es una respuesta de otra naturaleza.
    ...(props.entidadExtranjero ? [{ valor: props.entidadExtranjero.id, texto: props.entidadExtranjero.nombre }] : []),
    ...props.entidades.map((e) => ({ valor: e.id, texto: e.nombre })),
]);

let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.form.curp,
    () => {
        clearTimeout(temporizador);
        temporizador = setTimeout(leerCurp, 400);
    },
);

async function leerCurp(): Promise<void> {
    const texto = String(props.form.curp ?? '').trim();

    if (texto === '') {
        analisis.value = null;

        return;
    }

    try {
        const datos = await consultar<Analisis>('/identidad/curp', {
            curp: texto,
            persona_id: props.personaId ?? null,
        });

        analisis.value = datos;

        if (datos.estado === 'extranjero' && props.entidadExtranjero) {
            props.form.entidad_nacimiento_id = props.entidadExtranjero.id;

            return;
        }

        if (datos.estado !== 'valida') {
            return;
        }

        // Se rellena solo lo que esté vacío… salvo la fecha, la entidad y el
        // género, que la CURP conoce con más certeza que quien captura. Aun así
        // quedan editables después.
        props.form.fecha_nacimiento = datos.fecha_nacimiento ?? props.form.fecha_nacimiento;
        props.form.entidad_nacimiento_id = datos.entidad_nacimiento_id ?? props.form.entidad_nacimiento_id;
        props.form.genero_id = props.form.genero_id ?? datos.genero_id ?? null;

        if ('pais_nacimiento_id' in props.form) {
            props.form.pais_nacimiento_id = datos.pais_nacimiento_id ?? props.form.pais_nacimiento_id;
        }
    } catch {
        // Si la consulta falla el formulario sigue siendo usable a mano: el
        // autollenado es una comodidad, no un requisito para capturar.
        analisis.value = null;
    }
}

/**
 * Se buscan duplicados al salir del nombre o del correo, no al guardar: avisar
 * después de veinte campos llenos es avisar tarde.
 */
async function buscarDuplicados(): Promise<void> {
    if (!props.form.primer_apellido && !props.form.email) {
        return;
    }

    try {
        const datos = await consultar<{ coincidencias: Ficha[] }>('/identidad/duplicados', {
            nombre: props.form.nombre,
            primer_apellido: props.form.primer_apellido,
            segundo_apellido: props.form.segundo_apellido,
            curp: props.form.curp,
            email: props.form.email,
            fecha_nacimiento: props.form.fecha_nacimiento || null,
            persona_id: props.personaId ?? null,
        });

        duplicados.value = datos.coincidencias;
    } catch {
        duplicados.value = [];
    }
}

const notaCurp = computed(() => {
    if (analisis.value?.estado === 'invalida') return analisis.value.mensaje;
    if (analisis.value?.estado === 'extranjero') return 'Sin CURP. Indica el país de nacimiento.';
    if (analisis.value?.persona_existente) {
        return `Ya registrada: ${analisis.value.persona_existente.nombre_completo}. Se reutilizará esa persona.`;
    }
    if (analisis.value?.estado === 'valida') return 'CURP válida. Se llenaron los datos que contiene.';

    return null;
});
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-3">
        <CampoTexto
            v-model="form.nombre"
            etiqueta="Nombre(s)"
            requerido
            :error="form.errors.nombre"
            @blur="buscarDuplicados"
        />
        <CampoTexto
            v-model="form.primer_apellido"
            etiqueta="Primer apellido"
            requerido
            :error="form.errors.primer_apellido"
            @blur="buscarDuplicados"
        />
        <CampoTexto
            v-model="form.segundo_apellido"
            etiqueta="Segundo apellido"
            :error="form.errors.segundo_apellido"
        />

        <CampoTexto
            v-model="form.curp"
            etiqueta="CURP"
            mono
            :maximo="18"
            marcador="18 caracteres, o EXTRANJERO"
            :error="form.errors.curp"
            :ayuda="notaCurp ?? 'Al escribirla se llenan solos fecha, género y entidad.'"
        />

        <CampoTexto
            v-model="form.fecha_nacimiento"
            etiqueta="Fecha de nacimiento"
            tipo="date"
            :error="form.errors.fecha_nacimiento"
        />

        <CampoSelect
            v-model="form.genero_id"
            etiqueta="Género"
            vacio="Sin especificar"
            :opciones="generos.map((g) => ({ valor: g.id, texto: g.nombre }))"
            :error="form.errors.genero_id"
        />

        <CampoSelect
            v-model="form.entidad_nacimiento_id"
            etiqueta="Entidad de nacimiento"
            vacio="Sin especificar"
            :opciones="opcionesEntidad"
            :error="form.errors.entidad_nacimiento_id"
        />

        <CampoSelect
            v-if="pidePais"
            v-model="form.pais_nacimiento_id"
            etiqueta="País de nacimiento"
            vacio="Sin especificar"
            :opciones="paises.map((p) => ({ valor: p.id, texto: p.nombre }))"
            :error="form.errors.pais_nacimiento_id"
        />

        <CampoTexto
            v-model="form.email"
            etiqueta="Correo"
            tipo="email"
            :requerido="correoRequerido"
            :error="form.errors.email"
            ayuda="Es el usuario con el que entrará al sistema."
            @blur="buscarDuplicados"
        />

        <CampoTexto v-model="form.celular" etiqueta="Celular" tipo="tel" :error="form.errors.celular" />

        <!-- Posibles duplicados: se avisan, no se bloquean. Dos hermanos
             comparten apellidos y a veces el correo de la casa. -->
        <div
            v-if="duplicados.length"
            class="rounded-lg border p-3 text-sm sm:col-span-3"
            style="border-color: #f59e0b; background-color: color-mix(in srgb, #f59e0b 8%, transparent)"
        >
            <p class="font-medium">Puede que esta persona ya esté registrada</p>
            <ul class="mt-2 space-y-1">
                <li v-for="p in duplicados" :key="p.id" class="text-xs">
                    <strong>{{ p.nombre_completo }}</strong>
                    <span v-if="p.curp" class="font-mono"> · {{ p.curp }}</span>
                    <span v-if="p.email"> · {{ p.email }}</span>
                    <span :style="{ color: 'var(--color-suave)' }"> — coincide por {{ p.coincide_por }}</span>
                </li>
            </ul>
            <p class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                Si es la misma, escribe su CURP: el sistema reutiliza a la persona en vez de duplicarla.
            </p>
        </div>
    </div>
</template>
