<script setup lang="ts">
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';

/**
 * Campos de una asignatura (identificación + carga horaria), en UN solo layout.
 * Lo comparten el alta en la malla del plan y la ficha de la materia, para que
 * no diverjan etiquetas ni disposición. `form` es la instancia de useForm del
 * padre (se muta directo: form.nombre, form.errors…).
 */
interface Opcion {
    id: number;
    nombre: string;
}

defineProps<{
    form: Record<string, any>;
    tiposAsignatura: Opcion[];
    clasificaciones: Opcion[];
    areas: Opcion[];
}>();

const opciones = (lista: Opcion[]) => lista.map((x) => ({ valor: x.id, texto: x.nombre }));
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-4">
        <div class="sm:col-span-2">
            <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
        </div>
        <CampoTexto v-model="form.clave" etiqueta="Clave" requerido mono :error="form.errors.clave" />
        <CampoTexto v-model="form.identificador" etiqueta="Identificador" requerido :error="form.errors.identificador" />
        <CampoTexto v-model="form.creditos" etiqueta="Créditos" tipo="number" requerido :error="form.errors.creditos" />
        <CampoSelect
            v-model="form.tipo_asignatura_id"
            etiqueta="Tipo de asignatura"
            requerido
            :opciones="opciones(tiposAsignatura)"
            vacio="Selecciona…"
            :error="form.errors.tipo_asignatura_id"
        />
        <CampoSelect
            v-model="form.clasificacion_id"
            etiqueta="Clasificación"
            :opciones="opciones(clasificaciones)"
            vacio="Sin especificar"
            :error="form.errors.clasificacion_id"
        />
        <CampoSelect
            v-model="form.area_id"
            etiqueta="Área"
            :opciones="opciones(areas)"
            vacio="Sin especificar"
            :error="form.errors.area_id"
        />

        <div class="sm:col-span-4">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                Carga horaria
            </p>
            <div class="grid gap-4 sm:grid-cols-4">
                <CampoTexto v-model="form.horas_teoria" etiqueta="Teoría" tipo="number" :error="form.errors.horas_teoria" />
                <CampoTexto v-model="form.horas_practica" etiqueta="Práctica" tipo="number" :error="form.errors.horas_practica" />
                <CampoTexto v-model="form.horas_acompanamiento" etiqueta="Acompañamiento" tipo="number" :error="form.errors.horas_acompanamiento" />
                <CampoTexto v-model="form.horas_independientes" etiqueta="Independientes" tipo="number" :error="form.errors.horas_independientes" />
            </div>
        </div>
    </div>
</template>
