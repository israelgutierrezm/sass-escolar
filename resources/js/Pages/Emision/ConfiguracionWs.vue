<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Config {
    etapa_activa: 'pruebas' | 'produccion';
    usuario_pruebas: string | null;
    usuario_produccion: string | null;
    tiene_password_pruebas: boolean;
    tiene_password_produccion: boolean;
    hint_password_pruebas: string | null;
    hint_password_produccion: string | null;
    credenciales_completas_activa: boolean;
    conexion_estado: 'ok' | 'error' | null;
    conexion_mensaje: string | null;
    conexion_probada_en: string | null;
}

const props = defineProps<{
    config: Config;
    modo: 'real' | 'fake' | 'off';
}>();

const form = useForm({
    etapa_activa: props.config.etapa_activa,
    usuario_pruebas: props.config.usuario_pruebas ?? '',
    usuario_produccion: props.config.usuario_produccion ?? '',
    password_pruebas: '',
    password_produccion: '',
});

function guardar(): void {
    form.put('/titulacion/configuracion/web-service', {
        preserveScroll: true,
        onSuccess: () => {
            form.password_pruebas = '';
            form.password_produccion = '';
        },
    });
}

function probar(): void {
    router.post('/titulacion/configuracion/web-service/probar', {}, { preserveScroll: true });
}

const etapaActivaEsProd = computed(() => form.etapa_activa === 'produccion');

// Insignia del modo global (viene de config, no editable aquí).
const modoInfo = computed(() => ({
    real: { texto: 'Envío real', color: '#16a34a' },
    fake: { texto: 'Simulado (fake)', color: '#d97706' },
    off: { texto: 'Deshabilitado (off)', color: '#dc2626' },
}[props.modo]));
</script>

<template>
    <Head title="Web service · Titulación" />

    <AppLayout titulo="Titulación · Web service">
        <div class="mx-auto max-w-3xl space-y-6">
            <!-- Encabezado + modo global -->
            <section class="tarjeta p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-contenido">Credenciales del web service de la SEP</h2>
                        <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                            La SEP entrega dos juegos de credenciales —pruebas y producción— con endpoints
                            distintos. Captura ambos; el interruptor de abajo decide con cuál se opera hoy.
                            Las contraseñas se guardan cifradas y nunca se muestran completas.
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-3 py-1 text-xs font-medium"
                        :style="{ color: modoInfo.color, backgroundColor: `color-mix(in srgb, ${modoInfo.color} 15%, transparent)` }"
                    >
                        Modo: {{ modoInfo.texto }}
                    </span>
                </div>
                <p v-if="modo === 'off'" class="mt-3 rounded-lg border p-3 text-xs" :style="{ borderColor: '#dc2626', backgroundColor: 'color-mix(in srgb, #dc2626 8%, transparent)' }">
                    El envío al web service está deshabilitado por configuración del sistema (TITULOS_SEP_MODO=off).
                    Puedes capturar credenciales, pero no se enviarán títulos hasta habilitarlo.
                </p>
            </section>

            <!-- Interruptor de etapa activa -->
            <section class="tarjeta p-6">
                <h3 class="text-sm font-semibold text-contenido">Etapa activa</h3>
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Con esta etapa se sella cada lote nuevo de titulación. Antes de enviar un lote se valida
                    que su etapa coincida con la activa: así un lote de producción nunca se manda por error al
                    endpoint de pruebas ni al revés.
                </p>

                <div class="mt-4 flex gap-3">
                    <button
                        v-for="op in [{ v: 'pruebas', t: 'Pruebas' }, { v: 'produccion', t: 'Producción' }]"
                        :key="op.v"
                        type="button"
                        class="flex-1 rounded-xl border-2 px-4 py-3 text-sm font-medium transition"
                        :style="form.etapa_activa === op.v
                            ? { borderColor: op.v === 'produccion' ? '#16a34a' : 'var(--color-acento)', backgroundColor: `color-mix(in srgb, ${op.v === 'produccion' ? '#16a34a' : 'var(--color-acento)'} 10%, transparent)`, color: 'var(--color-contenido)' }
                            : { borderColor: 'var(--color-borde)', color: 'var(--color-suave)' }"
                        @click="form.etapa_activa = op.v as 'pruebas' | 'produccion'"
                    >
                        {{ op.t }}
                    </button>
                </div>
                <p v-if="etapaActivaEsProd" class="mt-3 text-xs font-medium" :style="{ color: '#16a34a' }">
                    ⚠ En producción los títulos enviados son oficiales ante la SEP.
                </p>
            </section>

            <!-- Credenciales de PRUEBAS -->
            <section class="tarjeta p-6">
                <h3 class="text-sm font-semibold text-contenido">Credenciales de pruebas</h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <CampoTexto v-model="form.usuario_pruebas" etiqueta="Usuario" marcador="usuario de pruebas" :error="form.errors.usuario_pruebas" />
                    <CampoTexto
                        v-model="form.password_pruebas"
                        etiqueta="Contraseña"
                        tipo="password"
                        :marcador="config.tiene_password_pruebas ? 'guardada — deja vacío para conservarla' : 'contraseña de pruebas'"
                        :ayuda="config.tiene_password_pruebas ? 'Ya hay una contraseña guardada.' : undefined"
                        :error="form.errors.password_pruebas"
                    />
                </div>
            </section>

            <!-- Credenciales de PRODUCCIÓN -->
            <section class="tarjeta p-6">
                <h3 class="text-sm font-semibold text-contenido">Credenciales de producción</h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <CampoTexto v-model="form.usuario_produccion" etiqueta="Usuario" marcador="usuario de producción" :error="form.errors.usuario_produccion" />
                    <CampoTexto
                        v-model="form.password_produccion"
                        etiqueta="Contraseña"
                        tipo="password"
                        :marcador="config.tiene_password_produccion ? 'guardada — deja vacío para conservarla' : 'contraseña de producción'"
                        :ayuda="config.tiene_password_produccion ? 'Ya hay una contraseña guardada.' : undefined"
                        :error="form.errors.password_produccion"
                    />
                </div>
            </section>

            <!-- Estado de conexión + acciones -->
            <section class="tarjeta p-6">
                <div v-if="config.conexion_estado" class="mb-4 rounded-lg border p-3 text-sm" :style="{
                    borderColor: config.conexion_estado === 'ok' ? '#16a34a' : '#dc2626',
                    backgroundColor: `color-mix(in srgb, ${config.conexion_estado === 'ok' ? '#16a34a' : '#dc2626'} 8%, transparent)`,
                }">
                    <p class="font-medium">Última prueba: {{ config.conexion_estado === 'ok' ? 'Correcta' : 'Con error' }}</p>
                    <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ config.conexion_mensaje }}</p>
                    <p v-if="config.conexion_probada_en" class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ config.conexion_probada_en }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <BotonPrincipal tipo="button" :procesando="form.processing" texto="Guardar configuración" icono="guardar" @click="guardar" />
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm font-medium"
                        :style="{ borderColor: 'var(--color-acento)', color: 'var(--color-acento)' }"
                        @click="probar"
                    >
                        Probar conexión
                    </button>
                    <span v-if="!config.credenciales_completas_activa" class="text-xs" :style="{ color: '#d97706' }">
                        Faltan credenciales para la etapa activa.
                    </span>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
