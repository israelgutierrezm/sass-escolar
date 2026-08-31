<?php

declare(strict_types=1);

namespace App\Services\Familia;

use App\Configuracion\Ajustes;
use App\Configuracion\CatalogoAjustes;
use App\Models\Identidad\Persona;
use App\Models\Identidad\TutorAlumno;

/**
 * Qué puede hacer un tutor EN NOMBRE de su hijo, y hasta cuándo.
 *
 * ── El hueco que cierra ────────────────────────────────────────────────────
 * `tutores_alumno` declaraba qué puede VER el tutor —lo académico, lo
 * financiero— y nada sobre qué puede ENTREGAR por él. El expediente del alumno
 * (`documentos_alumno`) sólo lo alimentaba el propio alumno, así que en una
 * secundaria, donde el papeleo lo lleva el padre, la escuela tenía que
 * cobrárselo en ventanilla. Decisión del cliente (2026-08-31): sí puede,
 * mientras el hijo sea MENOR DE EDAD y la escuela lo permita.
 *
 * ── La edad es un AJUSTE, no un 18 escrito en el código ────────────────────
 * Es la primera cosa que este servicio consulta y la razón de que exista. 18 es
 * la mayoría de edad en México y por eso es el valor por omisión, pero es un
 * dato de la escuela: hay alumnado extranjero y hay programas donde la escuela
 * decide tratar como menor a quien todavía no cumple 21. Se lee de
 * `familia.mayoria_de_edad`.
 *
 * ── Y QUÉ puede hacer también, uno por uno ─────────────────────────────────
 * Hoy hay un solo acto —entregar documentos— con su propio interruptor
 * (`familia.tutor_entrega_documentos`), y NO un catálogo de «actos delegables»:
 * cada acto es una rama de código con su ruta, su validación y su pantalla, así
 * que una fila nueva en una tabla no haría nada. Es el mismo criterio por el
 * que `tipos_actividad` no es catálogo. El siguiente acto que se delegue trae
 * su propio ajuste y su propio lector, aquí.
 *
 * ── Una sola verdad, para las dos capas ────────────────────────────────────
 * Este servicio lo usan la PANTALLA —para dibujar o no la sección, y para decir
 * por qué no— y el CONTROLADOR —para negarse—. Con la regla escrita dos veces,
 * el día que una cambie la pantalla ofrecería lo que el servidor rechaza.
 */
class RepresentacionDelTutor
{
    public function __construct(private readonly Ajustes $ajustes) {}

    /** La edad de hoy, o null si no se puede saber. */
    public function edad(Persona $hijo): ?int
    {
        return $hijo->fecha_nacimiento?->age;
    }

    public function mayoriaDeEdad(): int
    {
        return $this->ajustes->entero(CatalogoAjustes::MAYORIA_DE_EDAD);
    }

    /**
     * ¿Es menor de edad?
     *
     * Sin fecha de nacimiento la respuesta es NO, no «quizá»: quien no puede
     * demostrar que representa a un menor no lo representa. Falla cerrado, y el
     * motivo de abajo dice exactamente qué falta para que la escuela lo capture
     * en vez de creer que la sección está rota.
     */
    public function esMenor(Persona $hijo): bool
    {
        $edad = $this->edad($hijo);

        return $edad !== null && $edad < $this->mayoriaDeEdad();
    }

    /**
     * ¿La escuela permite este acto?
     *
     * Va aparte del motivo porque produce otra respuesta: apagado, la sección
     * no existe para nadie y su dirección devuelve **404**; el resto de los
     * motivos son de ESTE tutor con ESTE hijo y devuelven 403. Misma decisión
     * que la postulación autogestiva de la bolsa y que la descarga del
     * historial: lo que la escuela no contrató no está, no es que no te toque.
     */
    public function laEscuelaPermiteEntregarDocumentos(): bool
    {
        return $this->ajustes->bool(CatalogoAjustes::TUTOR_ENTREGA_DOCUMENTOS);
    }

    /**
     * Por qué NO puede entregar por este hijo, o null si sí puede.
     *
     * Devuelve el texto y no un booleano porque las tres razones tienen salidas
     * distintas —vincularse, capturar la fecha, o ninguna— y un «no se puede»
     * pelado manda a la gente a soporte.
     */
    public function motivoParaNoEntregarDocumentos(?TutorAlumno $vinculo, Persona $hijo): ?string
    {
        if ($vinculo === null) {
            return 'Este alumno no está vinculado a tu cuenta.';
        }

        $edad = $this->edad($hijo);

        if ($edad === null) {
            return 'No podemos saber su edad: a su expediente le falta la fecha de nacimiento. '
                .'Pídela en control escolar.';
        }

        if ($edad >= $this->mayoriaDeEdad()) {
            return sprintf(
                'Ya cumplió %d años y entrega sus documentos él mismo desde su portal.',
                $edad,
            );
        }

        return null;
    }

    /** Atajo para las dos capas: puede, sin importar por qué no. */
    public function puedeEntregarDocumentos(?TutorAlumno $vinculo, Persona $hijo): bool
    {
        return $this->laEscuelaPermiteEntregarDocumentos()
            && $this->motivoParaNoEntregarDocumentos($vinculo, $hijo) === null;
    }
}
