<script setup lang="ts">
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import ZonaArchivo from '@/Components/ZonaArchivo.vue';

/**
 * Los papeles que la escuela le pide AL HIJO, entregados por su tutor.
 *
 * ── Es el expediente del alumno, no uno del padre ──────────────────────────
 * Escribe en la misma tabla que «Mi expediente» del alumno: el acta de
 * nacimiento es suya, la haya subido él o su madre. Por eso esta pantalla dice
 * en primera persona del hijo —«le falta», «le pide»— y no «tus documentos».
 *
 * ── Lo primero es QUÉ FALTA ────────────────────────────────────────────────
 * Misma forma que los otros tres expedientes del sistema. Nadie entra aquí a
 * mirar lo que ya entregó; se entra porque la escuela pidió algo. Sin nada
 * pendiente, el aviso de arriba no se dibuja.
 *
 * ── Y sólo existe mientras el hijo sea menor ───────────────────────────────
 * Cuando cumple la edad que la escuela fijó, el bloque desaparece y en su lugar
 * se dice por qué. Sin esa frase, un padre vería esfumarse la sección de un día
 * para otro y llamaría a la escuela.
 */
interface Documento {
    id: number;
    documento_id: number | null;
    documento: string | null;
    descripcion: string | null;
    estado: string | null;
    estado_clave: string | null;
    vigencia: string | null;
    vencido: boolean;
    observaciones: string | null;
}

const props = defineProps<{
    hijoId: number;
    hijo: string;
    entrega: {
        motivo: string | null;
        edad: number | null;
        mayoria_de_edad: number;
        documentos: Documento[];
        tipos: { id: number; nombre: string; obligatorio: boolean }[];
    };
}>();

const puede = computed(() => props.entrega.motivo === null);

const base = computed(() => `/mis-hijos/${props.hijoId}/documentos`);

const faltantes = computed(() => {
    const entregados = new Set(props.entrega.documentos.map((d) => d.documento_id));

    return props.entrega.tipos.filter((t) => t.obligatorio && !entregados.has(t.id));
});

const rechazados = computed(() => props.entrega.documentos.filter((d) => d.estado_clave === 'rechazado'));
const vencidos = computed(() => props.entrega.documentos.filter((d) => d.vencido && d.estado_clave !== 'rechazado'));

const pendientes = computed(() => faltantes.value.length + rechazados.value.length + vencidos.value.length);

const form = useForm({
    documento_id: null as number | null,
    archivo: null as File | null,
    descripcion: '',
    vigencia: '',
});

function subir(): void {
    form.post(base.value, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
}

function subirEste(tipoId: number): void {
    form.documento_id = tipoId;
    document.getElementById('subir-documento-hijo')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function eliminar(doc: Documento): void {
    if (!confirm(`¿Retirar "${doc.documento}" del expediente de ${props.hijo}?`)) return;

    router.delete(`${base.value}/${doc.id}`, { preserveScroll: true });
}

/** El color habla del estado REAL: un aceptado que venció ya no vale. */
function colorDe(doc: Documento): string {
    if (doc.estado_clave === 'rechazado' || doc.vencido) return '#dc2626';
    if (doc.estado_clave === 'aceptado') return '#16a34a';

    return '#f59e0b';
}

function etiquetaDe(doc: Documento): string {
    if (doc.vencido && doc.estado_clave !== 'rechazado') return 'Vencido';

    return doc.estado ?? 'Pendiente';
}
</script>

<template>
    <section class="tarjeta overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b px-6 py-4" :style="{ borderColor: 'var(--color-borde)' }">
            <h2 class="text-base font-semibold">Sus documentos</h2>
            <span v-if="puede && entrega.edad !== null" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                Puedes entregarlos por {{ hijo }} hasta que cumpla {{ entrega.mayoria_de_edad }} años
            </span>
        </header>

        <!--
            El motivo, cuando no se puede. Va con las mismas palabras que el
            servidor devolvería al negarse: una sola verdad, y quien la lee sabe
            si hay algo que hacer (capturar la fecha) o si sencillamente ya no
            le toca.
        -->
        <p v-if="!puede" class="px-6 py-5 text-sm" :style="{ color: 'var(--color-suave)' }">
            {{ entrega.motivo }}
        </p>

        <template v-else>
            <div
                v-if="pendientes"
                class="border-b px-6 py-4"
                :style="{ borderColor: 'var(--color-borde)', backgroundColor: 'color-mix(in srgb, #f59e0b 8%, transparent)' }"
            >
                <p class="text-sm font-medium">Le falta entregar {{ pendientes }} {{ pendientes === 1 ? 'documento' : 'documentos' }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        v-for="t in faltantes"
                        :key="'f' + t.id"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :style="{ borderColor: 'var(--color-borde)' }"
                        @click="subirEste(t.id)"
                    >
                        {{ t.nombre }}
                    </button>
                    <button
                        v-for="d in [...rechazados, ...vencidos]"
                        :key="'r' + d.id"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :style="{ borderColor: '#dc2626', color: '#dc2626' }"
                        @click="d.documento_id && subirEste(d.documento_id)"
                    >
                        {{ d.documento }} · {{ etiquetaDe(d) }}
                    </button>
                </div>
            </div>

            <ul v-if="entrega.documentos.length" class="divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                <li v-for="d in entrega.documentos" :key="d.id" class="flex flex-wrap items-center justify-between gap-3 px-6 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ d.documento }}</p>
                        <p v-if="d.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">{{ d.descripcion }}</p>
                        <p v-if="d.observaciones" class="mt-0.5 text-xs" :style="{ color: '#dc2626' }">{{ d.observaciones }}</p>
                        <p v-if="d.vigencia" class="text-xs" :style="{ color: 'var(--color-suave)' }">Vigencia: {{ d.vigencia }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span
                            class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium"
                            :style="{ color: colorDe(d), backgroundColor: `color-mix(in srgb, ${colorDe(d)} 14%, transparent)` }"
                        >
                            {{ etiquetaDe(d) }}
                        </span>
                        <a :href="`${base}/${d.id}/descargar`" class="text-xs underline" :style="{ color: 'var(--color-acento)' }">
                            Descargar
                        </a>
                        <!--
                            Lo aceptado no se retira desde aquí: es la constancia
                            de un trámite que la escuela ya dio por bueno, y el
                            expediente no puede cambiar a espaldas de quien lo
                            revisó ni del propio alumno.
                        -->
                        <button
                            v-if="d.estado_clave !== 'aceptado'"
                            type="button"
                            class="text-xs underline"
                            :style="{ color: '#dc2626' }"
                            @click="eliminar(d)"
                        >
                            Quitar
                        </button>
                    </div>
                </li>
            </ul>

            <p v-else class="px-6 py-5 text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no se ha cargado ningún documento suyo.
            </p>

            <form
                id="subir-documento-hijo"
                class="border-t px-6 py-5"
                :style="{ borderColor: 'var(--color-borde)' }"
                @submit.prevent="subir"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-4">
                        <CampoSelect
                            v-model="form.documento_id"
                            etiqueta="Tipo de documento"
                            :opciones="entrega.tipos.map((t) => ({
                                valor: t.id,
                                texto: t.obligatorio ? `${t.nombre} (obligatorio)` : t.nombre,
                            }))"
                            vacio="Selecciona…"
                            :error="form.errors.documento_id"
                        />
                        <CampoTexto
                            v-model="form.vigencia"
                            etiqueta="Vigencia"
                            tipo="date"
                            :error="form.errors.vigencia"
                            ayuda="Solo si vence."
                        />
                    </div>

                    <div>
                        <ZonaArchivo
                            accept=".pdf,.jpg,.jpeg,.png"
                            texto="Arrastra el documento o haz clic para elegirlo"
                            ayuda="PDF o imagen, máximo 5 MB."
                            :cargado="form.archivo?.name ?? null"
                            :ocupado="form.processing"
                            @archivo="(f) => (form.archivo = f)"
                        />
                        <p v-if="form.errors.archivo" class="mt-1 text-xs text-red-600">
                            {{ form.errors.archivo }}
                        </p>
                    </div>
                </div>

                <BotonPrincipal
                    :procesando="form.processing"
                    :deshabilitado="!form.documento_id || !form.archivo"
                    texto="Subir documento"
                    cargando="Subiendo…"
                    icono="ninguno"
                    class="mt-4"
                />
            </form>
        </template>
    </section>
</template>
