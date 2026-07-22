<?php

declare(strict_types=1);

namespace App\Support;

/**
 * El catálogo de permisos del sistema, con su dominio y su explicación.
 *
 * **Los permisos NO se crean desde pantalla, y es deliberado.** Un permiso es
 * una llave que el código consulta (`can:asentar-acta`); uno inventado desde la
 * interfaz no lo comprobaría ninguna ruta y sería inerte — daría la sensación
 * de haber restringido algo sin restringir nada. Lo que SÍ es configurable, y
 * es lo que la escuela necesita, son los ROLES: cuáles existen y qué permisos
 * lleva cada uno.
 *
 * Vive aquí y no en el seeder porque lo consultan dos: el seeder al sembrar y
 * la pantalla de roles al pintar las casillas agrupadas. Tenerlo en el seeder
 * dejaba la agrupación por dominio invisible para la interfaz.
 *
 * Al agregar un permiso nuevo: se declara aquí, se siembra con
 * `php artisan tenants:seed --class=...PermisoSeeder` y se usa en la ruta.
 */
final class CatalogoPermisos
{
    /*
     * Las facetas a las que puede pertenecer un permiso.
     *
     * Un permiso NO se le puede dar a cualquier rol: pertenece al oficio que lo
     * ejerce. Un administrativo no debe poder concederse «Ver mis materias»
     * —eso es del docente— porque entonces el conmutador de rol deja de tener
     * sentido: si un administrador puede verlo todo desde su rol, nadie
     * conmuta, y el alcance por asignación (`docente_asignatura_grupo`,
     * `aspirante_asesor`) queda colgando de un permiso que no debería tener.
     *
     * Los que aparecen en VARIAS facetas es porque el oficio de verdad se
     * comparte: control escolar captura calificaciones en nombre del docente
     * ausente, y el kárdex lo consultan cinco perfiles distintos sobre alcances
     * distintos.
     */
    public const ADMINISTRATIVO = 'administrativo';

    public const DOCENTE = 'docente';

    public const ALUMNO = 'alumno';

    public const ASPIRANTE = 'aspirante';

    public const TUTOR = 'tutor_educativo';

    public const PADRE = 'padre_familia';

    /**
     * Dominio => [permiso => [etiqueta, descripción]].
     *
     * La descripción es lo que lee quien arma un rol y no escribió el sistema.
     * Sin ella, "gestionar-documentos" y "validar-expediente" son
     * indistinguibles desde una casilla.
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: array<int, string>}>>
     */
    private const CATALOGO = [
        'Personas' => [
            'ver-personas' => ['Ver personas', 'Consultar el directorio de personas de la escuela.', [self::ADMINISTRATIVO]],
            'crear-personas' => ['Dar de alta personas', 'Registrar una persona nueva.', [self::ADMINISTRATIVO]],
            'editar-personas' => ['Editar personas', 'Corregir nombre, CURP y datos de contacto. Alcanza a todas las matrículas de esa persona.', [self::ADMINISTRATIVO]],
        ],

        'Admisiones' => [
            'ver-aspirantes' => ['Ver aspirantes', 'Consultar el embudo de admisión y la ficha de cada prospecto.', [self::ADMINISTRATIVO]],
            'crear-aspirantes' => ['Dar de alta aspirantes', 'Registrar prospectos nuevos.', [self::ADMINISTRATIVO]],
            'editar-aspirantes' => ['Editar aspirantes', 'Modificar los datos de un prospecto y subir su documentación.', [self::ADMINISTRATIVO]],
            'validar-expediente' => ['Validar expedientes', 'Aceptar o rechazar los documentos que entrega un aspirante. Quien sube no valida.', [self::ADMINISTRATIVO]],
            'convertir-aspirante' => ['Convertir en alumno', 'Cerrar la admisión: genera la matrícula. Es el paso irreversible del embudo.', [self::ADMINISTRATIVO]],
            'generar-matricula' => ['Generar matrícula', 'Numerar a un alumno. Cubre reingresos y segundas carreras de quien ya está dentro.', [self::ADMINISTRATIVO]],
            'gestionar-documentos' => ['Administrar el catálogo de documentos', 'Definir qué papeles se le piden a cada tipo de persona.', [self::ADMINISTRATIVO]],
        ],

        'Portal del interesado' => [
            'llenar-mi-solicitud' => ['Llenar mi solicitud', 'El aspirante captura sus datos, sube su documentación y consulta lo que debe. Solo lo SUYO: no recibe id por la URL.', [self::ASPIRANTE]],
        ],

        'Promoción y CRM' => [
            'ver-mis-prospectos' => ['Ver mis prospectos', 'El promotor ve y da seguimiento SOLO a los aspirantes que le asignaron.', [self::ADMINISTRATIVO]],
            'gestionar-promocion' => ['Coordinar promoción', 'Ver el embudo completo, asignar promotores y mover prospectos de etapa.', [self::ADMINISTRATIVO]],
            'gestionar-comisiones' => ['Administrar comisiones', 'Ver las comisiones de todos, marcarlas pagadas y cancelarlas. Sin esto, cada promotor ve solo las suyas.', [self::ADMINISTRATIVO]],
            'configurar-comisiones' => ['Configurar comisiones', 'Definir cuánto se paga por alumno inscrito y a qué carreras aplica.', [self::ADMINISTRATIVO]],
        ],

        'Control escolar' => [
            'ver-alumnos' => ['Ver alumnos', 'Buscar matrículas y consultar su expediente.', [self::ADMINISTRATIVO, self::TUTOR]],
            'editar-alumnos' => ['Editar alumnos', 'Corregir su situación y su estatus de inscripción.', [self::ADMINISTRATIVO]],
            'inscribir-alumnos' => ['Inscribir alumnos', 'Dar de alta y de baja materias, con las validaciones de seriación y cupo.', [self::ADMINISTRATIVO]],
            'ver-kardex' => ['Ver kárdex', 'Consultar el historial académico.', [self::ADMINISTRATIVO, self::DOCENTE, self::ALUMNO, self::TUTOR, self::PADRE]],
            'ver-grupos' => ['Ver grupos y ciclos', 'Entrar a la sección de control escolar.', [self::ADMINISTRATIVO]],
            'abrir-grupos' => ['Abrir grupos y materias', 'Crear grupos y poner materias en oferta para un ciclo.', [self::ADMINISTRATIVO]],
            'gestionar-ventanas-captura' => ['Calendario de captura', 'Abrir y cerrar la captura por parcial y conceder excepciones. Queda auditado.', [self::ADMINISTRATIVO]],
            'pasar-lista' => ['Pasar lista', 'Registrar asistencia de clase.', [self::ADMINISTRATIVO, self::DOCENTE]],
        ],

        'Calificaciones' => [
            'capturar-calificaciones' => ['Capturar calificaciones', 'Vaciar los componentes de evaluación. NO alcanza a firmar el acta.', [self::ADMINISTRATIVO, self::DOCENTE]],
            'asentar-acta' => ['Firmar actas', 'Cerrar el acta y asentar en kárdex. Una calificación asentada ya no se edita.', [self::ADMINISTRATIVO, self::DOCENTE]],
        ],

        'Docencia' => [
            'ver-mis-materias' => ['Ver mis materias', 'Portal del docente: solo las materias que imparte.', [self::DOCENTE]],
            'editar-mi-expediente' => ['Editar mi expediente', 'Que el docente corrija sus datos y suba sus comprobantes.', [self::DOCENTE]],
            'ver-docentes' => ['Ver docentes', 'Consultar el catálogo de docentes y su expediente.', [self::ADMINISTRATIVO]],
            'gestionar-docentes' => ['Administrar docentes', 'Dar de alta, acreditar cédula y dictaminar sus documentos.', [self::ADMINISTRATIVO]],
        ],

        'Académico' => [
            'ver-catalogo-academico' => ['Ver el catálogo académico', 'Campus, carreras, planes, asignaturas y oferta.', [self::ADMINISTRATIVO]],
            'editar-catalogo-academico' => ['Editar el catálogo académico', 'Modificar planes, malla curricular, seriación y criterios de evaluación.', [self::ADMINISTRATIVO]],
        ],

        'Finanzas' => [
            'ver-adeudos' => ['Ver la cartera', 'Consultar saldos y el estado de cuenta de los alumnos.', [self::ADMINISTRATIVO, self::ALUMNO, self::PADRE]],
            'registrar-pagos' => ['Registrar pagos', 'Cobrar, confirmar y revertir pagos; generar cargos.', [self::ADMINISTRATIVO]],
            'condonar-adeudos' => ['Condonar y cancelar cargos', 'Perdonar un adeudo. Exige motivo y queda en la bitácora.', [self::ADMINISTRATIVO]],
            'facturar' => ['Emitir CFDI', 'Facturar, cancelar y refacturar. Es un acto fiscal a nombre de la escuela.', [self::ADMINISTRATIVO]],
            'gestionar-planes-cobro' => ['Configurar el cobro', 'Definir montos, periodicidades y reglas de generación de cargos.', [self::ADMINISTRATIVO]],
            'gestionar-emisores' => ['Administrar razones sociales', 'Dar de alta personas morales y cargar sus certificados de sello digital.', [self::ADMINISTRATIVO]],
        ],

        'Plataforma' => [
            'ver-configuracion' => ['Ver la configuración', 'Consultar los parámetros de la escuela.', [self::ADMINISTRATIVO]],
            'editar-configuracion' => ['Editar la configuración', 'Cambiar los parámetros de la escuela.', [self::ADMINISTRATIVO]],
            'gestionar-usuarios' => ['Administrar usuarios', 'Crear cuentas y asignarles roles.', [self::ADMINISTRATIVO]],
            'gestionar-roles' => ['Administrar roles', 'Crear roles y decidir qué puede hacer cada uno. Incluye este permiso.', [self::ADMINISTRATIVO]],
            'suplantar-usuarios' => ['Ver como otra persona', 'Entrar con la identidad de alguien más para dar soporte. Queda en bitácora.', [self::ADMINISTRATIVO]],
            'gestionar-formularios' => ['Constructor de formularios', 'Definir qué datos se piden y en qué versión.', [self::ADMINISTRATIVO]],
        ],
    ];

    /**
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    public static function porDominio(): array
    {
        return self::CATALOGO;
    }

    /**
     * Todas las claves, sin agrupar. Es lo que siembra `PermisoSeeder`.
     *
     * @return array<int, string>
     */
    public static function claves(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::CATALOGO)));
    }

    /**
     * El catálogo en la forma que consume la pantalla de roles.
     *
     * @return array<int, array{dominio: string, permisos: array<int, array{clave: string, etiqueta: string, descripcion: string}>}>
     */
    /**
     * El catálogo para la pantalla de roles, ACOTADO a una faceta.
     *
     * Se filtra en el servidor y no en el front: la pantalla es una comodidad,
     * la regla es que un rol no puede recibir permisos de un oficio que no es
     * el suyo. `RolController` vuelve a filtrar al guardar, porque un POST
     * armado a mano no pasa por ninguna casilla.
     *
     * Un dominio que se queda sin permisos para esa faceta no se envía: una
     * sección vacía solo hace ruido.
     *
     * @return array<int, array{dominio: string, permisos: array<int, array{clave: string, etiqueta: string, descripcion: string}>}>
     */
    public static function paraPantalla(?string $faceta = null): array
    {
        $salida = [];

        foreach (self::CATALOGO as $dominio => $permisos) {
            $delDominio = [];

            foreach ($permisos as $clave => $datos) {
                if ($faceta !== null && ! in_array($faceta, $datos[2], true)) {
                    continue;
                }

                $delDominio[] = [
                    'clave' => $clave,
                    'etiqueta' => $datos[0],
                    'descripcion' => $datos[1],
                ];
            }

            if ($delDominio !== []) {
                $salida[] = ['dominio' => $dominio, 'permisos' => $delDominio];
            }
        }

        return $salida;
    }

    /**
     * Las claves que una faceta puede recibir.
     *
     * @return array<int, string>
     */
    public static function clavesDe(string $faceta): array
    {
        $claves = [];

        foreach (self::CATALOGO as $permisos) {
            foreach ($permisos as $clave => $datos) {
                if (in_array($faceta, $datos[2], true)) {
                    $claves[] = $clave;
                }
            }
        }

        return $claves;
    }

    /** Si ese permiso le corresponde a esa faceta. */
    public static function correspondeA(string $clave, string $faceta): bool
    {
        foreach (self::CATALOGO as $permisos) {
            if (isset($permisos[$clave])) {
                return in_array($faceta, $permisos[$clave][2], true);
            }
        }

        return false;
    }

    public static function existe(string $clave): bool
    {
        return in_array($clave, self::claves(), true);
    }
}
