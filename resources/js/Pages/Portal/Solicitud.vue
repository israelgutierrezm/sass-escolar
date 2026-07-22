<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface Paso {
    clave: string;
    titulo: string;
    descripcion: string;
    aplica: boolean;
    completo: boolean;
    faltantes: string[];
    detalle: string;
}

const props = defineProps<{
    progreso: {
        pasos: Paso[];
        porcentaje: number;
        completos: number;
        total: number;
        siguiente: string | null;
    };
    persona: Record<string, any>;
    solicitud: { oferta_id: number | null; oferta: string | null; campus: string | null };
    documentos: {
        id: number;
        nombre: string;
        descripcion: string | null;
        obligatorio: boolean;
        entrega_id: number | null;
        estado: string | null;
        estado_clave: string | null;
        observacion: string | null;
    }[];
    cargos: {
        renglones: {
            concepto: string | null;
            total: number;
            saldo: number;
            vencimiento: string | null;
            vencido: boolean;
            estatus: string;
        }[];
        saldo: number;
    };
    sexos: { id: number; nombre: string }[];
    ofertas: { id: number; nombre: string }[];
}>();

const pesos = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

// Se abre en el primer paso sin terminar: es donde el interesado tiene algo
// que hacer, y obligarlo a buscarlo entre tres secciones es fricción gratis.
const abierto = ref<string>(props.progreso.siguiente ?? props.progreso.pasos[0].clave);

const datos = useForm({
    nombre: props.persona.nombre ?? '',
    primer_apellido: props.persona.primer_apellido ?? '',
    segundo_apellido: props.persona.segundo_apellido ?? '',
    curp: props.persona.curp ?? '',
    email: props.persona.email ?? '',
    celular: props.persona.celular ?? '',
    fecha_nacimiento: props.persona.fecha_nacimiento ?? '',
    sexo_id: props.persona.sexo_id ?? null,
    oferta_id: props.solicitud.oferta_id,
});

function guardarDatos(): void {
    datos.put('/mi-solicitud/datos', { preserveScroll: true });
}

const subida = useForm({ documento_id: null as number | null, archivo: null as File | null });

function subir(documentoId: number, evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0];

    if (!archivo) return;

    subida.documento_id = documentoId;
    subida.archivo = archivo;
    subida.post('/mi-solicitud/documentos', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => subida.reset(),
    });
}

const colorEstado: Record<string, string> = {
    aceptado: 'bg-emerald-50 text-emerald-700',
    pendiente: 'bg-amber-50 text-amber-800',
    rechazado: 'bg-red-50 text-red-700',
};
</script>

<template>
    <Head title="Mi solicitud" />

    <AppLayout titulo="Mi solicitud de admisión">
        <!-- Progreso: el mismo patrón de pasos con barra. -->
        <section class="tarjeta p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">Tu avance</h2>
                    <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                        {{ progreso.completos }} de {{ progreso.total }} pasos completos.
                        <template v-if="progreso.siguiente === null">
                            Ya no te falta nada; la escuela dará seguimiento.
                        </template>
                    </p>
                </div>
                <span class="text-2xl font-semibold tabular-nums">{{ progreso.porcentaje }}%</span>
            </div>

            <div class="mt-3 h-2 w-full rounded-full" :style="{ backgroundColor: 'var(--color-borde)' }">
                <div
                    class="h-2 rounded-full transition-all"
                    :style="{ width: progreso.porcentaje + '%', backgroundColor: 'var(--color-acento)' }"
                ></div>
            </div>

            <ol class="mt-5 grid gap-3 sm:grid-cols-3">
                <li
                    v-for="(paso, i) in progreso.pasos"
                    :key="paso.clave"
                    class="rounded-lg border p-3"
                    :style="{
                        borderColor: abierto === paso.clave ? 'var(--color-acento)' : 'var(--color-borde)',
                        opacity: paso.aplica ? 1 : 0.55,
                    }"
                >
                    <button type="button" class="w-full text-left" :disabled="!paso.aplica" @click="abierto = paso.clave">
                        <div class="flex items-center gap-2">
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                                :style="
                                    paso.completo
                                        ? { backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }
                                        : { border: '1px solid var(--color-borde)', color: 'var(--color-suave)' }
                                "
                            >
                                {{ paso.completo ? '✓' : i + 1 }}
                            </span>
                            <span class="text-sm font-medium">{{ paso.titulo }}</span>
                        </div>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--color-suave)' }">{{ paso.detalle }}</p>
                    </button>
                </li>
            </ol>
        </section>

        <!-- Paso 1: datos -->
        <section v-show="abierto === 'datos'" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Tus datos</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Son los que la escuela necesita para poder registrarte formalmente.
            </p>

            <form class="mt-4 grid gap-4 sm:grid-cols-3" @submit.prevent="guardarDatos">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Nombre(s)</span>
                    <input v-model="datos.nombre" type="text" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Primer apellido</span>
                    <input v-model="datos.primer_apellido" type="text" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Segundo apellido</span>
                    <input v-model="datos.segundo_apellido" type="text" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">CURP</span>
                    <input v-model="datos.curp" type="text" maxlength="18" class="w-full rounded-lg border px-3 py-2 font-mono text-sm uppercase" :style="{ borderColor: 'var(--color-borde)' }" />
                    <span v-if="datos.errors.curp" class="text-xs text-red-600">{{ datos.errors.curp }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Fecha de nacimiento</span>
                    <input v-model="datos.fecha_nacimiento" type="date" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Sexo</span>
                    <select v-model="datos.sexo_id" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null" disabled>Elige…</option>
                        <option v-for="s in sexos" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Correo</span>
                    <input v-model="datos.email" type="email" required class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Celular</span>
                    <input v-model="datos.celular" type="tel" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Programa de interés</span>
                    <select v-model="datos.oferta_id" class="w-full rounded-lg border px-3 py-2 text-sm" :style="{ borderColor: 'var(--color-borde)' }">
                        <option :value="null">Sin elegir</option>
                        <option v-for="o in ofertas" :key="o.id" :value="o.id">{{ o.nombre }}</option>
                    </select>
                </label>

                <div class="sm:col-span-3">
                    <button type="submit" :disabled="datos.processing" class="rounded-lg px-4 py-2 text-sm font-medium disabled:opacity-50" :style="{ backgroundColor: 'var(--color-acento)', color: 'var(--color-acento-texto)' }">
                        Guardar mis datos
                    </button>
                </div>
            </form>
        </section>

        <!-- Paso 2: documentos -->
        <section v-show="abierto === 'documentos'" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Tu documentación</h2>
            <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Sube cada papel en PDF o foto. Alguien de la escuela los revisa: hasta entonces quedan
                como pendientes. Si vuelves a subir uno, reemplaza al anterior y se revisa de nuevo.
            </p>

            <ul class="mt-4 divide-y" :style="{ borderColor: 'var(--color-borde)' }">
                <li v-for="doc in documentos" :key="doc.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div>
                        <p class="text-sm font-medium">
                            {{ doc.nombre }}
                            <span v-if="doc.obligatorio" class="text-red-500">*</span>
                        </p>
                        <p v-if="doc.descripcion" class="text-xs" :style="{ color: 'var(--color-suave)' }">
                            {{ doc.descripcion }}
                        </p>
                        <p v-if="doc.observacion" class="mt-1 text-xs text-red-700">
                            {{ doc.observacion }}
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <span
                            v-if="doc.estado"
                            class="rounded px-2 py-0.5 text-xs font-medium"
                            :class="colorEstado[doc.estado_clave ?? ''] ?? ''"
                        >
                            {{ doc.estado }}
                        </span>
                        <span v-else class="text-xs" :style="{ color: 'var(--color-suave)' }">Sin entregar</span>

                        <a
                            v-if="doc.entrega_id"
                            :href="`/mi-solicitud/documentos/${doc.entrega_id}`"
                            class="text-xs font-medium"
                            :style="{ color: 'var(--color-acento)' }"
                        >
                            Ver
                        </a>

                        <label class="cursor-pointer rounded-lg border px-3 py-1.5 text-xs" :style="{ borderColor: 'var(--color-borde)' }">
                            {{ doc.entrega_id ? 'Reemplazar' : 'Subir' }}
                            <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png" @change="subir(doc.id, $event)" />
                        </label>
                    </div>
                </li>
            </ul>

            <p v-if="!documentos.length" class="mt-4 text-sm" :style="{ color: 'var(--color-suave)' }">
                La escuela no pide documentos en esta etapa.
            </p>
        </section>

        <!-- Paso 3: pago -->
        <section v-show="abierto === 'pago'" class="tarjeta p-6">
            <h2 class="text-base font-semibold">Tu pago</h2>

            <template v-if="cargos.renglones.length">
                <p class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                    Estos son los cargos que la escuela te generó. Para pagarlos, acude a la escuela o
                    sigue las instrucciones que te den: aquí solo los consultas.
                </p>

                <table class="mt-4 w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide" :style="{ color: 'var(--color-suave)' }">
                        <tr>
                            <th class="py-2 font-medium">Concepto</th>
                            <th class="py-2 font-medium">Vence</th>
                            <th class="py-2 text-right font-medium">Total</th>
                            <th class="py-2 text-right font-medium">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(c, i) in cargos.renglones" :key="i" class="border-t" :style="{ borderColor: 'var(--color-borde)' }">
                            <td class="py-2">{{ c.concepto }}</td>
                            <td class="py-2" :class="c.vencido ? 'font-medium text-red-600' : ''">
                                {{ c.vencimiento }}
                                <span v-if="c.vencido" class="text-xs">(vencido)</span>
                            </td>
                            <td class="py-2 text-right tabular-nums">{{ pesos.format(c.total) }}</td>
                            <td class="py-2 text-right font-medium tabular-nums">
                                {{ c.saldo > 0 ? pesos.format(c.saldo) : 'Pagado' }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="mt-4 text-sm">
                    Saldo pendiente:
                    <strong class="tabular-nums" :class="cargos.saldo > 0 ? 'text-red-600' : ''">
                        {{ pesos.format(cargos.saldo) }}
                    </strong>
                </p>
            </template>

            <p v-else class="mt-1 text-sm" :style="{ color: 'var(--color-suave)' }">
                Todavía no hay nada que pagar. Si la escuela te genera un cargo, aparecerá aquí.
            </p>
        </section>
    </AppLayout>
</template>
