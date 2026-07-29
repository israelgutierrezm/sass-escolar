<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';

interface Responsable {
    id: number;
    nombre_completo: string;
    curp: string;
    cargo: string | null;
    titulo: string | null;
    cer_titular: string | null;
    cer_serial: string | null;
    vigencia_inicio: string | null;
    vigencia_fin: string | null;
}

interface DatosCert {
    titular: string;
    nombre: string;
    apellido_paterno: string;
    apellido_materno: string;
    curp: string;
    serial: string;
    vigencia_inicio: string;
    vigencia_fin: string;
}

const props = defineProps<{
    seccion: string;
    tituloSeccion: string;
    maximo: number;
    responsables: Responsable[];
    cargos: { id: number; nombre: string }[];
    titulos: { id: number; abreviatura: string; descripcion: string }[];
}>();

const base = computed(() => `/${props.seccion}/configuracion/responsables`);
const puedeAgregar = computed(() => props.responsables.length < props.maximo);

// Datos leídos del .cer (previsualización); null hasta que se sube uno válido.
const cert = ref<DatosCert | null>(null);
const leyendo = ref(false);
const errorCert = ref<string | null>(null);

const form = useForm<{ certificado: File | null; cargo_id: number | null; titulo_profesional_id: number | null }>({
    certificado: null,
    cargo_id: null,
    titulo_profesional_id: null,
});

async function alSeleccionarCert(evento: Event): Promise<void> {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    cert.value = null;
    errorCert.value = null;
    form.certificado = archivo;

    if (!archivo) {
        return;
    }

    leyendo.value = true;
    try {
        const datos = new FormData();
        datos.append('certificado', archivo);
        const { data } = await axios.post(`${base.value}/leer-certificado`, datos);
        cert.value = data;
    } catch (e: any) {
        errorCert.value = e?.response?.data?.error ?? 'No se pudo leer el certificado.';
        form.certificado = null;
    } finally {
        leyendo.value = false;
    }
}

function guardar(): void {
    form.post(base.value, {
        onSuccess: () => {
            form.reset();
            cert.value = null;
        },
    });
}

function eliminar(r: Responsable): void {
    if (!confirm(`¿Eliminar al responsable ${r.nombre_completo}?`)) {
        return;
    }
    router.delete(`${base.value}/${r.id}`, { preserveScroll: true });
}

const opcionesCargo = computed(() => props.cargos.map((c) => ({ valor: c.id, texto: c.nombre })));
const opcionesTitulo = computed(() => props.titulos.map((t) => ({ valor: t.id, texto: `${t.abreviatura} — ${t.descripcion}` })));
</script>

<template>
    <Head :title="`Responsables · ${tituloSeccion}`" />

    <AppLayout :titulo="`${tituloSeccion} · Responsables`">
        <p class="mb-4 text-sm text-red-600">Los campos marcados con asterisco (*) son obligatorios</p>

        <!-- Responsables ya registrados -->
        <section v-if="responsables.length" class="tarjeta mb-6 p-6">
            <h2 class="text-base font-semibold">Responsables registrados ({{ responsables.length }}/{{ maximo }})</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div
                    v-for="r in responsables"
                    :key="r.id"
                    class="rounded-lg border p-4"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold">{{ r.titulo ? `${r.titulo} ` : '' }}{{ r.nombre_completo }}</p>
                            <p class="font-mono text-xs" :style="{ color: 'var(--color-suave)' }">{{ r.curp }}</p>
                            <p class="mt-1 text-sm">{{ r.cargo ?? '—' }}</p>
                            <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">
                                .cer: {{ r.cer_titular }} · serie {{ r.cer_serial }}<br />
                                vigencia {{ r.vigencia_inicio }} – {{ r.vigencia_fin }}
                            </p>
                        </div>
                        <button type="button" class="text-sm text-red-600 hover:text-red-700" @click="eliminar(r)">
                            Eliminar
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Alta (solo si aún hay cupo) -->
        <section v-if="puedeAgregar" class="tarjeta p-6">
            <div class="grid gap-8 md:grid-cols-2">
                <!-- Izquierda: datos del .cer -->
                <div>
                    <h3 class="text-center text-base font-semibold" :style="{ color: 'var(--color-acento)' }">
                        Datos del certificado seleccionado
                    </h3>

                    <div v-if="cert" class="mt-4 rounded-lg border p-4 text-center" :style="{ borderColor: 'var(--color-borde)' }">
                        <p class="text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">Titular del .cer</p>
                        <p class="mt-1 font-semibold">{{ cert.titular }}</p>
                        <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Válido desde</p>
                                <p>{{ cert.vigencia_inicio }}</p>
                            </div>
                            <div>
                                <p class="text-xs" :style="{ color: 'var(--color-suave)' }">Válido hasta</p>
                                <p>{{ cert.vigencia_fin }}</p>
                            </div>
                        </div>
                        <p class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">CURP</p>
                        <p class="font-mono">{{ cert.curp }}</p>
                        <p class="mt-3 text-xs" :style="{ color: 'var(--color-suave)' }">Serie: {{ cert.serial }}</p>
                    </div>

                    <label class="mt-4 block cursor-pointer text-center text-sm" :style="{ color: 'var(--color-acento)' }">
                        <span v-if="!cert">Selecciona el archivo <b>.cer</b> del responsable</span>
                        <span v-else>Subir un .cer diferente</span>
                        <input type="file" accept=".cer" class="hidden" @change="alSeleccionarCert" />
                    </label>
                    <p v-if="leyendo" class="mt-2 text-center text-xs" :style="{ color: 'var(--color-suave)' }">Leyendo certificado…</p>
                    <p v-if="errorCert" class="mt-2 text-center text-xs text-red-600">{{ errorCert }}</p>
                </div>

                <!-- Derecha: completar cargo y título -->
                <div>
                    <h3 class="text-center text-base font-semibold">Datos del responsable</h3>

                    <form class="mt-4 space-y-4" @submit.prevent="guardar">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium">Nombre</label>
                                <input :value="cert?.nombre ?? ''" disabled class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo)' }" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">CURP</label>
                                <input :value="cert?.curp ?? ''" disabled class="w-full rounded-lg border px-3 py-2 font-mono text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo)' }" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Apellido Paterno</label>
                                <input :value="cert?.apellido_paterno ?? ''" disabled class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo)' }" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">Apellido Materno</label>
                                <input :value="cert?.apellido_materno ?? ''" disabled class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'var(--color-fondo)' }" />
                            </div>
                        </div>

                        <CampoSelect
                            v-model="form.titulo_profesional_id"
                            etiqueta="Título profesional *"
                            vacio="Seleccione una opción"
                            :opciones="opcionesTitulo"
                            :error="form.errors.titulo_profesional_id"
                        />
                        <CampoSelect
                            v-model="form.cargo_id"
                            etiqueta="Cargo *"
                            vacio="Seleccione una opción"
                            :opciones="opcionesCargo"
                            :error="form.errors.cargo_id"
                        />

                        <div class="flex justify-end">
                            <BotonPrincipal :procesando="form.processing" texto="Guardar" icono="guardar" :deshabilitado="!cert" />
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <section v-else class="tarjeta p-6 text-sm" :style="{ color: 'var(--color-suave)' }">
            Ya se registró el máximo de responsables ({{ maximo }}) para {{ tituloSeccion.toLowerCase() }}.
            Elimina uno para poder registrar otro.
        </section>
    </AppLayout>
</template>
