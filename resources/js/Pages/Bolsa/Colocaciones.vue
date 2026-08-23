<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BuscadorRemoto from '@/Components/BuscadorRemoto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

interface Colocacion {
    id: number;
    persona: string | null;
    matricula: string | null;
    carrera: string | null;
    empresa: string | null;
    puesto: string;
    salario: string | null;
    fecha_ingreso: string | null;
    /** null = no se preguntó, que no es lo mismo que «no». */
    relacionado: boolean | null;
    notas: string | null;
    origen: string;
}

const props = defineProps<{
    colocaciones: { data: Colocacion[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: { empresa_id: number | null; origen: string | null };
    empresas: { id: number; razon_social: string }[];
}>();

const empresaId = ref(props.filtros.empresa_id);
const origen = ref(props.filtros.origen);

const nueva = ref(false);
const editando = ref<Colocacion | null>(null);
const susCarreras = ref<{ id: number; matricula: string; carrera: string | null }[]>([]);

const alta = useForm<{
    persona_id: number | null;
    matricula_oferta_id: number | null;
    empresa_id: number | null;
    puesto: string;
    salario: string;
    fecha_ingreso: string;
    relacionado_con_carrera: string;
    notas: string;
}>({
    persona_id: null,
    matricula_oferta_id: null,
    empresa_id: null,
    puesto: '',
    salario: '',
    fecha_ingreso: '',
    relacionado_con_carrera: '',
    notas: '',
});

const edicion = useForm<{
    empresa_id: number | null;
    puesto: string;
    salario: string;
    fecha_ingreso: string;
    relacionado_con_carrera: string;
    notas: string;
}>({
    empresa_id: null,
    puesto: '',
    salario: '',
    fecha_ingreso: '',
    relacionado_con_carrera: '',
    notas: '',
});

/*
 * Las tres respuestas del «¿es de su área?». El vacío NO es un descuido de
 * captura: es «no se preguntó», y el reporte lo cuenta aparte para no afirmar
 * algo que nadie dijo.
 */
const RELACION = [
    { valor: '1', texto: 'Sí, es de su área' },
    { valor: '0', texto: 'No, es de otra área' },
];

function filtrar(): void {
    router.get(
        '/bolsa/colocaciones',
        { empresa_id: empresaId.value, origen: origen.value },
        { preserveState: true, replace: true },
    );
}

async function traerCarreras(personaId: number | null): Promise<void> {
    susCarreras.value = [];
    alta.matricula_oferta_id = null;

    if (personaId === null) return;

    const r = await fetch(`/bolsa/postulantes/${personaId}/matriculas`, { headers: { Accept: 'application/json' } });
    if (!r.ok) return;

    susCarreras.value = await r.json();

    if (susCarreras.value.length === 1) {
        alta.matricula_oferta_id = susCarreras.value[0].id;
    }
}

function abrirAlta(): void {
    nueva.value = true;
    alta.reset();
    susCarreras.value = [];
    alta.defaults();
}

function guardar(): void {
    alta.transform((d) => ({
        ...d,
        salario: d.salario === '' ? null : d.salario,
        relacionado_con_carrera: d.relacionado_con_carrera === '' ? null : d.relacionado_con_carrera === '1',
    })).post('/bolsa/colocaciones', {
        preserveScroll: true,
        onSuccess: () => {
            nueva.value = false;
            alta.reset();
            susCarreras.value = [];
        },
    });
}

function abrirEdicion(c: Colocacion): void {
    editando.value = c;
    edicion.empresa_id = props.empresas.find((e) => e.razon_social === c.empresa)?.id ?? null;
    edicion.puesto = c.puesto;
    edicion.salario = c.salario === null ? '' : c.salario.replace(/[$,]/g, '');
    edicion.fecha_ingreso = c.fecha_ingreso ?? '';
    edicion.relacionado_con_carrera = c.relacionado === null ? '' : c.relacionado ? '1' : '0';
    edicion.notas = c.notas ?? '';
    edicion.defaults();
}

function actualizar(): void {
    if (editando.value === null) return;

    edicion.transform((d) => ({
        ...d,
        salario: d.salario === '' ? null : d.salario,
        relacionado_con_carrera: d.relacionado_con_carrera === '' ? null : d.relacionado_con_carrera === '1',
    })).put(`/bolsa/colocaciones/${editando.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = null;
        },
    });
}

function deshacer(c: Colocacion): void {
    if (!confirm(`Vas a deshacer la colocación de ${c.persona} en ${c.empresa}. Si vino de una postulación, esa postulación regresa a la etapa anterior. ¿Continuar?`)) {
        return;
    }

    router.delete(`/bolsa/colocaciones/${c.id}`, { preserveScroll: true });
}

function etiquetaRelacion(v: boolean | null): string {
    if (v === null) return 'Sin ese dato';

    return v ? 'De su área' : 'De otra área';
}
</script>

<template>
    <Head title="Colocaciones" />

    <AppLayout titulo="Colocaciones">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Quién quedó contratado, dónde y desde cuándo. Es lo que alimenta el
                <Link href="/bolsa/empleabilidad" class="underline" :style="{ color: 'var(--color-acento)' }">
                    indicador de empleabilidad</Link>.
            </p>

            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrirAlta()"
            >
                Registrar colocación
            </button>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <CampoSelect
                v-model="empresaId"
                etiqueta=""
                :opciones="empresas.map((e) => ({ valor: e.id, texto: e.razon_social }))"
                vacio="Todas las empresas"
                @update:model-value="filtrar"
            />
            <!--
                De dónde salió: es lo que mide si la bolsa sirve de algo, o si la
                escuela sólo está anotando lo que sus egresados consiguen solos.
            -->
            <CampoSelect
                v-model="origen"
                etiqueta=""
                :opciones="[
                    { valor: 'bolsa', texto: 'Salidas de la bolsa' },
                    { valor: 'seguimiento', texto: 'De seguimiento de egresados' },
                ]"
                vacio="De cualquier origen"
                @update:model-value="filtrar"
            />
        </div>

        <TarjetaSeccion titulo="Colocaciones registradas" sin-relleno>
            <ul v-if="colocaciones.data.length">
                <li
                    v-for="c in colocaciones.data"
                    :key="c.id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium">{{ c.persona }}</p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                {{ c.puesto }} en {{ c.empresa }}
                                <span v-if="c.fecha_ingreso"> · desde el {{ c.fecha_ingreso }}</span>
                                <span v-if="c.salario"> · {{ c.salario }}</span>
                            </p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                <template v-if="c.carrera">{{ c.carrera }}</template>
                                <template v-else>Sin carrera señalada</template>
                                <span> · {{ etiquetaRelacion(c.relacionado) }}</span>
                                <span> · {{ c.origen }}</span>
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--color-borde)' }"
                                @click="abrirEdicion(c)"
                            >
                                Editar
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: '#dc2626', color: '#dc2626' }"
                                @click="deshacer(c)"
                            >
                                Deshacer
                            </button>
                        </div>
                    </div>

                    <p v-if="c.notas" class="mt-2 whitespace-pre-line text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ c.notas }}
                    </p>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay colocaciones registradas.
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="colocaciones.links"
            :total="colocaciones.total"
            :desde="colocaciones.from"
            :hasta="colocaciones.to"
            class="mt-4"
        />

        <Modal v-if="nueva" etiqueta="Registrar colocación" :formulario="alta" @cerrar="nueva = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Registrar colocación</h2>
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Para lo que un egresado consiguió por su cuenta. Lo que sale de una vacante
                        nuestra se registra desde su postulación.
                    </p>

                    <BuscadorRemoto
                        v-model="alta.persona_id"
                        url="/buscar/alumnos"
                        etiqueta="¿Quién se colocó?"
                        marcador="Nombre o matrícula…"
                        :error="alta.errors.persona_id"
                        @elegido="traerCarreras(alta.persona_id)"
                    />

                    <CampoSelect
                        v-if="susCarreras.length > 1"
                        v-model="alta.matricula_oferta_id"
                        etiqueta="¿Con cuál de sus carreras cuenta?"
                        :opciones="susCarreras.map((m) => ({ valor: m.id, texto: `${m.carrera ?? m.matricula}` }))"
                        vacio="Sin señalar"
                        ayuda="Egresó de más de una. Sin elegir, la colocación no entra en el porcentaje de ningún programa."
                        :error="alta.errors.matricula_oferta_id"
                    />

                    <CampoSelect
                        v-model="alta.empresa_id"
                        etiqueta="Empresa"
                        :opciones="empresas.map((e) => ({ valor: e.id, texto: e.razon_social }))"
                        vacio="Selecciona…"
                        ayuda="Si no está, date de alta al empleador primero en Empresas."
                        :error="alta.errors.empresa_id"
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="alta.puesto" etiqueta="Puesto" requerido :error="alta.errors.puesto" />
                        <CampoTexto
                            v-model="alta.fecha_ingreso"
                            etiqueta="Fecha de ingreso"
                            tipo="date"
                            requerido
                            :error="alta.errors.fecha_ingreso"
                        />
                        <CampoTexto v-model="alta.salario" etiqueta="Salario mensual" tipo="number" :error="alta.errors.salario" />
                        <CampoSelect
                            v-model="alta.relacionado_con_carrera"
                            etiqueta="¿El empleo es de su área?"
                            :opciones="RELACION"
                            vacio="No se preguntó"
                            ayuda="Dejarlo en blanco no es un «no»: se cuenta aparte."
                            :error="alta.errors.relacionado_con_carrera"
                        />
                    </div>

                    <CampoTextarea v-model="alta.notas" etiqueta="Notas" :filas="3" :error="alta.errors.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="alta.processing" texto="Registrar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="editando" etiqueta="Editar colocación" :formulario="edicion" @cerrar="editando = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="actualizar">
                    <h2 class="text-base font-semibold">{{ editando.persona }}</h2>
                    <!--
                        Ni la persona ni la carrera se editan: cambiarlas mueve el
                        número de dos renglones del reporte a la vez. Para eso se
                        deshace y se vuelve a capturar.
                    -->
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ editando.carrera ?? 'Sin carrera señalada' }} · para cambiar a quién o a qué
                        programa cuenta, deshaz la colocación y captúrala otra vez.
                    </p>

                    <CampoSelect
                        v-model="edicion.empresa_id"
                        etiqueta="Empresa"
                        :opciones="empresas.map((e) => ({ valor: e.id, texto: e.razon_social }))"
                        vacio="Selecciona…"
                        :error="edicion.errors.empresa_id"
                    />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="edicion.puesto" etiqueta="Puesto" requerido :error="edicion.errors.puesto" />
                        <CampoTexto
                            v-model="edicion.fecha_ingreso"
                            etiqueta="Fecha de ingreso"
                            tipo="date"
                            requerido
                            :error="edicion.errors.fecha_ingreso"
                        />
                        <CampoTexto v-model="edicion.salario" etiqueta="Salario mensual" tipo="number" :error="edicion.errors.salario" />
                        <CampoSelect
                            v-model="edicion.relacionado_con_carrera"
                            etiqueta="¿El empleo es de su área?"
                            :opciones="RELACION"
                            vacio="No se preguntó"
                            :error="edicion.errors.relacionado_con_carrera"
                        />
                    </div>

                    <CampoTextarea v-model="edicion.notas" etiqueta="Notas" :filas="3" :error="edicion.errors.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="edicion.processing" texto="Guardar" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
