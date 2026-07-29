<script setup lang="ts">
import CampoTexto from '@/Components/CampoTexto.vue';

/**
 * Carga horaria de una asignatura (teoría/práctica/acompañamiento/independientes).
 * Se separa de FormularioAsignatura para poder mostrarla en su propia tarjeta.
 * `form` es la instancia de useForm del padre (se muta directo).
 */
defineProps<{ form: Record<string, any> }>();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-4">
        <CampoTexto v-model="form.horas_teoria" etiqueta="Teoría" tipo="number" :error="form.errors.horas_teoria" />
        <CampoTexto v-model="form.horas_practica" etiqueta="Práctica" tipo="number" :error="form.errors.horas_practica" />
        <CampoTexto v-model="form.horas_acompanamiento" etiqueta="Acompañamiento" tipo="number" :error="form.errors.horas_acompanamiento" />
        <CampoTexto v-model="form.horas_independientes" etiqueta="Independientes" tipo="number" :error="form.errors.horas_independientes" />
    </div>
</template>
