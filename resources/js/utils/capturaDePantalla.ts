import axios from 'axios';
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/**
 * Vigilancia de capturas de pantalla durante un examen.
 *
 * ── Léase esto antes de prometerle nada a nadie ────────────────────────────
 * Una página web NO PUEDE impedir una captura de pantalla. La toma el sistema
 * operativo sin consultar al navegador; no hay API que lo evite, ni la habrá,
 * porque sería un agujero de accesibilidad. Y contra la cámara de un celular
 * apuntando al monitor no hay absolutamente nada que hacer.
 *
 * Lo que sí existe es esto:
 *
 * 1. ESTORBAR. Tapar el examen cuando la ventana pierde el foco —que es lo que
 *    pasa al abrir la herramienta de recortes de Windows—, quitar el menú
 *    contextual y el arrastre de texto. No detiene a quien sabe lo que hace;
 *    sí al que lo intenta sin pensar.
 *
 * 2. DEJAR CONSTANCIA de las dos señales que el navegador sí ve: la tecla Impr
 *    Pant y los atajos de captura de macOS. Son las que se registran, así que
 *    el contador dice «al menos esto», nunca «esto y nada más».
 *
 * Se avisa al alumno de que se está registrando. Vigilar sin decirlo es lo que
 * convierte una medida razonable en una trampa, y además funciona peor: lo que
 * disuade es saberse observado.
 */
type Senal = 'impr_pant' | 'atajo_mac';

export function vigilarCapturas(
    intentoId: number,
    opciones: { estorbar: boolean },
): { capturas: Ref<number>; tapado: Ref<boolean> } {
    const capturas = ref(0);
    const tapado = ref(false);

    // Un mismo gesto puede disparar keydown y keyup: sin esto, una sola captura
    // se contaba dos veces y el reporte del docente dejaba de ser creíble.
    let ultima = 0;

    async function registrar(senal: Senal): Promise<void> {
        const ahora = Date.now();

        if (ahora - ultima < 800) return;

        ultima = ahora;
        capturas.value++;

        try {
            await axios.post(`/mis-cursos/intentos/${intentoId}/captura`, { senal });
        } catch {
            /*
             * Si no se pudo avisar, se sigue. Un examen no se interrumpe porque
             * falle la bitácora: el alumno no tiene la culpa de que la red se
             * cayera, y perder su examen sería un castigo mucho peor que el
             * hecho que se estaba registrando.
             */
        }
    }

    function alSoltarTecla(e: KeyboardEvent): void {
        // Impr Pant sólo se detecta al soltar: Windows no emite keydown para
        // ella, se la queda el sistema.
        if (e.key === 'PrintScreen') void registrar('impr_pant');
    }

    function alPulsarTecla(e: KeyboardEvent): void {
        // macOS: Cmd+Shift+3 (pantalla completa), 4 (selección), 5 (panel).
        if (e.metaKey && e.shiftKey && ['3', '4', '5'].includes(e.key)) {
            void registrar('atajo_mac');
        }
    }

    /*
     * Perder el foco NO se cuenta como captura —cambiar de pestaña no lo es—,
     * pero sí tapa el examen: la herramienta de recortes roba el foco, y con el
     * contenido oculto lo que se recorta es el aviso.
     */
    function alPerderFoco(): void {
        tapado.value = true;
    }

    function alRecuperarFoco(): void {
        tapado.value = false;
    }

    function sinMenu(e: Event): void {
        e.preventDefault();
    }

    onMounted(() => {
        window.addEventListener('keyup', alSoltarTecla);
        window.addEventListener('keydown', alPulsarTecla);

        if (opciones.estorbar) {
            window.addEventListener('blur', alPerderFoco);
            window.addEventListener('focus', alRecuperarFoco);
            document.addEventListener('contextmenu', sinMenu);
        }
    });

    onBeforeUnmount(() => {
        window.removeEventListener('keyup', alSoltarTecla);
        window.removeEventListener('keydown', alPulsarTecla);
        window.removeEventListener('blur', alPerderFoco);
        window.removeEventListener('focus', alRecuperarFoco);
        document.removeEventListener('contextmenu', sinMenu);
    });

    return { capturas, tapado };
}
