<script setup lang="ts">
/**
 * Las reglas: qué exige cada programa.
 *
 * ── Se listan de la MÁS específica a la menos ──────────────────────────────
 * Que es como se leen: primero las excepciones y al final la general. Ordenarlas
 * por nombre escondería cuál gana, que es justo lo que hay que poder ver.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';

interface Regla {
    id: number;
    nombre: string;
    tipo: string | null;
    tipo_proceso_id: number;
    alcance: string;
    especificidad: number;
    activa: boolean;
    versiones: number;
    campus_id: number | null;
    nivel_estudios_id: number | null;
    programa_academico_id: number | null;
    plan_id: number | null;
    modalidad: string | null;
    generacion_desde: string | null;
    generacion_hasta: string | null;
    notas: string | null;
}

const props = defineProps<{
    reglas: Regla[];
    catalogos: {
        tiposProceso: { id: number; nombre: string }[];
        campus: { id: number; nombre: string }[];
        niveles: { id: number; nombre: string }[];
        programas: { id: number; nombre: string; nivel_estudios_id: number }[];
        planes: { id: number; nombre: string; programa_academico_id: number }[];
        modalidades: string[];
    };
    puedeEditar: boolean;
}>();

const errores = ref<Record<string, string>>({});
const procesando = ref(false);
const abierto = ref(false);
const editando = ref<Regla | null>(null);
const datos = ref<Record<string, unknown>>({});

/*
 * Los planes que se ofrecen se acotan al programa elegido: un plan de otro
 * programa deja la regla sin alcanzar a nadie —el servidor lo rechaza—, y
 * ofrecerlo aquí sería invitar al error.
 *
 * Y mientras no haya programa elegido, cada plan se rotula CON EL SUYO. Sin
 * eso la lista son veinte «Plan 2016» indistinguibles —los planes se llaman por
 * su año, no por su carrera— y elegir bien sería suerte. Con el programa ya
 * elegido el sufijo sobra: todos serían el mismo.
 */
const planesDelPrograma = computed(() => {
    const programa = datos.value.programa_academico_id as number | null;

    const nombreDelPrograma = (id: number) =>
        props.catalogos.programas.find((p) => p.id === id)?.nombre ?? '';

    return props.catalogos.planes
        .filter((p) => !programa || p.programa_academico_id === programa)
        .map((p) => ({
            ...p,
            etiqueta: programa ? p.nombre : `${p.nombre} · ${nombreDelPrograma(p.programa_academico_id)}`,
        }));
});

function abrir(r: Regla | null): void {
    errores.value = {};
    editando.value = r;
    datos.value = {
        nombre: r?.nombre ?? '',
        tipo_proceso_id: r?.tipo_proceso_id ?? null,
        campus_id: r?.campus_id ?? null,
        nivel_estudios_id: r?.nivel_estudios_id ?? null,
        programa_academico_id: r?.programa_academico_id ?? null,
        plan_id: r?.plan_id ?? null,
        modalidad: r?.modalidad ?? null,
        generacion_desde: r?.generacion_desde ?? '',
        generacion_hasta: r?.generacion_hasta ?? '',
        activa: r?.activa ?? true,
        notas: r?.notas ?? '',
    };
    abierto.value = true;
}

function guardar(): void {
    procesando.value = true;

    const destino = editando.value ? `/procesos/reglas/${editando.value.id}` : '/procesos/reglas';

    router[editando.value ? 'put' : 'post'](destino, { ...datos.value }, {
        preserveScroll: true,
        onError: (e) => (errores.value = e),
        onSuccess: () => (abierto.value = false),
        onFinish: () => (procesando.value = false),
    });
}
</script>

<template>
    <Head title="Reglas por programa" />

    <AppLayout titulo="Reglas por programa">
        <section class="tarjeta mb-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Qué exige cada programa</h2>
                    <p class="mt-1 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                        Una regla dice <strong>a quién</strong> aplica; sus <strong>versiones</strong> dicen
                        <strong>qué</strong> exige. Gana la más específica —plan, luego programa, nivel,
                        campus, generación y modalidad—, y lo que se deja sin elegir <strong>no acota</strong>.
                        Una regla sin ningún eje es la general de la escuela.
                    </p>
                    <p class="mt-2 max-w-3xl text-sm" :style="{ color: 'var(--color-suave)' }">
                        Cambiar un requisito <strong>crea una versión nueva</strong>: los expedientes ya
                        abiertos siguen con la que se les aplicó.
                    </p>
                </div>

                <BotonAccion v-if="puedeEditar" variante="nuevo" texto="Nueva regla" @click="abrir(null)" />
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="reglas.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Regla</th>
                            <th class="px-4 py-3 font-semibold">Proceso</th>
                            <th class="px-4 py-3 font-semibold">Alcance</th>
                            <th class="px-4 py-3 font-semibold text-center">Versiones</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in reglas" :key="r.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="px-6 py-4">
                                <span class="font-semibold text-contenido">{{ r.nombre }}</span>
                                <span v-if="!r.activa" class="ml-2 text-xs" :style="{ color: 'var(--color-suave)' }">· apagada</span>
                                <!-- Sin versiones, la regla no exige nada todavía:
                                     existe el alcance y no el requisito. -->
                                <span v-if="!r.versiones" class="mt-0.5 block text-xs" :style="{ color: '#b45309' }">
                                    Sin versiones: todavía no exige nada.
                                </span>
                            </td>
                            <td class="px-4 py-4">{{ r.tipo ?? '—' }}</td>
                            <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">{{ r.alcance }}</td>
                            <td class="px-4 py-4 text-center tabular-nums">{{ r.versiones }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-1">
                                    <BotonAccion variante="ver" texto="Abrir" :href="`/procesos/reglas/${r.id}`" />
                                    <BotonAccion v-if="puedeEditar" variante="editar" @click="abrir(r)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    Todavía no hay reglas. Sin una, ningún alumno es elegible: el sistema no adivina qué exige la escuela.
                </p>
            </div>
        </section>

        <Modal v-if="abierto" :etiqueta="editando ? 'Editar regla' : 'Nueva regla'" ancho="max-w-2xl" @cerrar="abierto = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">{{ editando ? 'Editar alcance' : 'Nueva regla' }}</h2>

                    <p class="rounded-lg px-4 py-3 text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 8%, transparent)', color: 'var(--color-suave)' }">
                        Esto es <strong>a quién</strong> aplica. Lo que exige se escribe después, en sus versiones.
                        Lo que dejes sin elegir <strong>no acota</strong>.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto v-model="datos.nombre" etiqueta="Nombre" requerido marcador="p. ej. Servicio social · Enfermería" :error="errores.nombre" />
                        <CampoSelect
                            v-model="datos.tipo_proceso_id"
                            etiqueta="Tipo de proceso"
                            requerido
                            :opciones="catalogos.tiposProceso.map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Elige el proceso…"
                            :error="errores.tipo_proceso_id"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect v-model="datos.campus_id" etiqueta="Campus" :opciones="catalogos.campus.map((c) => ({ valor: c.id, texto: c.nombre }))" vacio="Cualquiera" />
                        <CampoSelect v-model="datos.nivel_estudios_id" etiqueta="Nivel de estudios" :opciones="catalogos.niveles.map((n) => ({ valor: n.id, texto: n.nombre }))" vacio="Cualquiera" />
                        <CampoSelect
                            v-model="datos.programa_academico_id"
                            etiqueta="Programa académico"
                            :opciones="catalogos.programas.map((p) => ({ valor: p.id, texto: p.nombre }))"
                            vacio="Cualquiera"
                            @update:model-value="datos.plan_id = null"
                        />
                        <CampoSelect
                            v-model="datos.plan_id"
                            etiqueta="Plan de estudios"
                            :opciones="planesDelPrograma.map((p) => ({ valor: p.id, texto: p.etiqueta }))"
                            vacio="Cualquiera"
                            ayuda="Sólo los del programa elegido: uno de otro dejaría la regla sin alcanzar a nadie."
                            :error="errores.plan_id"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <CampoSelect
                            v-model="datos.modalidad"
                            etiqueta="Modalidad"
                            :opciones="catalogos.modalidades.map((m) => ({ valor: m, texto: m }))"
                            vacio="Cualquiera"
                        />
                        <CampoTexto v-model="datos.generacion_desde" etiqueta="De la generación" marcador="2020" :error="errores.generacion_desde" />
                        <CampoTexto v-model="datos.generacion_hasta" etiqueta="A la generación" marcador="2024" :error="errores.generacion_hasta" />
                    </div>

                    <CampoTextarea v-model="datos.notas" etiqueta="Notas" :filas="2" />

                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="datos.activa" type="checkbox" class="h-4 w-4" />
                        <span>Activa: se toma en cuenta al resolver</span>
                    </label>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="procesando" :texto="editando ? 'Guardar' : 'Crear regla'" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">Cancelar</button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
