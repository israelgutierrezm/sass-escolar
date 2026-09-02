<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoCheckbox from '@/Components/CampoCheckbox.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * La escala de firmas: a partir de cuánto tiene que firmar quién.
 *
 * ── El umbral se mide sobre la BECA, no sobre el dinero ────────────────────
 * Una beca del 40 % no tiene importe hasta que existen los cargos, y quién
 * firma hay que decidirlo al otorgarla. Por eso un nivel declara su escala y
 * sólo mira las becas de esa misma escala.
 */
interface Nivel {
    id: number;
    nombre: string;
    rol_id: number;
    rol: string | null;
    modo: string;
    desde: number;
    umbral: string;
    orden: number;
    activo: boolean;
    pendientes: number;
}

const props = defineProps<{
    niveles: Nivel[];
    roles: { id: number; nombre: string }[];
}>();

const opcionesRol = computed(() => props.roles.map((r) => ({ valor: r.id, texto: r.nombre })));
const opcionesModo = [
    { valor: 'porcentaje', texto: 'Becas por porcentaje' },
    { valor: 'monto_fijo', texto: 'Becas de monto fijo' },
];

function vacio() {
    return { nombre: '', rol_id: props.roles[0]?.id ?? 0, modo: 'porcentaje', desde: 0.5, orden: 1, activo: true };
}

const creando = ref(false);
const editando = ref<number | null>(null);
const alta = useForm(vacio());
const datos = useForm(vacio());

// Misma convención que el alta de la beca: la fracción tal cual, sin
// conversiones. Un formulario que pida «25» y guarde 0.25 mientras el otro pide
// 0.25 es como se llega a un umbral cien veces más alto de lo que se quiso.
const etiquetaDesde = (modo: string) => (modo === 'porcentaje' ? 'Desde (0.25 = 25 %)' : 'Desde (monto)');

function crear(): void {
    alta.post('/finanzas/becas/niveles', {
        preserveScroll: true,
        onSuccess: () => {
            alta.reset();
            creando.value = false;
        },
    });
}

function abrir(n: Nivel): void {
    editando.value = editando.value === n.id ? null : n.id;
    datos.nombre = n.nombre;
    datos.rol_id = n.rol_id;
    datos.modo = n.modo;
    datos.desde = n.desde;
    datos.orden = n.orden;
    datos.activo = n.activo;
}

function guardar(n: Nivel): void {
    datos.put(`/finanzas/becas/niveles/${n.id}`, {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    });
}

const porcentaje = computed(() => props.niveles.filter((n) => n.modo === 'porcentaje'));
const montoFijo = computed(() => props.niveles.filter((n) => n.modo === 'monto_fijo'));
</script>

<template>
    <Head title="Niveles de autorización de becas" />

    <AppLayout titulo="Niveles de autorización de becas">
        <TarjetaSeccion
            titulo="Quién firma, y desde cuánto"
            descripcion="Una beca que alcance un umbral no descuenta nada hasta que su nivel esté firmado."
            :icono="ICONOS.escudo"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <!--
                    Las dos cosas que se leen al revés, arriba y no en una nota
                    al pie: qué se compara, y que sin niveles nada cambia.
                -->
                <p class="text-sm" :style="{ color: 'var(--color-suave)' }">
                    El umbral se compara contra <strong>lo que la beca dice</strong> —40 %, o $3,000—, no contra
                    el dinero que acabará descontando: ese número no existe todavía cuando hay que decidir quién
                    firma. Por eso cada nivel elige su escala y sólo mira las becas de esa escala.
                    <strong>Sin ninguno configurado, las becas se otorgan activas como siempre.</strong>
                </p>

                <div class="mt-4">
                    <BotonPrincipal v-if="!creando" tipo="button" texto="Agregar nivel" icono="crear" @click="creando = true" />
                </div>

                <form v-if="creando" class="mt-4 rounded-lg border p-4" :style="{ borderColor: 'var(--color-borde)' }" @submit.prevent="crear">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <CampoTexto v-model="alta.nombre" etiqueta="Nombre del nivel" requerido :error="alta.errors.nombre" ayuda="Cómo se llama la firma: «Visto bueno de finanzas»." />
                        <CampoSelect v-model="alta.rol_id" etiqueta="Lo firma" :opciones="opcionesRol" requerido :error="alta.errors.rol_id" ayuda="Sólo los roles que pueden autorizar becas." />
                        <CampoSelect v-model="alta.modo" etiqueta="Escala" :opciones="opcionesModo" requerido :error="alta.errors.modo" />
                        <CampoTexto v-model="alta.desde" tipo="number" paso="0.0001" min="0" :etiqueta="etiquetaDesde(alta.modo)" requerido :error="alta.errors.desde" />
                        <CampoTexto v-model="alta.orden" tipo="number" paso="1" min="1" etiqueta="Orden" requerido :error="alta.errors.orden" ayuda="Sólo ordena la lista: las firmas no se esperan unas a otras." />
                        <CampoCheckbox v-model="alta.activo" etiqueta="Activo" />
                    </div>
                    <div class="mt-4 flex gap-2">
                        <BotonPrincipal :procesando="alta.processing" texto="Guardar" icono="crear" />
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="creando = false; alta.reset()"
                        >Cancelar</button>
                    </div>
                </form>

                <p v-if="!roles.length" class="mt-4 text-sm" :style="{ color: 'var(--color-peligro)' }">
                    Ningún rol puede autorizar becas todavía. Concede el permiso «Autorizar becas» en
                    Plataforma → Roles antes de armar la escala: un nivel cuyo rol no puede entrar deja la beca
                    esperando para siempre.
                </p>
            </div>

            <div v-for="grupo in [{ titulo: 'Becas por porcentaje', filas: porcentaje }, { titulo: 'Becas de monto fijo', filas: montoFijo }]" :key="grupo.titulo">
                <h3 v-if="grupo.filas.length" class="mt-6 px-6 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                    {{ grupo.titulo }}
                </h3>

                <div v-if="grupo.filas.length" class="mt-2 overflow-x-auto">
                    <table class="w-full min-w-[46rem] text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                            <tr>
                                <th class="px-6 py-3 font-medium">Nivel</th>
                                <th class="px-4 py-3 font-medium">Lo firma</th>
                                <th class="px-4 py-3 text-right font-medium">Desde</th>
                                <th class="px-4 py-3 text-right font-medium">Esperando</th>
                                <th class="px-6 py-3 text-right font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="n in grupo.filas" :key="n.id">
                                <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                    <td class="px-6 py-3">
                                        <span class="font-medium">{{ n.nombre }}</span>
                                        <span v-if="!n.activo" class="ml-2 rounded px-1.5 py-0.5 text-[11px]" :style="{ background: 'var(--color-fondo-suave)', color: 'var(--color-suave)' }">Apagado</span>
                                    </td>
                                    <td class="px-4 py-3">{{ n.rol ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ n.umbral }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ n.pendientes || '—' }}</td>
                                    <td class="px-6 py-3 text-right">
                                        <BotonAccion :variante="editando === n.id ? 'cerrar' : 'editar'" @click="abrir(n)" />
                                    </td>
                                </tr>
                                <tr v-if="editando === n.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                    <td colspan="5" class="px-6 py-4">
                                        <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardar(n)">
                                            <CampoTexto v-model="datos.nombre" etiqueta="Nombre del nivel" requerido :error="datos.errors.nombre" />
                                            <CampoSelect v-model="datos.rol_id" etiqueta="Lo firma" :opciones="opcionesRol" requerido :error="datos.errors.rol_id" />
                                            <CampoSelect v-model="datos.modo" etiqueta="Escala" :opciones="opcionesModo" requerido :error="datos.errors.modo" />
                                            <CampoTexto v-model="datos.desde" tipo="number" paso="0.0001" min="0" :etiqueta="etiquetaDesde(datos.modo)" requerido :error="datos.errors.desde" />
                                            <CampoTexto v-model="datos.orden" tipo="number" paso="1" min="1" etiqueta="Orden" requerido :error="datos.errors.orden" />
                                            <CampoCheckbox v-model="datos.activo" etiqueta="Activo" :ayuda="n.pendientes ? `Hay ${n.pendientes} beca(s) esperando esta firma: no se puede apagar todavía.` : undefined" />
                                            <div class="sm:col-span-2 lg:col-span-3">
                                                <BotonPrincipal :procesando="datos.processing" texto="Guardar" />
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <p v-if="!niveles.length" class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay ningún nivel. Las becas se otorgan activas de inmediato.
            </p>
        </TarjetaSeccion>
    </AppLayout>
</template>
