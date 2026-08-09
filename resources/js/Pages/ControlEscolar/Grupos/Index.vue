<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import BarraListado from '@/Components/BarraListado.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import Paginacion from '@/Components/Paginacion.vue';
import PildoraEstado from '@/Components/PildoraEstado.vue';
import TarjetaRegistro from '@/Components/TarjetaRegistro.vue';

const ICONO_GRUPO =
    'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z';

interface FilaGrupo {
    id: number;
    clave: string;
    nombre: string | null;
    ciclo: string | null;
    campus: string | null;
    plan: string | null;
    turno: string | null;
    situacion: string | null;
    ciclo_id: number;
    cupo: number | null;
    materias_count: number;
    alumnos_count: number;
}

const props = defineProps<{
    grupos: {
        data: FilaGrupo[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filtros: Record<string, any>;
    ciclos: { id: number; nombre: string }[];
    campus: { id: number; nombre: string }[];
    planes: { id: number; nombre: string }[];
    turnos: { id: number; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
    puedeEditar: boolean;
    puedeInscribir: boolean;
}>();

const vista = ref<'lista' | 'cuadricula'>('lista');

const definicionFiltros = [
    { clave: 'ciclo_id', etiqueta: 'Ciclo', opciones: props.ciclos.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'campus_id', etiqueta: 'Campus', opciones: props.campus.map((c) => ({ valor: c.id, texto: c.nombre })) },
    { clave: 'plan_id', etiqueta: 'Plan de estudios', opciones: props.planes.map((p) => ({ valor: p.id, texto: p.nombre })) },
    { clave: 'turno_id', etiqueta: 'Turno', opciones: props.turnos.map((t) => ({ valor: t.id, texto: t.nombre })) },
    { clave: 'situacion_id', etiqueta: 'Situación', opciones: props.situaciones.map((s) => ({ valor: s.id, texto: s.nombre })) },
];

function eliminar(id: number, clave: string): void {
    if (!confirm(`¿Eliminar el grupo "${clave}"?`)) {
        return;
    }

    router.delete(`/escolar/grupos/${id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Grupos" />

    <AppLayout titulo="Control escolar">
        <NavEscolar />

        <BarraListado
            v-model:vista="vista"
            url="/escolar/grupos"
            vista-clave="grupos"
            :valores="filtros"
            :filtros="definicionFiltros"
            placeholder="Clave o nombre del grupo…"
            :puede-crear="puedeEditar"
            nuevo-texto="Nuevo grupo"
            nuevo-href="/escolar/grupos/create"
            titulo="Grupos"
            descripcion="Grupos abiertos por ciclo y campus"
            :icono="ICONO_GRUPO"
        >
            <template #conteo>
                <span class="rounded-full px-3 py-1 text-xs font-medium" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-acento) 12%, transparent)', color: 'var(--color-acento)' }">
                    {{ grupos.total }} en total
                </span>
            </template>
        </BarraListado>

        <!-- Cuadrícula -->
        <template v-if="vista === 'cuadricula'">
            <section v-if="grupos.data.length" class="cuadricula-listado">
                <TarjetaRegistro
                    v-for="grupo in grupos.data"
                    :key="grupo.id"
                    :titulo="grupo.clave"
                    :subtitulo="grupo.nombre"
                    :estado="grupo.situacion"
                    :url="`/escolar/grupos/${grupo.id}`"
                    :datos="[
                        { etiqueta: 'Ciclo', valor: grupo.ciclo },
                        { etiqueta: 'Campus', valor: grupo.campus },
                        { etiqueta: 'Plan', valor: grupo.plan ?? 'Sin plan fijo' },
                        { etiqueta: 'Turno', valor: grupo.turno },
                        { etiqueta: 'Ocupación', valor: `${grupo.alumnos_count}/${grupo.cupo ?? '—'}` },
                        { etiqueta: 'Materias', valor: grupo.materias_count },
                    ]"
                >
                    <template #acciones>
                        <BotonAccion
                            v-if="puedeInscribir && grupo.materias_count"
                            variante="agregar"
                            texto="Inscribir"
                            :href="`/escolar/inscripciones/masiva?ciclo_id=${grupo.ciclo_id}&grupo_id=${grupo.id}`"
                        />
                        <!-- «Abrir» no decía a qué se entraba; la pantalla es la
                             de las materias del grupo, así que lo dice. -->
                        <BotonAccion variante="ver" texto="Materias" :href="`/escolar/grupos/${grupo.id}`" />
                        <template v-if="puedeEditar">
                            <BotonAccion variante="editar" :href="`/escolar/grupos/${grupo.id}/edit`" />
                            <BotonAccion variante="eliminar" @click="eliminar(grupo.id, grupo.clave)" />
                        </template>
                    </template>
                </TarjetaRegistro>
            </section>

            <section v-if="grupos.links.length > 3" class="tarjeta">
                <Paginacion :enlaces="grupos.links" :total="grupos.total" :desde="grupos.from" :hasta="grupos.to" />
            </section>
        </template>

        <!-- Lista -->
        <section v-else class="tarjeta overflow-hidden">
            <div class="overflow-x-auto">
                <table v-if="grupos.data.length" class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider" :style="{ color: 'var(--color-suave)', backgroundColor: 'color-mix(in srgb, var(--color-suave) 6%, transparent)' }">
                            <th class="px-6 py-3 font-semibold">Grupo</th>
                            <th class="px-4 py-3 font-semibold">Ciclo</th>
                            <th class="px-4 py-3 font-semibold">Campus</th>
                            <th class="px-4 py-3 font-semibold">Plan</th>
                            <th class="px-4 py-3 font-semibold">Turno</th>
                            <th class="px-4 py-3 font-semibold text-center">Ocupación</th>
                            <th class="px-4 py-3 font-semibold text-center">Materias</th>
                            <th class="px-4 py-3 font-semibold">Situación</th>
                            <th class="px-6 py-3 font-semibold text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="grupo in grupos.data"
                            :key="grupo.id"
                            class="fila-nueva border-t transition-colors"
                            :style="{ borderColor: 'var(--color-borde)' }"
                        >
                            <!-- Grupo: clave + nombre -->
                            <td class="px-6 py-4">
                                <a :href="`/escolar/grupos/${grupo.id}`" class="block font-mono text-xs font-semibold" :style="{ color: 'var(--color-acento)' }">{{ grupo.clave }}</a>
                                <span v-if="grupo.nombre" class="mt-0.5 block text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ grupo.nombre }}</span>
                            </td>
                            <td class="px-4 py-4 font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ grupo.ciclo }}</td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ grupo.campus }}</td>
                            <td class="px-4 py-4" :style="{ color: 'var(--color-suave)' }">{{ grupo.plan ?? 'Sin plan fijo' }}</td>
                            <td class="px-4 py-4">
                                <span v-if="grupo.turno" class="inline-block rounded-full px-2.5 py-0.5 text-[11px]" :style="{ backgroundColor: 'color-mix(in srgb, var(--color-suave) 10%, transparent)', color: 'var(--color-suave)' }">{{ grupo.turno }}</span>
                                <span v-else :style="{ color: 'var(--color-suave)' }">—</span>
                            </td>
                            <!-- Inscritos sobre cupo: el cupo solo dice algo en
                                 relación con lo que ya se ocupó. -->
                            <td class="px-4 py-4 text-center tabular-nums">
                                <span :style="{ color: grupo.cupo && grupo.alumnos_count >= grupo.cupo ? '#b45309' : 'var(--color-contenido)' }">{{ grupo.alumnos_count }}</span>
                                <span :style="{ color: 'var(--color-suave)' }">/{{ grupo.cupo ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-4 text-center tabular-nums" :style="{ color: 'var(--color-suave)' }">{{ grupo.materias_count }}</td>
                            <td class="px-4 py-4">
                                <PildoraEstado :texto="grupo.situacion" />
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-1">
                                    <!-- La inscripción masiva es el destino más
                                         frecuente desde aquí: llega con el ciclo
                                         y el grupo ya elegidos. -->
                                    <BotonAccion
                                        v-if="puedeInscribir && grupo.materias_count"
                                        variante="agregar"
                                        texto="Inscribir"
                                        :href="`/escolar/inscripciones/masiva?ciclo_id=${grupo.ciclo_id}&grupo_id=${grupo.id}`"
                                    />
                                    <!-- «Abrir» no decía a qué se entraba; la
                                         pantalla es la de las materias. -->
                                    <BotonAccion variante="ver" texto="Materias" :href="`/escolar/grupos/${grupo.id}`" />
                                    <template v-if="puedeEditar">
                                        <BotonAccion variante="editar" :href="`/escolar/grupos/${grupo.id}/edit`" />
                                        <BotonAccion variante="eliminar" @click="eliminar(grupo.id, grupo.clave)" />
                                    </template>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p v-else class="px-6 py-12 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                    {{
                        filtros.busqueda
                            ? `Ningún grupo coincide con "${filtros.busqueda}".`
                            : 'Aún no hay grupos. Necesitas al menos un ciclo y un campus.'
                    }}
                </p>
            </div>

            <Paginacion :enlaces="grupos.links" :total="grupos.total" :desde="grupos.from" :hasta="grupos.to" />
        </section>
    </AppLayout>
</template>

<style scoped>
.fila-nueva:hover {
    background-color: color-mix(in srgb, var(--color-acento) 5%, transparent);
}
</style>
