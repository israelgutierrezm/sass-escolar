<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotonAccion from '@/Components/BotonAccion.vue';
import BotonPrincipal from '@/Components/BotonPrincipal.vue';
import CampoSelect from '@/Components/CampoSelect.vue';
import CampoTexto from '@/Components/CampoTexto.vue';
import TarjetaSeccion from '@/Components/TarjetaSeccion.vue';
import { ICONOS } from '@/iconos';

/**
 * De qué bolsa sale cada beca, y cuánto llevamos.
 *
 * ── El número dice lo YA aplicado ──────────────────────────────────────────
 * Y hay que decirlo con todas sus letras, porque se lee al revés: no es lo que
 * va a costar el ciclo. Una beca del 40 % no tiene importe hasta que existen
 * los cargos, y proyectarla exigiría inventar cuántos faltan y de cuánto — un
 * número inventado al lado de uno medido se lee igual de cierto.
 *
 * ── Pasarse se avisa, no se bloquea ────────────────────────────────────────
 * Un tope duro impediría la última beca del año a quien la necesita por unos
 * pesos, y esa es una decisión de la dirección. Lo que el sistema garantiza es
 * que nadie se pase sin enterarse.
 */
interface Bolsa {
    patrocinador_id: number;
    patrocinador: string;
    protegido: boolean;
    becas: number;
    asignado: number | null;
    ejercido: number;
    disponible: number | null;
    excedido: boolean;
    notas: string | null;
    otorgadas: number;
}

interface Patrocinador {
    id: number;
    clave: string;
    nombre: string;
    contacto: string | null;
    correo: string | null;
    telefono: string | null;
    notas: string | null;
    activo: boolean;
    protegido: boolean;
    becas: number;
}

const props = defineProps<{
    ciclos: { valor: number; texto: string }[];
    cicloId: number;
    bolsas: Bolsa[];
    patrocinadores: Patrocinador[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

const ciclo = ref(props.cicloId);

function cambiarCiclo(): void {
    router.get('/finanzas/becas/presupuesto', { ciclo: ciclo.value }, { preserveState: true, preserveScroll: true });
}

// --- presupuesto por bolsa
const editando = ref<number | null>(null);
const bolsa = useForm({ patrocinador_id: 0, ciclo_id: props.cicloId, monto: 0, notas: '' });

function abrirBolsa(b: Bolsa): void {
    editando.value = editando.value === b.patrocinador_id ? null : b.patrocinador_id;
    bolsa.patrocinador_id = b.patrocinador_id;
    bolsa.ciclo_id = props.cicloId;
    bolsa.monto = b.asignado ?? 0;
    bolsa.notas = b.notas ?? '';
}

function guardarBolsa(): void {
    bolsa.post('/finanzas/becas/presupuesto', {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    });
}

const totalAsignado = computed(() =>
    props.bolsas.reduce((s, b) => s + (b.asignado ?? 0), 0),
);
const totalEjercido = computed(() => props.bolsas.reduce((s, b) => s + b.ejercido, 0));

// --- patrocinadores
function vacio() {
    return { clave: '', nombre: '', contacto: '', correo: '', telefono: '', notas: '', activo: true };
}

const creando = ref(false);
const editandoP = ref<number | null>(null);
const alta = useForm(vacio());
const datos = useForm(vacio());

function crear(): void {
    alta.post('/finanzas/becas/patrocinadores', { preserveScroll: true, onSuccess: () => alta.reset() });
}

function abrirPatrocinador(p: Patrocinador): void {
    editandoP.value = editandoP.value === p.id ? null : p.id;
    datos.clave = p.clave;
    datos.nombre = p.nombre;
    datos.contacto = p.contacto ?? '';
    datos.correo = p.correo ?? '';
    datos.telefono = p.telefono ?? '';
    datos.notas = p.notas ?? '';
    datos.activo = p.activo;
}

function guardarPatrocinador(p: Patrocinador): void {
    datos.put(`/finanzas/becas/patrocinadores/${p.id}`, {
        preserveScroll: true,
        onSuccess: () => (editandoP.value = null),
    });
}
</script>

<template>
    <Head title="Presupuesto de becas" />

    <AppLayout titulo="Presupuesto de becas">
        <TarjetaSeccion
            titulo="Bolsas del ciclo"
            descripcion="Cuánto hay para becar de cada fuente, y cuánto se ha descontado ya."
            :icono="ICONOS.dinero"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <div class="max-w-xs">
                    <CampoSelect
                        v-model="ciclo"
                        etiqueta="Ciclo"
                        :opciones="ciclos"
                        @update:model-value="cambiarCiclo"
                    />
                </div>

                <!--
                    Lo que este número NO es. Va arriba y no en una nota al pie
                    porque se lee al revés: alguien va a restarlo de la bolsa y
                    creer que eso es lo que queda para el resto del año.
                -->
                <p class="mt-3 text-sm" :style="{ color: 'var(--color-suave)' }">
                    «Ejercido» es lo que las becas <strong>ya descontaron</strong> de los cargos emitidos, no lo
                    que costará el ciclo completo: una beca por porcentaje no tiene importe hasta que existen
                    los cargos. Pasarse de la bolsa no bloquea nada — se avisa, y decidir es de la dirección.
                </p>
            </div>

            <div v-if="bolsas.length" class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Bolsa</th>
                            <th class="px-4 py-3 text-right font-medium">Becas otorgadas</th>
                            <th class="px-4 py-3 text-right font-medium">Asignado</th>
                            <th class="px-4 py-3 text-right font-medium">Ejercido</th>
                            <th class="px-4 py-3 text-right font-medium">Disponible</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="b in bolsas" :key="b.patrocinador_id">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="block font-medium">{{ b.patrocinador }}</span>
                                    <span class="text-[11px]" :style="{ color: 'var(--color-suave)' }">
                                        {{ b.becas }} {{ b.becas === 1 ? 'programa' : 'programas' }} de beca
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ b.otorgadas }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <span v-if="b.asignado !== null">{{ pesos.format(b.asignado) }}</span>
                                    <!--
                                        Sin bolsa asignada NO es cero: es que nadie
                                        ha dicho cuánto hay, y son dos cosas
                                        distintas.
                                    -->
                                    <span v-else :style="{ color: 'var(--color-suave)' }">sin asignar</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ pesos.format(b.ejercido) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <span v-if="b.disponible === null" :style="{ color: 'var(--color-suave)' }">—</span>
                                    <span v-else-if="b.excedido" :style="{ color: '#dc2626' }">
                                        {{ pesos.format(b.disponible) }}
                                    </span>
                                    <span v-else>{{ pesos.format(b.disponible) }}</span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <BotonAccion
                                        :variante="editando === b.patrocinador_id ? 'cerrar' : 'editar'"
                                        @click="abrirBolsa(b)"
                                    />
                                </td>
                            </tr>

                            <tr v-if="b.excedido" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="6" class="px-6 py-2 text-xs" :style="{ color: '#b45309' }">
                                    Esta bolsa ya se pasó de lo asignado por
                                    {{ pesos.format(Math.abs(b.disponible ?? 0)) }}.
                                </td>
                            </tr>

                            <tr v-if="b.notas" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="6" class="px-6 py-2 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ b.notas }}
                                </td>
                            </tr>

                            <tr v-if="editando === b.patrocinador_id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="6" class="px-6 py-4" style="background-color: color-mix(in srgb, var(--color-acento) 4%, transparent)">
                                    <form class="flex flex-wrap items-start gap-3" @submit.prevent="guardarBolsa">
                                        <CampoTexto
                                            v-model.number="bolsa.monto"
                                            etiqueta="Monto asignado"
                                            tipo="number"
                                            requerido
                                            :error="bolsa.errors.monto"
                                        />
                                        <div class="min-w-0 flex-1">
                                            <CampoTexto v-model="bolsa.notas" etiqueta="Notas" :error="bolsa.errors.notas" />
                                        </div>
                                        <BotonPrincipal class="alinea-con-campo" :procesando="bolsa.processing" texto="Guardar" icono="ninguno" />
                                    </form>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td colspan="2" class="px-6 py-3 text-right font-medium">Total del ciclo</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ pesos.format(totalAsignado) }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ pesos.format(totalEjercido) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p v-else class="px-6 py-8 text-center text-sm" :style="{ color: 'var(--color-suave)' }">
                No hay ciclos con los que presupuestar todavía.
            </p>
        </TarjetaSeccion>

        <TarjetaSeccion
            titulo="Patrocinadores"
            descripcion="Quién financia cada programa de beca. «La escuela» es la bolsa propia y no se borra."
            :icono="ICONOS.documento"
            sin-relleno
        >
            <div class="px-6 pt-4">
                <BotonPrincipal v-if="!creando" texto="Agregar patrocinador" icono="crear" @click="creando = true" />

                <form v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="crear">
                    <CampoTexto v-model="alta.clave" etiqueta="Clave" mono requerido :error="alta.errors.clave" />
                    <CampoTexto v-model="alta.nombre" etiqueta="Nombre" requerido :error="alta.errors.nombre" />
                    <CampoTexto v-model="alta.contacto" etiqueta="Contacto" :error="alta.errors.contacto" />
                    <div class="flex items-end gap-2">
                        <BotonPrincipal :procesando="alta.processing" texto="Crear" icono="crear" />
                        <button
                            type="button"
                            class="rounded-lg border px-4 py-2 text-sm"
                            :style="{ borderColor: 'var(--color-borde)' }"
                            @click="creando = false"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="px-6 py-3 font-medium">Patrocinador</th>
                            <th class="px-4 py-3 font-medium">Contacto</th>
                            <th class="px-4 py-3 text-right font-medium">Programas</th>
                            <th class="px-4 py-3 font-medium">Estado</th>
                            <th class="px-6 py-3 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in patrocinadores" :key="p.id">
                            <tr class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td class="px-6 py-3">
                                    <span class="block font-medium">{{ p.nombre }}</span>
                                    <span class="font-mono text-[11px]" :style="{ color: 'var(--color-suave)' }">{{ p.clave }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ p.contacto ?? '—' }}
                                    <span v-if="p.correo" class="block">{{ p.correo }}</span>
                                    <span v-if="p.telefono" class="block">{{ p.telefono }}</span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ p.becas }}</td>
                                <td class="px-4 py-3 text-xs" :style="{ color: 'var(--color-suave)' }">
                                    {{ p.activo ? 'Activo' : 'Apagado' }}
                                    <span v-if="p.protegido" class="block">bolsa propia</span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <BotonAccion
                                        :variante="editandoP === p.id ? 'cerrar' : 'editar'"
                                        @click="abrirPatrocinador(p)"
                                    />
                                </td>
                            </tr>
                            <tr v-if="editandoP === p.id" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                                <td colspan="5" class="px-6 py-4" style="background-color: color-mix(in srgb, var(--color-acento) 4%, transparent)">
                                    <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="guardarPatrocinador(p)">
                                        <CampoTexto
                                            v-model="datos.clave"
                                            etiqueta="Clave"
                                            mono
                                            requerido
                                            :deshabilitado="p.protegido"
                                            :error="datos.errors.clave"
                                        />
                                        <CampoTexto
                                            v-model="datos.nombre"
                                            etiqueta="Nombre"
                                            requerido
                                            :deshabilitado="p.protegido"
                                            :error="datos.errors.nombre"
                                        />
                                        <CampoTexto v-model="datos.contacto" etiqueta="Contacto" :error="datos.errors.contacto" />
                                        <CampoTexto v-model="datos.correo" etiqueta="Correo" :error="datos.errors.correo" />
                                        <CampoTexto v-model="datos.telefono" etiqueta="Teléfono" :error="datos.errors.telefono" />
                                        <CampoTexto v-model="datos.notas" etiqueta="Notas" :error="datos.errors.notas" />
                                        <label v-if="!p.protegido" class="flex items-center gap-2 text-sm">
                                            <input v-model="datos.activo" type="checkbox" />
                                            Activo
                                        </label>
                                        <!--
                                            «La escuela» no se renombra ni se apaga:
                                            es el valor por omisión de toda beca
                                            nueva y hay becas colgando de ella. Sus
                                            datos de contacto sí se llenan.
                                        -->
                                        <p v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">
                                            La bolsa propia no se renombra ni se apaga: es de donde salen las becas
                                            que la escuela absorbe.
                                        </p>
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
        </TarjetaSeccion>
    </AppLayout>
</template>
