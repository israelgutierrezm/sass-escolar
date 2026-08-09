import axios from 'axios';
import { computed, onMounted, ref, type ComputedRef, type Ref } from 'vue';

/**
 * El clima del campus, para quien lo quiera pintar.
 *
 * Vive aparte del componente porque ahora lo consumen dos: la banda de
 * bienvenida del panel y el cielo que la decora. Dos llamadas para el mismo
 * dato en la misma pantalla sería absurdo, y peor aún es que el cielo diga que
 * es de noche mientras la temperatura viene de otra consulta.
 *
 * ── Se pide DESPUÉS de que la página cargó ─────────────────────────────────
 * El panel no espera a un servicio de otro país para pintar. Si no llega nada,
 * `clima` se queda en null y quien lo use simplemente no dibuja: una tarjeta
 * con un error de red no le sirve a nadie.
 */
export interface DiaDeClima {
    fecha: string;
    dia: string;
    maxima: number;
    minima: number;
    lluvia: number;
    icono: string;
    condicion: string;
}

export interface Clima {
    temperatura: number;
    sensacion: number;
    humedad: number;
    viento: number;
    es_de_dia: boolean;
    condicion: string;
    icono: string;
    lugar: string;
    aproximado: boolean;
    actualizado: string;
    proximos: DiaDeClima[];
    aire: { indice: number; etiqueta: string; color: string; recomendacion: string } | null;
}

/**
 * Dónde se recuerda la ubicación que la persona autorizó.
 *
 * En `localStorage` y no en el servidor a propósito: es un dato del dispositivo
 * de alguien, sirve sólo para adornar su panel, y guardarlo en la base de la
 * escuela convertiría un permiso del navegador en un registro de por dónde anda
 * su gente. Quien quiera revocarlo borra los datos del sitio y ya.
 */
const LLAVE_UBICACION = 'acadion.clima.ubicacion';

interface Coordenadas {
    lat: number;
    lon: number;
}

function ubicacionGuardada(): Coordenadas | null {
    try {
        const crudo = localStorage.getItem(LLAVE_UBICACION);
        const d = crudo ? JSON.parse(crudo) : null;

        return typeof d?.lat === 'number' && typeof d?.lon === 'number' ? d : null;
    } catch {
        return null;
    }
}

export function usaClima(): {
    clima: Ref<Clima | null>;
    esDeNoche: ComputedRef<boolean>;
    puedeUbicar: boolean;
    ubicando: Ref<boolean>;
    conMiUbicacion: () => Promise<void>;
} {
    const clima = ref<Clima | null>(null);
    const ubicando = ref(false);

    async function traer(coordenadas: Coordenadas | null): Promise<void> {
        try {
            const { data } = await axios.get('/panel/clima', {
                params: coordenadas ? { lat: coordenadas.lat, lon: coordenadas.lon } : {},
            });

            // El endpoint responde `{}` cuando no se pudo saber —es un JSON de
            // null—, así que no basta con mirar si hubo error de red.
            clima.value = data && typeof data.temperatura === 'number' ? data : null;
        } catch {
            // Silencio a propósito: es la ventana, no información crítica.
            clima.value = null;
        }
    }

    // Con lo ya autorizado, si lo hay: quien dio permiso una vez no tiene que
    // volver a pulsar nada en cada visita.
    onMounted(() => traer(ubicacionGuardada()));

    /**
     * Sólo si el navegador la ofrece Y la página va por HTTPS (o es localhost):
     * fuera de eso `navigator.geolocation` existe pero falla siempre, y ofrecer
     * un botón que no puede funcionar es peor que no ofrecerlo.
     */
    const puedeUbicar = typeof navigator !== 'undefined'
        && 'geolocation' in navigator
        && (window.isSecureContext ?? false);

    /**
     * Pide permiso y vuelve a traer el clima desde donde está la persona.
     *
     * Si lo niega —o el dispositivo no sabe dónde está— no se toca nada: se
     * queda el clima que ya había, que es mejor que un hueco y un mensaje de
     * error por una tarjeta del tiempo.
     */
    async function conMiUbicacion(): Promise<void> {
        if (!puedeUbicar || ubicando.value) {
            return;
        }

        ubicando.value = true;

        try {
            const posicion = await new Promise<GeolocationPosition>((resolver, rechazar) => {
                navigator.geolocation.getCurrentPosition(resolver, rechazar, {
                    // Basta la aproximada: para el clima sobra, y pedir la
                    // precisa enciende el GPS y tarda mucho más.
                    enableHighAccuracy: false,
                    timeout: 8000,
                    maximumAge: 10 * 60 * 1000,
                });
            });

            const coordenadas: Coordenadas = {
                // A tres decimales —unos cien metros—: es lo que el servidor usa
                // como llave de cache, y de paso no se manda la puerta de nadie.
                lat: Number(posicion.coords.latitude.toFixed(3)),
                lon: Number(posicion.coords.longitude.toFixed(3)),
            };

            try {
                localStorage.setItem(LLAVE_UBICACION, JSON.stringify(coordenadas));
            } catch {
                // Modo privado o almacenamiento lleno: funciona igual, sólo que
                // habrá que volver a pulsar la próxima vez.
            }

            await traer(coordenadas);
        } catch {
            // Permiso denegado o sin señal: se queda como estaba.
        } finally {
            ubicando.value = false;
        }
    }

    /*
     * De día mientras no se sepa lo contrario.
     *
     * Es deliberado que `null` cuente como día: el cielo se pinta antes de que
     * llegue la respuesta, y estrenar la pantalla en negro para luego aclararla
     * se ve como un parpadeo de error.
     */
    const esDeNoche = computed(() => clima.value !== null && !clima.value.es_de_dia);

    return { clima, esDeNoche, puedeUbicar, ubicando, conMiUbicacion };
}
