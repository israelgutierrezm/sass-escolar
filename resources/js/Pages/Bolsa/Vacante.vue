<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';

/**
 * Alta y edición de una vacante — la MISMA pantalla.
 *
 * Dos pantallas casi iguales es como se llega a que el alta pida un campo que
 * la edición no ofrece, y entonces ese campo sólo se puede poner al crear.
 */
const props = defineProps<{
    vacante: Record<string, any>;
    catalogos: Record<string, any[]>;
}>();

const esNueva = computed(() => props.vacante.id === null);

const form = useForm({
    empresa_id: props.vacante.empresa_id,
    titulo: props.vacante.titulo ?? '',
    descripcion: props.vacante.descripcion ?? '',
    modalidad_id: props.vacante.modalidad_id,
    tipo_jornada_id: props.vacante.tipo_jornada_id,
    salario_min: props.vacante.salario_min ?? '',
    salario_max: props.vacante.salario_max ?? '',
    campus_id: props.vacante.campus_id,
    vacantes_disponibles: props.vacante.vacantes_disponibles ?? 1,
    ubicacion: props.vacante.ubicacion ?? '',
    fecha_publicacion: props.vacante.fecha_publicacion ?? '',
    fecha_cierre: props.vacante.fecha_cierre ?? '',
    situacion_id: props.vacante.situacion_id,
    carreras: [...(props.vacante.carreras ?? [])] as number[],
    habilidades: [...(props.vacante.habilidades ?? [])] as { id: number; indispensable: boolean }[],
});

function alternarCarrera(id: number): void {
    form.carreras = form.carreras.includes(id)
        ? form.carreras.filter((c) => c !== id)
        : [...form.carreras, id];
}

function habilidadElegida(id: number): { id: number; indispensable: boolean } | undefined {
    return form.habilidades.find((h) => h.id === id);
}

function alternarHabilidad(id: number): void {
    form.habilidades = habilidadElegida(id)
        ? form.habilidades.filter((h) => h.id !== id)
        : [...form.habilidades, { id, indispensable: false }];
}

function alternarIndispensable(id: number): void {
    form.habilidades = form.habilidades.map((h) =>
        h.id === id ? { ...h, indispensable: !h.indispensable } : h,
    );
}

function guardar(): void {
    esNueva.value
        ? form.post('/bolsa/vacantes', { preserveScroll: true })
        : form.put(`/bolsa/vacantes/${props.vacante.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="esNueva ? 'Nueva vacante' : vacante.titulo" />

    <AppLayout :titulo="esNueva ? 'Nueva vacante' : vacante.titulo">
        <form @submit.prevent="guardar">
            <TarjetaSeccion titulo="El puesto" class="mb-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoSelect
                        v-model="form.empresa_id"
                        etiqueta="Empresa"
                        :opciones="catalogos.empresas.map((e) => ({ valor: e.id, texto: e.razon_social }))"
                        vacio="Selecciona…"
                        :error="form.errors.empresa_id"
                        ayuda="Sólo aparecen las que no están vetadas."
                    />
                    <CampoTexto v-model="form.titulo" etiqueta="Título" requerido :error="form.errors.titulo" />

                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium">Descripción <span class="text-red-600">*</span></label>
                        <textarea
                            v-model="form.descripcion"
                            rows="5"
                            class="mt-1 w-full rounded-lg border px-3 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'transparent' }"
                        ></textarea>
                        <p v-if="form.errors.descripcion" class="mt-1 text-xs text-red-600">{{ form.errors.descripcion }}</p>
                    </div>

                    <CampoSelect
                        v-model="form.modalidad_id"
                        etiqueta="Modalidad"
                        :opciones="catalogos.modalidades.map((m) => ({ valor: m.id, texto: m.nombre }))"
                        vacio="Sin especificar"
                        :error="form.errors.modalidad_id"
                    />
                    <CampoSelect
                        v-model="form.tipo_jornada_id"
                        etiqueta="Jornada"
                        :opciones="catalogos.jornadas.map((j) => ({ valor: j.id, texto: j.nombre }))"
                        vacio="Sin especificar"
                        :error="form.errors.tipo_jornada_id"
                    />
                    <CampoTexto
                        v-model="form.salario_min"
                        etiqueta="Sueldo mínimo"
                        tipo="number"
                        :error="form.errors.salario_min"
                        ayuda="Opcional: casi ninguna vacante lo publica."
                    />
                    <CampoTexto
                        v-model="form.salario_max"
                        etiqueta="Sueldo máximo"
                        tipo="number"
                        :error="form.errors.salario_max"
                    />
                    <CampoTexto v-model="form.ubicacion" etiqueta="Ubicación" :error="form.errors.ubicacion" />
                    <CampoTexto
                        v-model="form.vacantes_disponibles"
                        etiqueta="Plazas"
                        tipo="number"
                        requerido
                        :error="form.errors.vacantes_disponibles"
                    />
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Publicación" class="mb-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <CampoTexto
                        v-model="form.fecha_publicacion"
                        etiqueta="Se publica"
                        tipo="date"
                        requerido
                        :error="form.errors.fecha_publicacion"
                    />
                    <CampoTexto
                        v-model="form.fecha_cierre"
                        etiqueta="Cierra"
                        tipo="date"
                        :error="form.errors.fecha_cierre"
                        ayuda="Déjala vacía si no tiene fecha límite."
                    />
                    <CampoSelect
                        v-model="form.situacion_id"
                        etiqueta="Situación"
                        :opciones="catalogos.situaciones.map((s) => ({ valor: s.id, texto: s.nombre }))"
                        :error="form.errors.situacion_id"
                    />
                    <CampoSelect
                        v-model="form.campus_id"
                        etiqueta="Campus que la difunde"
                        :opciones="catalogos.campus.map((c) => ({ valor: c.id, texto: c.nombre }))"
                        vacio="Toda la escuela"
                        :error="form.errors.campus_id"
                    />
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion
                titulo="Perfil"
                descripcion="Sin ninguna carrera marcada, la vacante queda abierta a todas."
                class="mb-4"
            >
                <p class="mb-2 text-sm font-medium">Carreras</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="c in catalogos.carreras"
                        :key="c.id"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :style="form.carreras.includes(c.id)
                            ? { borderColor: 'var(--color-acento)', color: 'var(--color-acento)', backgroundColor: 'color-mix(in srgb, var(--color-acento) 10%, transparent)' }
                            : { borderColor: 'var(--color-borde)' }"
                        @click="alternarCarrera(c.id)"
                    >
                        {{ c.nombre }}
                    </button>
                </div>
                <p v-if="!form.carreras.length" class="mt-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                    Ninguna marcada — la verán todos los alumnos y egresados.
                </p>

                <p class="mb-2 mt-5 text-sm font-medium">Habilidades</p>
                <div class="space-y-2">
                    <div v-for="h in catalogos.habilidades" :key="h.id" class="flex flex-wrap items-center gap-3 text-sm">
                        <label class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                :checked="!!habilidadElegida(h.id)"
                                @change="alternarHabilidad(h.id)"
                            />
                            {{ h.nombre }}
                        </label>
                        <!--
                            Distinguir lo indispensable de lo que suma: sin esto,
                            una vacante con ocho habilidades parece exigirlas
                            todas y nadie se postula.
                        -->
                        <label
                            v-if="habilidadElegida(h.id)"
                            class="flex items-center gap-1.5 text-xs"
                            :style="{ color: 'var(--color-suave)' }"
                        >
                            <input
                                type="checkbox"
                                :checked="habilidadElegida(h.id)?.indispensable"
                                @change="alternarIndispensable(h.id)"
                            />
                            indispensable
                        </label>
                    </div>
                </div>
            </TarjetaSeccion>

            <BotonPrincipal
                :procesando="form.processing"
                :deshabilitado="!form.empresa_id || !form.titulo || !form.descripcion"
                :texto="esNueva ? 'Publicar vacante' : 'Guardar cambios'"
                cargando="Guardando…"
                icono="ninguno"
            />
        </form>
    </AppLayout>
</template>
