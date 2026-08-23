<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import BotonVolver from '@/Components/BotonVolver.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import CampoTextarea from '@/Components/CampoTextarea.vue';
import Modal from '@/Components/Modal.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { hoyLocal } from '@/utils/fechas';

interface Esquema {
    id: number;
    modalidad: string | null;
    modalidad_id: number;
    componentes: string[];
    monto_base: string | null;
    tarifa_hora: string | null;
    tarifa_asignatura: string | null;
    desde: string | null;
    hasta: string | null;
    abierto: boolean;
    notas: string | null;
}

const props = defineProps<{
    expediente: { id: number; persona: string | null; numero_empleado: string; vigente: boolean };
    esquemas: Esquema[];
    modalidades: { id: number; nombre: string; componentes: string[] }[];
}>();

const abriendo = ref(false);
const corrigiendo = ref<Esquema | null>(null);

const nuevo = useForm({
    modalidad_id: null as number | null,
    vigente_desde: hoyLocal(),
    monto_base: '',
    tarifa_hora: '',
    tarifa_asignatura: '',
    notas: '',
});

const correccion = useForm({
    monto_base: '',
    tarifa_hora: '',
    tarifa_asignatura: '',
    notas: '',
});

const ETIQUETAS: Record<string, string> = {
    monto_base: 'Monto base mensual',
    tarifa_hora: 'Tarifa por hora',
    tarifa_asignatura: 'Tarifa por asignatura',
};

/*
 * Qué campos pedir lo dice la MODALIDAD elegida, no una lista fija: pintar la
 * tarifa por hora en un sueldo fijo invita a capturar un número que el cálculo
 * no va a usar y que el día que cambie de modalidad se aplicaría solo.
 */
const componentesDelNuevo = computed(
    () => props.modalidades.find((m) => m.id === nuevo.modalidad_id)?.componentes ?? [],
);

const abierto = computed(() => props.esquemas.find((e) => e.abierto) ?? null);

function dinero(v: string | null): string {
    return v === null ? '—' : `$${Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
}

function abrir(): void {
    abriendo.value = true;
    nuevo.reset();
    nuevo.vigente_desde = hoyLocal();
    nuevo.defaults();
}

function guardar(): void {
    nuevo.post(`/rh/empleados/${props.expediente.id}/percepciones`, {
        preserveScroll: true,
        onSuccess: () => {
            abriendo.value = false;
        },
    });
}

function abrirCorreccion(e: Esquema): void {
    corrigiendo.value = e;
    correccion.monto_base = e.monto_base ?? '';
    correccion.tarifa_hora = e.tarifa_hora ?? '';
    correccion.tarifa_asignatura = e.tarifa_asignatura ?? '';
    correccion.notas = e.notas ?? '';
    correccion.defaults();
}

function corregir(): void {
    if (corrigiendo.value === null) return;

    correccion.put(`/rh/empleados/${props.expediente.id}/percepciones/${corrigiendo.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            corrigiendo.value = null;
        },
    });
}
</script>

<template>
    <Head :title="`Sueldo · ${expediente.persona}`" />

    <AppLayout titulo="Esquema de percepción">
        <BotonVolver :href="`/rh/empleados/${expediente.id}`" texto="Volver al expediente" class="mb-4" />

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">{{ expediente.persona }}</h2>
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    <span class="font-mono">{{ expediente.numero_empleado }}</span>
                </p>
            </div>

            <button
                v-if="expediente.vigente"
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium"
                :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }"
                @click="abrir()"
            >
                {{ abierto ? 'Cambiar el sueldo' : 'Fijar el sueldo' }}
            </button>
            <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                Está dado de baja: no se le puede fijar sueldo.
            </span>
        </div>

        <!--
            Un aumento no borra lo anterior: el histórico es lo que permite
            explicar un recibo viejo sin adivinar.
        -->
        <TarjetaSeccion titulo="Historial de sueldos" sin-relleno>
            <ul v-if="esquemas.length">
                <li
                    v-for="e in esquemas"
                    :key="e.id"
                    class="border-t px-6 py-3 text-sm"
                    :style="{ borderColor: 'var(--color-borde)' }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ e.modalidad }}
                                <span
                                    v-if="e.abierto"
                                    class="ml-1 rounded-full px-2 py-0.5 text-xs font-normal"
                                    :style="{
                                        backgroundColor: 'color-mix(in srgb, #16a34a 14%, transparent)',
                                        color: '#16a34a',
                                    }"
                                >
                                    vigente
                                </span>
                            </p>
                            <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                desde el {{ e.desde }}<template v-if="e.hasta"> hasta el {{ e.hasta }}</template>
                            </p>
                            <!--
                                Sólo los componentes que la modalidad usa. Los
                                demás están en NULL a propósito: un cero diría
                                «se le paga cero por hora», que no es lo mismo
                                que «no se le paga por hora».
                            -->
                            <p class="mt-1 text-xs">
                                <template v-for="(c, i) in e.componentes" :key="c">
                                    <template v-if="i > 0"> · </template>
                                    {{ ETIQUETAS[c] }}:
                                    <strong>{{ dinero((e as any)[c]) }}</strong>
                                </template>
                            </p>
                        </div>

                        <button
                            type="button"
                            class="shrink-0 rounded-lg border px-3 py-1.5 text-xs"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="abrirCorreccion(e)"
                        >
                            Corregir cifras
                        </button>
                    </div>

                    <p v-if="e.notas" class="mt-2 whitespace-pre-line text-xs" :style="{ color: 'var(--color-suave)' }">
                        {{ e.notas }}
                    </p>
                </li>
            </ul>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no tiene sueldo fijado. Sin esquema no se le puede generar recibo.
            </p>
        </TarjetaSeccion>

        <Modal v-if="abriendo" etiqueta="Fijar el sueldo" :formulario="nuevo" @cerrar="abriendo = false">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="guardar">
                    <h2 class="text-base font-semibold">Sueldo de {{ expediente.persona }}</h2>
                    <p v-if="abierto" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        El esquema vigente desde el {{ abierto.desde }} se cierra el día antes de la
                        fecha que pongas. No se borra: queda en el historial.
                    </p>

                    <CampoSelect
                        v-model="nuevo.modalidad_id"
                        etiqueta="Modalidad"
                        :opciones="modalidades.map((m) => ({ valor: m.id, texto: m.nombre }))"
                        vacio="Selecciona…"
                        ayuda="Decide qué cifras hacen falta abajo."
                        :error="nuevo.errors.modalidad_id"
                    />

                    <CampoTexto
                        v-model="nuevo.vigente_desde"
                        etiqueta="Vigente desde"
                        tipo="date"
                        requerido
                        :error="nuevo.errors.vigente_desde"
                    />

                    <div v-if="componentesDelNuevo.length" class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto
                            v-for="c in componentesDelNuevo"
                            :key="c"
                            :model-value="(nuevo as any)[c]"
                            :etiqueta="ETIQUETAS[c]"
                            tipo="number"
                            requerido
                            @update:model-value="(v: any) => ((nuevo as any)[c] = v)"
                        />
                    </div>
                    <p v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Elige una modalidad para saber qué cifras pedirte.
                    </p>

                    <CampoTextarea v-model="nuevo.notas" etiqueta="Notas" :filas="2" :error="nuevo.errors.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="nuevo.processing" texto="Guardar sueldo" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>

        <Modal v-if="corrigiendo" etiqueta="Corregir cifras" :formulario="correccion" @cerrar="corrigiendo = null">
            <template #default="{ cerrar }">
                <form class="space-y-4 p-6" @submit.prevent="corregir">
                    <h2 class="text-base font-semibold">{{ corrigiendo.modalidad }}</h2>
                    <!--
                        Se corrigen las CIFRAS, no las fechas: mover la vigencia
                        reacomodaría el tramo que otro esquema ya cubre, y para
                        eso está abrir uno nuevo, que deja rastro de cuándo
                        cambió.
                    -->
                    <p class="text-xs" :style="{ color: 'var(--color-suave)' }">
                        Vigente desde el {{ corrigiendo.desde }}. Para cambiar las fechas o la
                        modalidad, abre un esquema nuevo.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <CampoTexto
                            v-for="c in corrigiendo.componentes"
                            :key="c"
                            :model-value="(correccion as any)[c]"
                            :etiqueta="ETIQUETAS[c]"
                            tipo="number"
                            requerido
                            @update:model-value="(v: any) => ((correccion as any)[c] = v)"
                        />
                    </div>

                    <CampoTextarea v-model="correccion.notas" etiqueta="Notas" :filas="2" :error="correccion.errors.notas" />

                    <div class="flex items-center gap-3 pt-2">
                        <BotonPrincipal :procesando="correccion.processing" texto="Guardar" />
                        <button type="button" class="rounded-lg border border-borde px-4 py-2 text-sm" @click="cerrar">
                            Cancelar
                        </button>
                    </div>
                </form>
            </template>
        </Modal>
    </AppLayout>
</template>
