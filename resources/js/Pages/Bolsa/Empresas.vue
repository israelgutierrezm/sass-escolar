<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import Paginacion from '@/Components/Paginacion.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

/**
 * El padrón de empleadores.
 *
 * No hay botón de eliminar: una empresa con la que la escuela no quiere volver
 * a trabajar se pasa a «vetada». Borrarla se llevaría sus colocaciones
 * históricas, que son el insumo de los reportes de acreditación.
 */
interface Empresa {
    id: number;
    razon_social: string;
    rfc: string | null;
    sector: string | null;
    tamano: string | null;
    situacion: string | null;
    situacion_clave: string | null;
    sitio_web: string | null;
    contactos: number;
}

const props = defineProps<{
    empresas: { data: Empresa[]; links: any[]; total: number; from: number | null; to: number | null };
    filtros: { busqueda: string; sector_id: number | null; situacion_id: number | null };
    catalogos: {
        sectores: { id: number; nombre: string }[];
        tamanos: { id: number; nombre: string }[];
        situaciones: { id: number; nombre: string }[];
    };
}>();

const busqueda = ref(props.filtros.busqueda);
const alta = ref(false);

const form = useForm({
    razon_social: '',
    rfc: '',
    sector_id: null as number | null,
    tamano_id: null as number | null,
    situacion_id: props.catalogos.situaciones[0]?.id ?? null,
    sitio_web: '',
    notas: '',
});

function filtrar(): void {
    router.get('/bolsa/empresas', { busqueda: busqueda.value }, { preserveState: true, replace: true });
}

function crear(): void {
    form.post('/bolsa/empresas', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            alta.value = false;
        },
    });
}

/** Vetada en rojo: es la única situación que impide publicar. */
function colorDe(clave: string | null): string {
    if (clave === 'vetada') return '#dc2626';
    if (clave === 'activa') return '#16a34a';

    return '#f59e0b';
}
</script>

<template>
    <Head title="Empresas" />

    <AppLayout titulo="Empresas">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input
                v-model="busqueda"
                type="search"
                placeholder="Razón social o RFC…"
                class="min-w-0 flex-1 rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'transparent' }"
                @keyup.enter="filtrar"
            />
            <button
                type="button"
                class="rounded-lg border px-3 py-2 text-sm"
                :style="{ borderColor: 'var(--color-borde)' }"
                @click="filtrar"
            >
                Buscar
            </button>
            <button
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="alta = !alta"
            >
                {{ alta ? 'Cancelar' : 'Nueva empresa' }}
            </button>
        </div>

        <TarjetaSeccion v-if="alta" titulo="Nueva empresa" class="mb-4">
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="crear">
                <CampoTexto v-model="form.razon_social" etiqueta="Razón social" requerido :error="form.errors.razon_social" />
                <CampoTexto
                    v-model="form.rfc"
                    etiqueta="RFC"
                    mono
                    :error="form.errors.rfc"
                    ayuda="Opcional, pero si lo pones no puede repetirse."
                />
                <CampoSelect
                    v-model="form.sector_id"
                    etiqueta="Sector"
                    :opciones="catalogos.sectores.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    vacio="Sin especificar"
                    :error="form.errors.sector_id"
                />
                <CampoSelect
                    v-model="form.tamano_id"
                    etiqueta="Tamaño"
                    :opciones="catalogos.tamanos.map((t) => ({ valor: t.id, texto: t.nombre }))"
                    vacio="Sin especificar"
                    :error="form.errors.tamano_id"
                />
                <CampoSelect
                    v-model="form.situacion_id"
                    etiqueta="Situación"
                    :opciones="catalogos.situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                    :error="form.errors.situacion_id"
                />
                <CampoTexto v-model="form.sitio_web" etiqueta="Sitio web" :error="form.errors.sitio_web" />

                <div class="sm:col-span-2">
                    <BotonPrincipal
                        :procesando="form.processing"
                        :deshabilitado="!form.razon_social"
                        texto="Guardar empresa"
                        cargando="Guardando…"
                        icono="ninguno"
                    />
                </div>
            </form>
        </TarjetaSeccion>

        <TarjetaSeccion titulo="Empleadores registrados" sin-relleno>
            <ul v-if="empresas.data.length">
                <li
                    v-for="e in empresas.data"
                    :key="e.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="min-w-0">
                        <Link :href="`/bolsa/empresas/${e.id}`" class="font-medium" :style="{ color: 'var(--color-acento)' }">
                            {{ e.razon_social }}
                        </Link>
                        <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            <span v-if="e.rfc" class="font-mono">{{ e.rfc }}</span>
                            <span v-if="e.sector"> · {{ e.sector }}</span>
                            <span v-if="e.tamano"> · {{ e.tamano }}</span>
                            <span v-if="e.contactos"> · {{ e.contactos }} contacto(s)</span>
                        </p>
                    </div>

                    <span
                        class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                        :style="{
                            backgroundColor: `color-mix(in srgb, ${colorDe(e.situacion_clave)} 14%, transparent)`,
                            color: colorDe(e.situacion_clave),
                        }"
                    >
                        {{ e.situacion }}
                    </span>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay empleadores registrados.
            </p>
        </TarjetaSeccion>

        <Paginacion
            :enlaces="empresas.links"
            :total="empresas.total"
            :desde="empresas.from"
            :hasta="empresas.to"
            class="mt-4"
        />
    </AppLayout>
</template>
