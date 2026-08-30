<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { hoyLocal } from '@/utils/fechas';

interface Convenio {
    id: number;
    folio: string;
    institucion: string | null;
    ciudad: string | null;
    tipo: string | null;
    situacion: string | null;
    desde: string | null;
    hasta: string | null;
    vencido: boolean;
    vigente: boolean;
    programas_academicos: string[];
    convocatorias: number;
}

const props = defineProps<{
    convenios: { data: Convenio[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: { institucion_id: number | null };
    catalogos: Record<string, { id: number; nombre: string }[]>;
}>();

const institucionId = ref(props.filtros.institucion_id);
const nuevaInstitucion = ref(false);
const nuevoConvenio = ref(false);

const institucion = useForm({
    nombre: '',
    pais_id: null as number | null,
    ciudad: '',
    tipo_id: null as number | null,
    sitio_web: '',
});

const convenio = useForm({
    institucion_aliada_id: null as number | null,
    tipo_convenio_id: null as number | null,
    folio: '',
    vigente_desde: hoyLocal(),
    vigente_hasta: '',
    situacion_id: null as number | null,
    notas: '',
    programas_academicos: [] as number[],
});

function filtrar(): void {
    router.get('/movilidad/convenios', { institucion_id: institucionId.value }, { preserveState: true, replace: true });
}

function guardarInstitucion(): void {
    institucion.post('/movilidad/instituciones', {
        preserveScroll: true,
        onSuccess: () => {
            nuevaInstitucion.value = false;
            institucion.reset();
        },
    });
}

function guardarConvenio(): void {
    convenio.transform((d) => ({ ...d, vigente_hasta: d.vigente_hasta === '' ? null : d.vigente_hasta }))
        .post('/movilidad/convenios', {
            preserveScroll: true,
            onSuccess: () => {
                nuevoConvenio.value = false;
                convenio.reset();
            },
        });
}

/*
 * El color habla del estado REAL. Un convenio con la situación «vigente» y la
 * fecha pasada seguiría pintándose en verde, que es la trampa que ya mordió con
 * las vacantes de la bolsa.
 */
function colorDe(c: Convenio): string {
    if (c.vigente) return '#16a34a';

    return c.vencido ? '#dc2626' : '#d97706';
}

function etiquetaDe(c: Convenio): string {
    return c.vencido ? 'Venció' : (c.situacion ?? '—');
}
</script>

<template>
    <Head title="Convenios" />

    <AppLayout titulo="Convenios de movilidad">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                Los acuerdos con otras instituciones. Un convenio ampara muchas convocatorias.
            </p>

            <div class="flex gap-2">
                <button
                    type="button"
                    class="rounded-lg border px-3 py-2 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                    @click="nuevaInstitucion = true"
                >
                    Nueva institución
                </button>
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium"
                    :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                    @click="nuevoConvenio = true"
                >
                    Nuevo convenio
                </button>
            </div>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-3">
            <CampoSelect
                v-model="institucionId"
                etiqueta=""
                :opciones="(catalogos.instituciones ?? []).map((i) => ({ valor: i.id, texto: i.nombre }))"
                vacio="Todas las instituciones"
                @update:model-value="filtrar"
            />
        </div>

        <TarjetaSeccion titulo="Convenios" sin-relleno>
            <ul v-if="convenios.data.length">
                <li
                    v-for="c in convenios.data"
                    :key="c.id"
                    class="flex flex-wrap items-start justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <p class="font-medium">{{ c.institucion }}</p>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span class="font-mono">{{ c.folio }}</span>
                            <span v-if="c.tipo"> · {{ c.tipo }}</span>
                            <span v-if="c.ciudad"> · {{ c.ciudad }}</span>
                            <span> · desde el {{ c.desde }}</span>
                            <span v-if="c.hasta"> hasta el {{ c.hasta }}</span>
                        </p>
                        <!--
                            Sin programas académicos señaladas NO es captura incompleta: es
                            «para todas». Se dice con palabras.
                        -->
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <template v-if="c.programas_academicos.length">Cubre {{ c.programas_academicos.join(', ') }}</template>
                            <template v-else>Cubre todos los programas académicos</template>
                            <span> · {{ c.convocatorias }} convocatoria(s)</span>
                        </p>
                    </div>

                    <span
                        class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :style="{
                            backgroundColor: `color-mix(in srgb, ${colorDe(c)} 14%, transparent)`,
                            color: colorDe(c),
                        }"
                    >
                        {{ etiquetaDe(c) }}
                    </span>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay convenios.
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="convenios.links"
            :total="convenios.total"
            :desde="convenios.from"
            :hasta="convenios.to"
            class="mt-4"
        />

        <Modal v-if="nuevaInstitucion" etiqueta="Nueva institución" :formulario="institucion" @cerrar="nuevaInstitucion = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarInstitucion">
                    <h2 class="text-base font-semibold">Nueva institución aliada</h2>

                    <CampoTexto v-model="institucion.nombre" etiqueta="Nombre" requerido :error="institucion.errors.nombre" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="institucion.pais_id"
                            etiqueta="País"
                            :opciones="(catalogos.paises ?? []).map((p) => ({ valor: p.id, texto: p.nombre }))"
                            vacio="Sin señalar"
                            :error="institucion.errors.pais_id"
                        />
                        <CampoTexto v-model="institucion.ciudad" etiqueta="Ciudad" :error="institucion.errors.ciudad" />
                        <CampoSelect
                            v-model="institucion.tipo_id"
                            etiqueta="Tipo"
                            :opciones="(catalogos.tipos_institucion ?? []).map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Selecciona…"
                            :error="institucion.errors.tipo_id"
                        />
                        <CampoTexto v-model="institucion.sitio_web" etiqueta="Sitio web" :error="institucion.errors.sitio_web" />
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="institucion.processing" texto="Guardar" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="nuevoConvenio" etiqueta="Nuevo convenio" :formulario="convenio" @cerrar="nuevoConvenio = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardarConvenio">
                    <h2 class="text-base font-semibold">Nuevo convenio</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoSelect
                            v-model="convenio.institucion_aliada_id"
                            etiqueta="Institución"
                            :opciones="(catalogos.instituciones ?? []).map((i) => ({ valor: i.id, texto: i.nombre }))"
                            vacio="Selecciona…"
                            :error="convenio.errors.institucion_aliada_id"
                        />
                        <CampoSelect
                            v-model="convenio.tipo_convenio_id"
                            etiqueta="Tipo"
                            :opciones="(catalogos.tipos_convenio ?? []).map((t) => ({ valor: t.id, texto: t.nombre }))"
                            vacio="Selecciona…"
                            :error="convenio.errors.tipo_convenio_id"
                        />
                        <CampoTexto v-model="convenio.folio" etiqueta="Folio" requerido mono ayuda="El del papel firmado." :error="convenio.errors.folio" />
                        <CampoSelect
                            v-model="convenio.situacion_id"
                            etiqueta="Situación"
                            :opciones="(catalogos.situaciones ?? []).map((s) => ({ valor: s.id, texto: s.nombre }))"
                            vacio="Selecciona…"
                            :error="convenio.errors.situacion_id"
                        />
                        <CampoTexto v-model="convenio.vigente_desde" etiqueta="Vigente desde" tipo="date" requerido :error="convenio.errors.vigente_desde" />
                        <CampoTexto
                            v-model="convenio.vigente_hasta"
                            etiqueta="Vigente hasta"
                            tipo="date"
                            ayuda="En blanco = sin fecha de término."
                            :error="convenio.errors.vigente_hasta"
                        />
                    </div>

                    <!--
                        Vacío = TODAS. Es el caso más común de un convenio marco,
                        y exigir al menos una obligaría a palomear las veinte.
                    -->
                    <CampoCasillas
                        v-model="convenio.programas_academicos"
                        etiqueta="Programas académicos que cubre"
                        :opciones="(catalogos.programas_academicos ?? []).map((c) => ({ valor: c.id, texto: c.nombre }))"
                        ayuda="Sin marcar ninguna, el convenio cubre TODAS los programas académicos."
                        :error="convenio.errors.programas_academicos"
                    />

                    <CampoTextarea v-model="convenio.notas" etiqueta="Notas" :filas="2" :error="convenio.errors.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="convenio.processing" texto="Guardar convenio" icono="crear" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
