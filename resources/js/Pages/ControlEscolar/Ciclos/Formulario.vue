<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import NavEscolar from '@/Components/NavEscolar.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoCasillas from '@/Components/CampoCasillas.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

const props = defineProps<{
    ciclo: Record<string, any> | null;
    campus: { id: number; nombre: string }[];
    situaciones: { id: number; nombre: string }[];
    niveles: { id: number; nombre: string }[];
    alcanceAcotado: boolean;
}>();

const esEdicion = computed(() => props.ciclo !== null);

const campusAjenos = computed<string[]>(() => props.ciclo?.campus_ajenos ?? []);

const form = useForm({
    campus_ids: (props.ciclo?.campus_ids ?? []) as number[],
    // La clave ya no se teclea: se arma de año + periodo.
    anio: props.ciclo?.anio ?? new Date().getFullYear(),
    numero_periodo: props.ciclo?.numero_periodo ?? 1,
    nivel_ids: (props.ciclo?.nivel_ids ?? []) as number[],
    nombre: props.ciclo?.nombre ?? '',
    fecha_inicio: props.ciclo?.fecha_inicio ?? '',
    fecha_fin: props.ciclo?.fecha_fin ?? '',
    situacion_id: props.ciclo?.situacion_id ?? props.situaciones[0]?.id ?? null,
    inscripcion_desde: props.ciclo?.inscripcion_desde ?? '',
    inscripcion_hasta: props.ciclo?.inscripcion_hasta ?? '',
    altas_bajas_hasta: props.ciclo?.altas_bajas_hasta ?? '',
    captura_calif_hasta: props.ciclo?.captura_calif_hasta ?? '',
});

const opciones = (lista: { id: number; nombre: string }[]) =>
    lista.map((item) => ({ valor: item.id, texto: item.nombre }));

// La clave se muestra como vista previa de lo que el sistema guardará; no se
// edita. Así se ve el resultado (2026-1) sin capturarlo.
const clavePrevia = computed(() =>
    form.anio && form.numero_periodo ? `${form.anio}-${form.numero_periodo}` : '—',
);

// El ciclo restringe sus grupos: se avisa según lo que se haya elegido, para
// que quien lo crea sepa qué está acotando antes de guardar.
const avisoRestriccion = computed(() => {
    const partes: string[] = [];
    const nombresNivel = props.niveles.filter((n) => form.nivel_ids.includes(n.id)).map((n) => n.nombre);

    if (nombresNivel.length) {
        partes.push(`solo grupos de nivel ${nombresNivel.join(', ')}`);
    }

    if (form.campus_ids.length) {
        partes.push('solo grupos de los campus marcados');
    }

    return partes.length ? `Los grupos de este ciclo aceptarán ${partes.join('; y ')}.` : null;
});

function enviar(): void {
    esEdicion.value ? form.put(`/escolar/ciclos/${props.ciclo!.id}`) : form.post('/escolar/ciclos');
}
</script>

<template>
    <Head :title="esEdicion ? 'Editar ciclo' : 'Nuevo ciclo'" />

    <AppLayout :titulo="esEdicion ? 'Editar ciclo' : 'Nuevo ciclo'">
        <NavEscolar />

        <form class="space-y-6" @submit.prevent="enviar">
            <TarjetaSeccion titulo="Identificación y periodo" descripcion="Clave, fechas y dónde aplica el ciclo." :icono="ICONOS.calendario">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <CampoTexto
                        v-model="form.anio"
                        etiqueta="Año"
                        tipo="number"
                        requerido
                        marcador="2026"
                        :error="form.errors.anio"
                    />
                    <CampoSelect
                        v-model="form.numero_periodo"
                        etiqueta="Número de periodo"
                        requerido
                        :opciones="[
                            { valor: 1, texto: '1' },
                            { valor: 2, texto: '2' },
                            { valor: 3, texto: '3' },
                            { valor: 4, texto: '4' },
                        ]"
                        :error="form.errors.numero_periodo"
                        :ayuda="`Clave del ciclo: ${clavePrevia} (se genera sola)`"
                    />
                    <CampoTexto v-model="form.nombre" etiqueta="Nombre interno" requerido :error="form.errors.nombre" />
                    <CampoSelect
                        v-model="form.situacion_id"
                        etiqueta="Situación"
                        requerido
                        :opciones="opciones(situaciones)"
                        :error="form.errors.situacion_id"
                    />
                    <CampoTexto
                        v-model="form.fecha_inicio"
                        etiqueta="Inicio del ciclo"
                        tipo="date"
                        requerido
                        :error="form.errors.fecha_inicio"
                    />
                    <CampoTexto
                        v-model="form.fecha_fin"
                        etiqueta="Fin del ciclo"
                        tipo="date"
                        requerido
                        :error="form.errors.fecha_fin"
                    />
                </div>

                <div class="mt-5">
                    <CampoCasillas
                        v-model="form.campus_ids"
                        etiqueta="Campus donde aplica *"
                        :opciones="opciones(campus)"
                        :error="form.errors.campus_ids"
                        vacio="No tienes campus asignados."
                        :ayuda="
                            alcanceAcotado
                                ? 'Obligatorio. Solo aparecen los campus de tu alcance; marca al menos uno.'
                                : 'Obligatorio. Marca al menos un campus donde aplicará el ciclo.'
                        "
                    />

                    <!-- Campus del ciclo que este administrador no gestiona: se
                         muestran para que sepa que el ciclo es más amplio de lo
                         que ve, y se conservan intactos al guardar. -->
                    <p v-if="campusAjenos.length" class="mt-2 text-xs text-suave">
                        Este ciclo también aplica en
                        <span class="font-medium">{{ campusAjenos.join(', ') }}</span>, fuera de tu
                        alcance. No se modificarán al guardar.
                    </p>

                    <!-- Niveles de estudio: debajo de campus, donde aplica. -->
                    <div class="mt-5">
                        <CampoCasillas
                            v-model="form.nivel_ids"
                            etiqueta="Niveles de estudio (opcional)"
                            :opciones="opciones(niveles)"
                            :error="form.errors.nivel_ids"
                            ayuda="Marca uno o varios. Si marcas alguno, los grupos del ciclo solo podrán ser de esos niveles; sin marcar ninguno, cualquier nivel."
                        />
                    </div>

                    <!-- Aviso de lo que el ciclo acota: nivel y/o campus limitan
                         qué grupos podrán crearse dentro. -->
                    <div
                        v-if="avisoRestriccion"
                        class="mt-4 flex items-start gap-2 rounded-lg p-3 text-sm"
                        style="background-color: color-mix(in srgb, #6366f1 8%, transparent)"
                    >
                        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        </svg>
                        <span>{{ avisoRestriccion }}</span>
                    </div>
                </div>
            </TarjetaSeccion>

            <TarjetaSeccion titulo="Ventanas" descripcion="Gobiernan qué se puede hacer y cuándo. Fuera de la ventana de inscripción, el sistema no deja inscribir." :icono="ICONOS.reloj">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <CampoTexto
                        v-model="form.inscripcion_desde"
                        etiqueta="Inscripción desde"
                        tipo="date"
                        :error="form.errors.inscripcion_desde"
                    />
                    <CampoTexto
                        v-model="form.inscripcion_hasta"
                        etiqueta="Inscripción hasta"
                        tipo="date"
                        :error="form.errors.inscripcion_hasta"
                    />
                    <CampoTexto
                        v-model="form.altas_bajas_hasta"
                        etiqueta="Altas y bajas hasta"
                        tipo="date"
                        :error="form.errors.altas_bajas_hasta"
                    />
                    <CampoTexto
                        v-model="form.captura_calif_hasta"
                        etiqueta="Captura de calificaciones hasta"
                        tipo="date"
                        :error="form.errors.captura_calif_hasta"
                    />
                </div>
            </TarjetaSeccion>

            <div class="flex items-center gap-3">
                <BotonPrincipal :procesando="form.processing" :texto="esEdicion ? 'Guardar cambios' : 'Crear ciclo'" />
                <a
                    href="/escolar/ciclos"
                    class="rounded-lg border border-borde px-5 py-2.5 text-sm text-contenido hover:bg-fondo"
                >
                    Cancelar
                </a>
            </div>
        </form>
    </AppLayout>
</template>
