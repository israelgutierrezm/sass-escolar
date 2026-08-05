<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonExpediente from '@/Components/BotonExpediente.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaPersona from '@/Components/TarjetaPersona.vue';

interface FilaAspirante {
    id: number;
    nombre_completo: string | null;
    curp: string | null;
    email: string | null;
    celular: string | null;
    foto: string | null;
    situacion: string | null;
    etapa: string | null;
    campus: string | null;
    oferta: string | null;
    origen: string | null;
    paso: number;
    validado_admin: boolean;
}

const ICONO_ASPIRANTE =
    'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z';

function iniciales(nombre: string | null): string {
    if (!nombre) return '—';
    const partes = nombre.trim().split(/\s+/);
    return ((partes[0]?.[0] ?? '') + (partes[1]?.[0] ?? '')).toUpperCase() || '—';
}

const props = defineProps<{
    aspirantes: {
        data: FilaAspirante[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    situaciones: { id: number; nombre: string }[];
    etapas: { id: number; nombre: string }[];
    origenes: { id: number; nombre: string }[];
    campusDisponibles: { id: number; nombre: string }[];
    ofertas: { id: number; nombre: string }[];
    puedeCrear: boolean;
    puedeEditar: boolean;
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = [
    { clave: 'situacion_id', etiqueta: 'Situación', opciones: props.situaciones.map((s) => ({ valor: s.id, texto: s.nombre })) },
    { clave: 'etapa_crm_id', etiqueta: 'Etapa del embudo', opciones: props.etapas.map((e) => ({ valor: e.id, texto: e.nombre })) },
    { clave: 'origen_id', etiqueta: 'Cómo llegó', opciones: props.origenes.map((o) => ({ valor: o.id, texto: o.nombre })) },
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campusDisponibles.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'oferta_id', etiqueta: 'Programa de interés', opciones: props.ofertas.map((o) => ({ valor: o.id, texto: o.nombre })) },
];

/**
 * Saca al prospecto del embudo.
 *
 * Es la papelera del CRM: duplicados, un registro de prueba, el que se apuntó
 * dos veces desde el formulario público. El servidor se niega si ya se convirtió
 * en alumno —ahí cuelga su matrícula— y el borrado es lógico, así que si vuelve
 * el año que entra su CURP lo reencuentra.
 */
function eliminar(aspirante: { id: number; nombre_completo: string | null }): void {
    if (!confirm(`¿Sacar a ${aspirante.nombre_completo ?? 'este aspirante'} del embudo de admisión?`)) {
        return;
    }

    router.delete(`/aspirantes/${aspirante.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Aspirantes" />

    <AppLayout titulo="Aspirantes">
        <BarraListado
            v-model:vista="vista"
            url="/aspirantes"
            vista-clave="aspirantes"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Nombre o CURP…"
            :puede-crear="puedeCrear"
            nuevo-texto="Nuevo aspirante"
            nuevo-href="/aspirantes/nuevo"
            titulo="Aspirantes"
            descripcion="Prospectos en el embudo de admisión"
            :icono="ICONO_ASPIRANTE"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ aspirantes.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="aspirantes.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <TarjetaPersona
                    v-for="aspirante in aspirantes.data"
                    :key="aspirante.id"
                    :nombre="aspirante.nombre_completo"
                    :identificador="aspirante.curp"
                    :foto="aspirante.foto"
                    :lineas="[aspirante.oferta, aspirante.campus, aspirante.celular ?? aspirante.email, aspirante.etapa]"
                    :estado="aspirante.situacion"
                    :aviso="aspirante.etapa ? null : 'fuera del embudo'"
                    :url="`/aspirantes/${aspirante.id}`"
                />
            </section>

            <section v-if="aspirantes.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="aspirantes.links" :total="aspirantes.total" :desde="aspirantes.from" :hasta="aspirantes.to" />
            </section>
        </template>

        <!-- Lista -->
        <template v-else>
            <section class="tarjeta overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="aspirantes.data.length" class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                                <th class="px-6 py-3 font-semibold">Aspirante</th>
                                <th class="px-4 py-3 font-semibold">CURP</th>
                                <th class="px-4 py-3 font-semibold">Interés</th>
                                <th class="px-4 py-3 font-semibold">Etapa</th>
                                <th class="px-4 py-3 font-semibold">Situación</th>
                                <th class="px-4 py-3 font-semibold">Cómo llegó</th>
                                <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="aspirante in aspirantes.data"
                                :key="aspirante.id"
                                class="fila-nueva group border-t transition-colors"
                                :style="{ borderColor: 'var(--color-borde)' }"
                            >
                                <!-- Aspirante: avatar + nombre + contacto -->
                                <td class="px-6 py-4">
                                    <a :href="`/aspirantes/${aspirante.id}`" class="flex items-center gap-3">
                                        <img v-if="aspirante.foto" :src="aspirante.foto" alt="" class="h-10 w-10 rounded-full object-cover ring-1 ring-black/5" loading="lazy" />
                                        <span
                                            v-else
                                            class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-xs font-semibold"
                                            :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 15%, transparent)', color: 'var(--color-acento)' }"
                                        >{{ iniciales(aspirante.nombre_completo) }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-semibold text-contenido">{{ aspirante.nombre_completo ?? '—' }}</span>
                                            <span v-if="aspirante.celular || aspirante.email" class="block truncate text-xs" :style="{ color: 'var(--color-suave)' }">{{ aspirante.celular ?? aspirante.email }}</span>
                                        </span>
                                    </a>
                                </td>

                                <!-- CURP -->
                                <td class="px-4 py-4">
                                    <span v-if="aspirante.curp" class="inline-block rounded-md px-2 py-0.5 font-mono text-xs" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 12%, transparent)' }">{{ aspirante.curp }}</span>
                                    <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                                </td>

                                <!-- Interés -->
                                <td class="px-4 py-4">
                                    <span class="text-xs">{{ aspirante.oferta ?? '—' }}</span>
                                    <span v-if="aspirante.campus" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ aspirante.campus }}</span>
                                </td>

                                <!-- Etapa -->
                                <td class="px-4 py-4">
                                    <span
                                        v-if="aspirante.etapa"
                                        class="inline-block rounded-full px-2.5 py-0.5 text-[11px]"
                                        :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)', color: 'var(--color-acento)' }"
                                    >{{ aspirante.etapa }}</span>
                                    <span v-else class="text-[11px] font-medium" :style="{ color: '#b45309' }">Fuera del embudo</span>
                                </td>

                                <!-- Situación -->
                                <td class="px-4 py-4">
                                    <PildoraEstado :texto="aspirante.situacion" />
                                </td>

                                <!-- Cómo llegó -->
                                <td class="px-4 py-4 text-xs" :style="{ color: 'var(--color-suave)' }">{{ aspirante.origen ?? '—' }}</td>

                                <!-- Acciones -->
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-1">
                                        <BotonAccion v-if="puedeEditar" variante="editar" :href="`/aspirantes/${aspirante.id}/editar`" />
                                        <BotonAccion v-if="puedeEditar" variante="eliminar" texto="Eliminar del embudo" @click="eliminar(aspirante)" />
                                        <!-- Igual que en alumnos y docentes: el
                                             expediente es a donde se entra de
                                             verdad —identidad, documentos,
                                             conversión y «ver como»—, y estaba
                                             detrás de un icono de lupa que no lo
                                             anunciaba. -->
                                        <BotonExpediente :href="`/aspirantes/${aspirante.id}`" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ filtros.busqueda ? `Nadie coincide con "${filtros.busqueda}".` : 'Todavía no hay aspirantes registrados.' }}
                    </p>
                </div>

                <Paginacion :enlaces="aspirantes.links" :total="aspirantes.total" :desde="aspirantes.from" :hasta="aspirantes.to" />
            </section>
        </template>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
