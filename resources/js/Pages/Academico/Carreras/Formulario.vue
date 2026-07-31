<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import NavAcademico from '@/Components/NavAcademico.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

const props = defineProps<{
    carrera: Record<string, any> | null;
    niveles: { id: number; nombre: string }[];
}>();

const esEdicion = computed(() => props.carrera !== null);

const form = useForm({
    identificador: props.carrera?.identificador ?? '',
    clave: props.carrera?.clave ?? '',
    nombre: props.carrera?.nombre ?? '',
    nivel_estudios_id: props.carrera?.nivel_estudios_id ?? null,
    imagen_url: props.carrera?.imagen_url ?? '',
});

const opcionesNivel = computed(() => props.niveles.map((n) => ({ valor: n.id, texto: n.nombre })));

function enviar(): void {
    esEdicion.value ? form.put(`/academico/carreras/${props.carrera!.id}`) : form.post('/academico/carreras');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar carrera' : 'Nueva carrera'" />

    <AppLayout :titulo="esEdicion ? 'Editar carrera' : 'Nueva carrera'">
        <NavAcademico />

        <form @submit.prevent="enviar">
            <TarjetaSeccion titulo="Datos de la carrera" descripcion="Identificación y nivel de estudios." :icono="ICONOS.birrete">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CampoTexto
                        v-model="form.identificador"
                        etiqueta="Identificador"
                        requerido
                        :error="form.errors.identificador"
                        ayuda="ID estable, se conserva entre migraciones."
                    />
                    <CampoTexto
                        v-model="form.clave"
                        etiqueta="Clave (SEP)"
                        requerido
                        mono
                        :error="form.errors.clave"
                        ayuda="Clave oficial de la carrera ante la SEP (cveCarrera del título). El «Identificador» es interno."
                    />
                    <CampoTexto v-model="form.nombre" etiqueta="Nombre" requerido :error="form.errors.nombre" />
                    <CampoSelect
                        v-model="form.nivel_estudios_id"
                        etiqueta="Nivel de estudios"
                        requerido
                        :opciones="opcionesNivel"
                        vacio="Selecciona…"
                        :error="form.errors.nivel_estudios_id"
                    />
                </div>
                <template #pie>
                    <div class="flex items-center gap-3">
                        <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear carrera'" />
                        <a
                            href="/academico/carreras"
                            class="rounded-lg border border-borde px-5 py-2.5 text-sm text-contenido hover:bg-fondo"
                        >
                            Cancelar
                        </a>
                    </div>
                </template>
            </TarjetaSeccion>
        </form>
    </AppLayout>
</template>
