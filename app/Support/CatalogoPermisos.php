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
    /**
     * Dominio => [permiso => [etiqueta, descripción]].
     *
     * La descripción es lo que lee quien arma un rol y no escribió el sistema.
     * Sin ella, "gestionar-documentos" y "validar-expediente" son
     * indistinguibles desde una casilla.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const CATALOGO = [
        'Personas' => [
            'ver-personas' => ['Ver personas', 'Consultar el directorio de personas de la escuela.'],
            'crear-personas' => ['Dar de alta personas', 'Registrar una persona nueva.'],
            'editar-personas' => ['Editar personas', 'Corregir nombre, CURP y datos de contacto. Alcanza a todas las matrículas de esa persona.'],
        ],

        'Admisiones' => [
            'ver-aspirantes' => ['Ver aspirantes', 'Consultar el embudo de admisión y la ficha de cada prospecto.'],
            'crear-aspirantes' => ['Dar de alta aspirantes', 'Registrar prospectos nuevos.'],
            'editar-aspirantes' => ['Editar aspirantes', 'Modificar los datos de un prospecto y subir su documentación.'],
            'validar-expediente' => ['Validar expedientes', 'Aceptar o rechazar los documentos que entrega un aspirante. Quien sube no valida.'],
            'convertir-aspirante' => ['Convertir en alumno', 'Cerrar la admisión: genera la matrícula. Es el paso irreversible del embudo.'],
            'generar-matricula' => ['Generar matrícula', 'Numerar a un alumno. Cubre reingresos y segundas carreras de quien ya está dentro.'],
            'gestionar-documentos' => ['Administrar el catálogo de documentos', 'Definir qué papeles se le piden a cada tipo de persona.'],
        ],

        'Promoción y CRM' => [
            'ver-mis-prospectos' => ['Ver mis prospectos', 'El promotor ve y da seguimiento SOLO a los aspirantes que le asignaron.'],
            'gestionar-promocion' => ['Coordinar promoción', 'Ver el embudo completo, asignar promotores y mover prospectos de etapa.'],
            'gestionar-comisiones' => ['Administrar comisiones', 'Ver las comisiones de todos, marcarlas pagadas y cancelarlas. Sin esto, cada promotor ve solo las suyas.'],
            'configurar-comisiones' => ['Configurar comisiones', 'Definir cuánto se paga por alumno inscrito y a qué carreras aplica.'],
        ],

        'Control escolar' => [
            'ver-alumnos' => ['Ver alumnos', 'Buscar matrículas y consultar su expediente.'],
            'editar-alumnos' => ['Editar alumnos', 'Corregir su situación y su estatus de inscripción.'],
            'inscribir-alumnos' => ['Inscribir alumnos', 'Dar de alta y de baja materias, con las validaciones de seriación y cupo.'],
            'ver-kardex' => ['Ver kárdex', 'Consultar el historial académico.'],
            'ver-grupos' => ['Ver grupos y ciclos', 'Entrar a la sección de control escolar.'],
            'abrir-grupos' => ['Abrir grupos y materias', 'Crear grupos y poner materias en oferta para un ciclo.'],
            'gestionar-ventanas-captura' => ['Calendario de captura', 'Abrir y cerrar la captura por parcial y conceder excepciones. Queda auditado.'],
            'pasar-lista' => ['Pasar lista', 'Registrar asistencia de clase.'],
        ],

        'Calificaciones' => [
            'capturar-calificaciones' => ['Capturar calificaciones', 'Vaciar los componentes de evaluación. NO alcanza a firmar el acta.'],
            'asentar-acta' => ['Firmar actas', 'Cerrar el acta y asentar en kárdex. Una calificación asentada ya no se edita.'],
        ],

        'Docencia' => [
            'ver-mis-materias' => ['Ver mis materias', 'Portal del docente: solo las materias que imparte.'],
            'editar-mi-expediente' => ['Editar mi expediente', 'Que el docente corrija sus datos y suba sus comprobantes.'],
            'ver-docentes' => ['Ver docentes', 'Consultar el catálogo de docentes y su expediente.'],
            'gestionar-docentes' => ['Administrar docentes', 'Dar de alta, acreditar cédula y dictaminar sus documentos.'],
        ],

        'Académico' => [
            'ver-catalogo-academico' => ['Ver el catálogo académico', 'Campus, carreras, planes, asignaturas y oferta.'],
            'editar-catalogo-academico' => ['Editar el catálogo académico', 'Modificar planes, malla curricular, seriación y criterios de evaluación.'],
        ],

        'Finanzas' => [
            'ver-adeudos' => ['Ver la cartera', 'Consultar saldos y el estado de cuenta de los alumnos.'],
            'registrar-pagos' => ['Registrar pagos', 'Cobrar, confirmar y revertir pagos; generar cargos.'],
            'condonar-adeudos' => ['Condonar y cancelar cargos', 'Perdonar un adeudo. Exige motivo y queda en la bitácora.'],
            'facturar' => ['Emitir CFDI', 'Facturar, cancelar y refacturar. Es un acto fiscal a nombre de la escuela.'],
            'gestionar-planes-cobro' => ['Configurar el cobro', 'Definir montos, periodicidades y reglas de generación de cargos.'],
            'gestionar-emisores' => ['Administrar razones sociales', 'Dar de alta personas morales y cargar sus certificados de sello digital.'],
        ],

        'Plataforma' => [
            'ver-configuracion' => ['Ver la configuración', 'Consultar los parámetros de la escuela.'],
            'editar-configuracion' => ['Editar la configuración', 'Cambiar los parámetros de la escuela.'],
            'gestionar-usuarios' => ['Administrar usuarios', 'Crear cuentas y asignarles roles.'],
            'gestionar-roles' => ['Administrar roles', 'Crear roles y decidir qué puede hacer cada uno. Incluye este permiso.'],
            'suplantar-usuarios' => ['Ver como otra persona', 'Entrar con la identidad de alguien más para dar soporte. Queda en bitácora.'],
            'gestionar-formularios' => ['Constructor de formularios', 'Definir qué datos se piden y en qué versión.'],
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
    public static function paraPantalla(): array
    {
        $salida = [];

        foreach (self::CATALOGO as $dominio => $permisos) {
            $salida[] = [
                'dominio' => $dominio,
                'permisos' => array_map(
                    fn (string $clave, array $datos) => [
                        'clave' => $clave,
                        'etiqueta' => $datos[0],
                        'descripcion' => $datos[1],
                    ],
                    array_keys($permisos),
                    array_values($permisos),
                ),
            ];
        }

        return $salida;
    }

    public static function existe(string $clave): bool
    {
        return in_array($clave, self::claves(), true);
    }
}
