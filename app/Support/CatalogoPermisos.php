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
     * ausente, y el historial académico lo consultan cinco perfiles distintos sobre alcances
     * distintos.
     */
    public const ADMINISTRATIVO = 'administrativo';

    public const DOCENTE = 'docente';

    public const ALUMNO = 'alumno';

    public const ASPIRANTE = 'aspirante';

    public const TUTOR = 'tutor_educativo';

    public const PADRE = 'padre_familia';

    /**
     * Las facetas que existen. Una sola lista, a propósito.
     *
     * Había otra igual escrita a mano dentro de `Rol::ambitoDePermisos()`, y el
     * menú del frontend tiene una tercera en `resources/js/menu/catalogo.ts`.
     * Que estas etiquetas se escriban a mano en varios lados ya costó caro: en
     * la cartera se comparaba contra `'padre'` y `'tutor'`, que no son ninguna
     * de éstas, y como el código fallaba abierto un padre de familia terminó
     * viendo la cartera completa de la escuela. Un error de dedo aquí no da
     * error de PHP: da una comparación que nunca se cumple.
     *
     * `FacetasConsistentesTest` comprueba que las otras dos listas no se
     * separen de ésta.
     *
     * @var array<int, string>
     */
    public const FACETAS = [
        self::ADMINISTRATIVO,
        self::DOCENTE,
        self::ALUMNO,
        self::ASPIRANTE,
        self::TUTOR,
        self::PADRE,
    ];

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
            /*
             * NO existen `ver-personas` ni `crear-personas`, y es a propósito.
             *
             * Los dos estaban declarados y NINGUNA ruta los comprobaba. Un
             * permiso asignable que no abre ninguna puerta se palomea creyendo
             * que concede algo, y no concede nada.
             *
             * `crear-personas`: una persona nunca se crea sola. Nace dentro del
             * alta de un aspirante, un alumno, un docente, un tutor o un
             * usuario, y cada una de esas ya tiene su permiso.
             *
             * `ver-personas` (retirado el 2026-08-28) prometía «consultar el
             * directorio de personas de la escuela», y ese directorio NO EXISTE
             * — ni existía cuando se declaró—. Tampoco debería: a una persona no
             * se la consulta en abstracto, se la consulta como alumna, docente,
             * prospecto, tutora o cuenta, y cada uno de esos listados tiene su
             * permiso Y su alcance —por campus, por asignación—. Un directorio
             * plano sería precisamente la puerta que se salta todos esos
             * alcances a la vez.
             *
             * La cara de una persona sí se sirve por un endpoint común
             * (`FotoPersonaController`), y ahí la regla NO es un permiso
             * genérico: es la de cada oficio, comprobada una por una.
             */
            'editar-personas' => ['Editar personas', 'Corregir nombre, CURP y datos de contacto. Alcanza a todas las matrículas de esa persona.', [self::ADMINISTRATIVO]],
        ],

        'Admisiones' => [
            'ver-aspirantes' => ['Ver aspirantes', 'Consultar el embudo de admisión y la ficha de cada prospecto.', [self::ADMINISTRATIVO]],
            'crear-aspirantes' => ['Dar de alta aspirantes', 'Registrar prospectos nuevos.', [self::ADMINISTRATIVO]],
            'editar-aspirantes' => ['Editar aspirantes', 'Modificar los datos de un prospecto y subir su documentación.', [self::ADMINISTRATIVO]],
            'validar-expediente' => ['Validar expedientes', 'Aceptar o rechazar los documentos que entregan aspirantes, alumnos y padres o tutores. Quien sube no valida, y un rechazo tiene que decir por qué.', [self::ADMINISTRATIVO]],
            'convertir-aspirante' => ['Convertir en alumno', 'Cerrar la admisión: genera la matrícula. Es el paso irreversible del embudo.', [self::ADMINISTRATIVO]],
            'generar-matricula' => ['Generar matrícula', 'Numerar a un alumno. Cubre reingresos y segundas programas académicos de quien ya está dentro.', [self::ADMINISTRATIVO]],
            'gestionar-documentos' => ['Administrar el catálogo de documentos', 'Definir qué papeles se le piden a cada tipo de persona.', [self::ADMINISTRATIVO]],
            'configurar-matriculas' => ['Configurar el formato de matrícula', 'Definir con qué se arma la matrícula y ajustar los consecutivos. Toca la numeración de TODA la escuela: no es lo mismo que poder numerar a un alumno.', [self::ADMINISTRATIVO]],
        ],

        'Portal del interesado' => [
            'llenar-mi-solicitud' => ['Llenar mi solicitud', 'El aspirante captura sus datos, sube su documentación y consulta lo que debe. Solo lo SUYO: no recibe id por la URL.', [self::ASPIRANTE]],
        ],

        'Captación y CRM' => [
            'ver-mis-prospectos' => ['Ver mis prospectos', 'El promotor ve y da seguimiento SOLO a los aspirantes que le asignaron.', [self::ADMINISTRATIVO]],
            'gestionar-captacion' => ['Coordinar captación', 'Ver el embudo completo, asignar promotores y mover prospectos de etapa.', [self::ADMINISTRATIVO]],
            'gestionar-comisiones' => ['Administrar comisiones', 'Ver las comisiones de todos, marcarlas pagadas y cancelarlas. Sin esto, cada promotor ve solo las suyas.', [self::ADMINISTRATIVO]],
            'configurar-comisiones' => ['Configurar comisiones', 'Definir cuánto se paga por alumno inscrito y a qué programas académicos aplica.', [self::ADMINISTRATIVO]],
        ],

        'Control escolar' => [
            // SIN el tutor educativo: este permiso abre el listado de TODA la
            // escuela, y su alcance real son sus tutorados. Lo suyo es
            // `ver-mis-tutorados`, que resuelve por el vínculo en `tutorias`.
            'ver-alumnos' => ['Ver alumnos', 'Buscar matrículas y consultar su expediente. Alcanza a toda la escuela.', [self::ADMINISTRATIVO]],
            'editar-alumnos' => ['Editar alumnos', 'Corregir su situación y su estatus de inscripción.', [self::ADMINISTRATIVO]],
            'inscribir-alumnos' => ['Inscribir alumnos', 'Dar de alta y de baja materias, con las validaciones de seriación y cupo.', [self::ADMINISTRATIVO]],

            /*
             * La trayectoria administrativa. Se parte en tres porque son tres
             * decisiones distintas: consultarla la necesita cualquiera que
             * atienda al alumno; registrarla, quien tramita; y CORREGIR un
             * movimiento ya asentado es un acto de excepción, como el acta de
             * corrección — no se le da a quien captura todos los días.
             */
            'ver-movimientos-escolares' => ['Ver movimientos escolares', 'Consultar la trayectoria administrativa de una matrícula: altas, bajas, reingresos y cambios.', [self::ADMINISTRATIVO]],
            'registrar-movimiento-escolar' => ['Registrar movimientos escolares', 'Asentar a mano un movimiento que ningún proceso emitió. No permite editar ni borrar los ya asentados.', [self::ADMINISTRATIVO]],
            'corregir-movimiento-escolar' => ['Corregir movimientos escolares', 'Enmendar un movimiento ya asentado emitiendo otro que lo corrige. El original se conserva.', [self::ADMINISTRATIVO]],
            'ver-historial-academico' => ['Ver historial académico', 'Consultar las materias cursadas de una matrícula, con su calificación y sus créditos.', [self::ADMINISTRATIVO, self::DOCENTE, self::ALUMNO, self::TUTOR, self::PADRE]],
            'gestionar-tutorias' => ['Asignar tutorías', 'Repartir los alumnos entre los tutores educativos, por ciclo.', [self::ADMINISTRATIVO]],
            /*
             * Separado de `ver-historial-academico`, que lo tienen cinco
             * facetas: una cosa es CONSULTAR el historial de alguien y otra
             * decidir cómo se imprime el documento de toda la escuela —qué
             * columnas lleva, quién lo firma y si el alumno se lo puede
             * descargar—. Lo segundo es de dirección o de servicios escolares.
             */
            'gestionar-historial' => ['Diseñar el historial académico', 'Definir cómo se imprime el historial: columnas, agrupación, firma y si el alumno puede descargarlo.', [self::ADMINISTRATIVO]],
            'gestionar-recursos-digitales' => ['Gestionar la recursos digitales', 'Publicar, ordenar y retirar los enlaces que el alumno ve en los recursos digitales.', [self::ADMINISTRATIVO]],
            /*
             * Atender el mostrador y decidir el catálogo son la misma tarea de
             * ventanilla —quien entrega las constancias es quien sabe cuáles se
             * ofrecen—, así que van con un solo permiso. Ponerle PRECIO, en
             * cambio, es de Finanzas y pide `gestionar-planes-cobro`.
             */
            'atender-servicios' => ['Atender solicitudes de servicio', 'Elegir qué servicios puede pedir el alumno y resolver sus solicitudes.', [self::ADMINISTRATIVO]],
            /*
             * APARTE de `gestionar-tutorias` a propósito.
             *
             * Aquél permite repartir tutorías y ver CUÁNTAS sesiones lleva cada
             * alumno, que es lo que hace falta para supervisar que la tutoría
             * ocurre. Éste abre lo que se DIJO en ellas, y ahí hay notas de
             * situación personal: quien coordina el reparto no necesita
             * leerlas, y en muchas escuelas es orientación o dirección quien
             * debe hacerlo.
             */
            'ver-bitacoras-tutoria' => ['Leer bitácoras de tutoría', 'Abrir lo que el tutor anotó en cada sesión. Puede incluir situaciones personales del alumno.', [self::ADMINISTRATIVO]],
            'ver-grupos' => ['Ver grupos y ciclos', 'Entrar a la sección de control escolar.', [self::ADMINISTRATIVO]],
            'abrir-grupos' => ['Abrir grupos y materias', 'Crear grupos y poner materias en oferta para un ciclo.', [self::ADMINISTRATIVO]],
            'gestionar-ventanas-captura' => ['Calendario de captura', 'Abrir y cerrar la captura por parcial y conceder excepciones. Queda auditado.', [self::ADMINISTRATIVO]],
            'pasar-lista' => ['Pasar lista', 'Registrar asistencia de clase.', [self::ADMINISTRATIVO, self::DOCENTE]],
        ],

        'Calificaciones' => [
            'capturar-calificaciones' => ['Capturar calificaciones', 'Vaciar los componentes de evaluación. NO alcanza a firmar el acta.', [self::ADMINISTRATIVO, self::DOCENTE]],
            'asentar-acta' => ['Firmar actas', 'Cerrar el acta y asentar en historial académico. Una calificación asentada ya no se edita.', [self::ADMINISTRATIVO, self::DOCENTE]],
        ],

        'Reportes' => [
            /*
             * UNO solo, y a proposito.
             *
             * Lo que se puede sacar en cada reporte lo decide el permiso de su
             * FUENTE --el de matriculas exige `ver-alumnos`--, asi que un
             * permiso por area seria pedirle dos veces lo mismo a la escuela y
             * abrir la puerta a concederle «reportes de finanzas» a quien no
             * puede ver la cartera. Este solo abre la seccion; que haya algo
             * dentro depende de lo que ya podia ver.
             */
            /*
             * Acomodar el indice es OTRA cosa que consultarlo.
             *
             * Quien saca reportes todos los dias no tiene por que poder
             * reorganizar la seccion para todos los demas; y quien la organiza
             * --normalmente direccion-- no necesariamente saca reportes.
             * Mover un reporte de area NO cambia quien lo ve: eso lo decide el
             * permiso de su fuente.
             */
            'gestionar-areas-reporte' => ['Organizar los reportes', 'Renombrar las areas, reordenarlas y mover reportes de un area a otra. NO concede acceso a ningun dato: quien ve cada reporte lo sigue decidiendo el permiso de su fuente.', [self::ADMINISTRATIVO]],
            'ver-reportes' => ['Entrar a Reportes', 'Ver la seccion de reportes. Cada reporte ademas exige el permiso de los datos que saca: quien no ve la cartera no vera los reportes de finanzas.', [self::ADMINISTRATIVO]],

            /*
             * AUDITAR es otra pregunta que sacar.
             *
             * La bitacora contesta "quien se llevo el padron de 900 alumnos y
             * con que filtros", asi que es un permiso de control interno y no de
             * consulta: quien saca reportes todos los dias no tiene por que ver
             * lo que sacan los demas, y quien vigila --direccion, control
             * interno-- no necesariamente saca ninguno.
             *
             * Y NO concede ver los datos: la bitacora guarda los FILTROS y las
             * COLUMNAS que se pidieron, nunca las filas. Quien la audita ve que
             * alguien exporto la cartera del campus norte, no la cartera.
             */
            'auditar-reportes' => ['Auditar el uso de los reportes', 'Ver quien corrio cada reporte, con que filtros y cuantas filas se llevo. NO concede ver los datos de ningun reporte: la bitacora guarda lo que se pidio, no lo que salio.', [self::ADMINISTRATIVO]],
        ],

        /*
         * Servicio social, prácticas y demás procesos formativos.
         *
         * ── Sólo se declara lo que YA tiene lector ────────────────────────
         * El módulo se construye por fases y su matriz completa de permisos
         * está en `docs/plan-procesos-formativos.md` §4. Aquí llega cada uno
         * el día que existe la ruta que lo comprueba: un permiso declarado sin
         * puerta se palomea en `/plataforma/roles` creyendo que concede algo, y
         * este proyecto ya tuvo que retirar dos así —`ver-personas` y
         * `crear-personas`—.
         *
         * ── Y las separaciones que vienen, para que no se fundan ──────────
         * Cuando lleguen: REVISAR horas ≠ APROBARLAS (quien captura en
         * ventanilla no es quien valida que ese tiempo cuente); aprobar
         * SOLICITUDES ≠ aprobar EXCEPCIONES (saltarse un requisito configurado
         * es un acto de dirección); y LIBERAR ≠ CORREGIR una liberación
         * (emitir es rutina, enmendar lo emitido es excepción, como en los
         * movimientos escolares).
         *
         * Ninguno ignora el ALCANCE: el permiso dice QUÉ, y el campus y la
         * asignación dicen SOBRE QUIÉN.
         */
        'Servicio social y prácticas' => [
            'configurar-procesos-formativos' => ['Configurar los procesos', 'Los tipos de proceso —servicio social, prácticas, residencia…— y sus catálogos: qué exige cada uno y si lleva bitácora de horas.', [self::ADMINISTRATIVO]],
            'ver-procesos-formativos' => ['Consultar servicio social y prácticas', 'Sólo lectura del módulo, para dirección y auditoría.', [self::ADMINISTRATIVO]],
        ],

        'Disciplina' => [
            'gestionar-incidencias' => ['Gestionar incidencias', 'Registrar y editar las incidencias de conducta de los alumnos.', [self::ADMINISTRATIVO]],
            'levantar-incidencia' => ['Levantar una incidencia', 'Que el docente registre una incidencia de un alumno de sus grupos.', [self::DOCENTE]],
            'gestionar-sanciones' => ['Gestionar sanciones', 'Aplicar y editar las sanciones, citando las incidencias que las originaron.', [self::ADMINISTRATIVO]],
            'ver-conducta-hijo' => ['Ver la conducta de mi hijo', 'Portal del padre o tutor familiar: el historial de incidencias y sanciones de su hijo.', [self::PADRE]],
        ],

        'Familia' => [
            'ver-mis-hijos' => ['Ver a mis hijos', 'Portal del padre o tutor familiar: la información de los alumnos que tiene vinculados.', [self::PADRE]],
            // El alcance NO lo da este permiso sino el vínculo en `tutorias`:
            // deja entrar al portal, y a quiénes ve lo decide a quién acompaña.
            'ver-mis-tutorados' => ['Ver a mis tutorados', 'Portal del tutor educativo: los alumnos que acompaña académicamente.', [self::TUTOR]],
            /*
             * El expediente del TUTOR, no el de su hijo: su identificación, su
             * comprobante de domicilio. Va aparte de `ver-mis-hijos` por lo
             * mismo que el del alumno va aparte de `ver-mis-cursos`: hay
             * escuelas donde los papeles del padre se entregan en ventanilla y
             * el portal sólo sirve para consultar.
             */
            /*
             * Pedirle a las familias que autoricen algo. Aparte de
             * `gestionar-avisos` porque no es lo mismo informar que pedir
             * permiso: una autorización tiene consecuencias legales y la
             * escuela puede querer que sólo la emita dirección.
             */
            /*
             * VER el tablero. Postularse solo depende además del interruptor
             * `bolsa.postulacion_autogestiva`: son dos preguntas —si a esta
             * persona le toca ver las vacantes, y si la escuela deja que se
             * postule sin pasar por ventanilla—.
             */
            'ver-vacantes' => ['Ver la bolsa de trabajo', 'Que el alumno o egresado vea las vacantes que aplican a su programa académico.', [self::ALUMNO]],
            /*
             * Recursos humanos: el expediente laboral de quien trabaja aquí.
             *
             * Aparte de `ver-docentes`, que es del catálogo ACADÉMICO —cédula,
             * tipo de docente, a qué materias se le puede asignar—. Quien
             * administra la nómina no tiene por qué ver la carga académica, y
             * quien revisa expedientes docentes no tiene por qué ver sueldos.
             */
            /*
             * Movilidad. Aparte de `gestionar-rh` y de control escolar porque
             * es otra oficina —vinculación internacional— y porque la segunda
             * rebanada le va a dejar ESCRIBIR en el historial académico: eso no
             * puede colgar de un permiso que se reparte por otra razón.
             */
            'gestionar-movilidad' => ['Movilidad e intercambios', 'Convenios con otras instituciones, convocatorias, postulaciones y estancias.', [self::ADMINISTRATIVO]],
            'gestionar-rh' => ['Recursos humanos', 'Dar de alta expedientes laborales, adscribir a un puesto y dar de baja.', [self::ADMINISTRATIVO]],
            /*
             * Los SUELDOS, aparte del expediente.
             *
             * Quien captura altas, bajas y adscripciones no necesariamente
             * puede ver cuánto gana cada quien: es el dato más sensible del
             * sistema y en muchas escuelas sólo lo toca dirección. Es la misma
             * separación que `registrar-pagos` frente a `gestionar-planes-cobro`.
             */
            'gestionar-percepciones' => ['Sueldos y percepciones', 'Ver y fijar cuánto se le paga a cada empleado, y administrar los conceptos de nómina.', [self::ADMINISTRATIVO]],
            'gestionar-bolsa-trabajo' => ['Gestionar la bolsa de trabajo', 'Registrar empleadores, publicar vacantes y dar seguimiento a las postulaciones.', [self::ADMINISTRATIVO]],
            'gestionar-autorizaciones' => ['Gestionar autorizaciones', 'Pedirle a los padres o tutores que autoricen salidas, uso de imagen y actividades.', [self::ADMINISTRATIVO]],
            'editar-mi-expediente-tutor' => ['Editar mi expediente (tutor)', 'Que el padre o tutor suba los documentos que la escuela le pide a él.', [self::PADRE]],
            'ver-tutores' => ['Ver padres y tutores', 'Consultar el directorio de padres y tutores y a qué alumnos están vinculados.', [self::ADMINISTRATIVO]],
            // Separado de `ver-tutores` a propósito: quien consulta el
            // directorio no por eso debe poder capturar los datos personales
            // de un padre de familia. Los VÍNCULOS siguen editándose desde el
            // expediente del alumno; esto es sólo para sus formularios.
            'editar-tutores' => ['Editar padres y tutores', 'Llenar los formularios que la escuela le pide a un padre o tutor.', [self::ADMINISTRATIVO]],
        ],

        /*
         * Portal del alumno. Se llama «mis cursos» y no «mis materias» a
         * propósito: el docente ya tiene `ver-mis-materias` para las que
         * IMPARTE, y dos permisos con el mismo nombre y distinto significado
         * son una trampa a la hora de asignarlos.
         */
        'Portal del alumno' => [
            'ver-mis-cursos' => ['Ver mis cursos', 'Portal del alumno: las materias que cursa, su evaluación, sus calificaciones y su asistencia.', [self::ALUMNO]],
            // Propio y no el del docente: son dos expedientes distintos, con
            // documentos distintos, y un docente que además estudia no debería
            // heredar el suyo del otro rol.
            'editar-mi-expediente-alumno' => ['Editar mi expediente (alumno)', 'Que el alumno corrija sus datos y suba los documentos que la escuela le pide.', [self::ALUMNO]],
            /*
             * El permiso es de quién ENTRA; el interruptor de la sección, de si
             * la escuela la tiene abierta. Son dos cosas distintas y hacen falta
             * las dos: apagar los recursos digitales no debe obligar a repartir permisos
             * de nuevo, y quitarle el permiso a un rol no debe cerrarla para
             * todos los demás.
             */
            'ver-recursos-digitales' => ['Ver la recursos digitales', 'Portal del alumno: los enlaces y recursos que la escuela publica.', [self::ALUMNO]],
            'solicitar-servicios' => ['Solicitar servicios', 'Portal del alumno: pedir constancias, credenciales y demás trámites del catálogo, y pagarlos si tienen costo.', [self::ALUMNO]],
        ],

        'Docencia' => [
            'ver-mis-materias' => ['Ver mis materias', 'Portal del docente: solo las materias que imparte.', [self::DOCENTE]],
            'editar-mi-expediente' => ['Editar mi expediente', 'Que el docente corrija sus datos y suba sus comprobantes.', [self::DOCENTE]],
            'ver-docentes' => ['Ver docentes', 'Consultar el catálogo de docentes y su expediente.', [self::ADMINISTRATIVO]],
            // Cuándo puede dar clase cada docente y qué materias sabe dar. Es
            // el insumo de la generación de horarios, y sirve solo: contesta
            // «¿a quién le doy esta materia?» aunque nunca se genere nada.
            'gestionar-disponibilidad' => ['Disponibilidad y perfil docente', 'Capturar los horarios en que cada docente puede dar clase y las materias que sabe impartir.', [self::ADMINISTRATIVO]],
            // Ver y capturar el horario de un grupo. Aparte de generarlo: la
            // captura manual siempre hace falta —el motor deja materias sin
            // colocar y alguien tiene que resolverlas—.
            'editar-horarios' => ['Capturar horarios', 'Armar el horario de un grupo: agregar, mover y quitar clases.', [self::ADMINISTRATIVO]],
            'generar-horarios' => ['Generar horarios', 'Proponer un horario automáticamente a partir de la disponibilidad docente y las reglas.', [self::ADMINISTRATIVO]],
            'editar-mi-disponibilidad' => ['Declarar mi disponibilidad', 'Que el docente diga en qué horarios puede dar clase.', [self::DOCENTE]],
            'gestionar-docentes' => ['Administrar docentes', 'Dar de alta, acreditar cédula y dictaminar sus documentos.', [self::ADMINISTRATIVO]],
        ],

        'Académico' => [
            'ver-catalogo-academico' => ['Ver el catálogo académico', 'Campus, programas académicos, planes, asignaturas y oferta.', [self::ADMINISTRATIVO]],
            'editar-catalogo-academico' => ['Editar el catálogo académico', 'Modificar planes, malla curricular, seriación y criterios de evaluación.', [self::ADMINISTRATIVO]],
            /*
             * Las rúbricas DE LA ESCUELA, que las ve y las usa todo el mundo.
             *
             * Las suyas propias las arma cada docente sin este permiso: le
             * basta `capturar-calificaciones`, que es exactamente el acto. Lo
             * que este permiso concede es publicar para todos —y editar lo que
             * ya publicó alguien—, que es otra responsabilidad.
             */
            'gestionar-rubricas' => ['Administrar las rúbricas de la escuela', 'Publicar rúbricas para toda la escuela y editar las que ya están publicadas. Las suyas propias las arma cada docente sin esto.', [self::ADMINISTRATIVO]],
        ],

        'Finanzas' => [
            'ver-adeudos' => ['Ver la cartera', 'Consultar saldos y el estado de cuenta de los alumnos.', [self::ADMINISTRATIVO, self::ALUMNO, self::PADRE]],
            'registrar-pagos' => ['Registrar pagos', 'Cobrar, confirmar y revertir pagos; generar cargos.', [self::ADMINISTRATIVO]],
            'condonar-adeudos' => ['Condonar y cancelar cargos', 'Perdonar un adeudo. Exige motivo y queda en la bitácora.', [self::ADMINISTRATIVO]],
            'facturar' => ['Emitir CFDI', 'Facturar, cancelar y refacturar. Es un acto fiscal a nombre de la escuela.', [self::ADMINISTRATIVO]],
            'gestionar-planes-cobro' => ['Configurar el cobro', 'Definir montos, periodicidades y reglas de generación de cargos.', [self::ADMINISTRATIVO]],
            'gestionar-emisores' => ['Administrar razones sociales', 'Dar de alta personas morales y cargar sus certificados de sello digital.', [self::ADMINISTRATIVO]],
            'cerrar-periodo-fiscal' => ['Cerrar el periodo fiscal', 'Declarar un mes cerrado, con lo que deja de poderse cancelar un comprobante suyo. Es un acto de supervisión, aparte de emitir CFDI todos los días.', [self::ADMINISTRATIVO]],
            'gestionar-cajas' => ['Administrar cajas', 'Dar de alta los mostradores donde se recibe dinero, por campus. No es cobrar: es decidir qué cajas existen.', [self::ADMINISTRATIVO]],
            'operar-caja' => ['Operar una caja', 'Abrir el turno con su fondo, cobrar dentro de él y cerrarlo contando el efectivo.', [self::ADMINISTRATIVO]],
            'gestionar-presupuesto' => ['Administrar el presupuesto', 'Definir centros de costo, partidas y cuánto se autoriza gastar en cada cruce. Es una decisión de dirección: dice de cuánto dispone cada área para el ciclo.', [self::ADMINISTRATIVO]],
            'registrar-egresos' => ['Registrar egresos', 'Capturar el dinero que sale, contra su centro de costo y su partida. Va aparte de administrar el presupuesto: quien captura el gasto del día no es quien decide de cuánto dispone cada área.', [self::ADMINISTRATIVO]],
            'autorizar-convenios' => ['Autorizar convenios de pago', 'Acordar con un alumno que su deuda se pague en parcialidades. Decide CUÁNDO se le cobra —y con ello si queda bloqueado—, así que va aparte de cobrar: quien está en el mostrador no tiene por qué poder darle seis meses a nadie.', [self::ADMINISTRATIVO]],
            'conciliar-banco' => ['Conciliar el banco', 'Importar el estado de cuenta y casarlo contra los cobros y depósitos del sistema. Es supervisión: va aparte de cobrar, porque quien registra el dinero no debería ser quien declara que llegó.', [self::ADMINISTRATIVO]],
            'autorizar-becas' => ['Autorizar becas', 'Firmar el nivel que le corresponda a su rol para que una beca entre en vigor. Va aparte de otorgarla: quien la propone no es quien la aprueba, y mientras falte una firma la beca no descuenta nada.', [self::ADMINISTRATIVO]],
            'autorizar-corte-caja' => ['Autorizar diferencias de caja', 'Explicar y dar por buena la diferencia de un corte que no cuadró. Va aparte de operar: quien cuenta el dinero no puede autorizarse a sí mismo su propio faltante.', [self::ADMINISTRATIVO]],
        ],

        'Certificación y titulación' => [
            'gestionar-certificacion' => ['Certificación electrónica', 'Administrar la configuración de la certificación: responsables que firman y sus catálogos.', [self::ADMINISTRATIVO]],
            'gestionar-titulacion' => ['Titulación electrónica', 'Administrar la configuración de la titulación: responsables que firman y sus catálogos.', [self::ADMINISTRATIVO]],
            'certificar-alumnos' => ['Certificar alumnos', 'Armar lotes de certificación, agregar alumnos que ya cerraron su plan y firmar sus certificados. Descargar el XML sellado.', [self::ADMINISTRATIVO]],
            'titular-alumnos' => ['Titular alumnos', 'Armar lotes de titulación, agregar egresados, firmar sus títulos y enviarlos al web service de la SEP. Descargar el XML sellado.', [self::ADMINISTRATIVO]],
        ],

        'Plataforma' => [
            'ver-configuracion' => ['Ver la configuración', 'Consultar los parámetros de la escuela.', [self::ADMINISTRATIVO]],
            'editar-configuracion' => ['Editar la configuración', 'Cambiar los parámetros de la escuela.', [self::ADMINISTRATIVO]],
            'gestionar-usuarios' => ['Administrar usuarios', 'Crear cuentas y asignarles roles.', [self::ADMINISTRATIVO]],
            /*
             * Las credenciales de Zoom / Meet y el pool de licencias.
             *
             * Aparte de `editar-configuracion` porque no es un parámetro de la
             * escuela: son secretos con los que se crean reuniones a nombre de
             * la institución, y cuántas licencias hay decide cuántas clases
             * simultáneas se pueden dar. Quien programa una clase NO necesita
             * esto: le basta tener la materia asignada.
             */
            'gestionar-clases-en-linea' => ['Configurar clases en línea', 'Encender Zoom o Google Meet, guardar sus credenciales y administrar las licencias de anfitrión. No hace falta para dar clase: eso lo abre tener la materia asignada.', [self::ADMINISTRATIVO]],
            'gestionar-avisos' => ['Publicar avisos', 'Redactar avisos, dirigirlos a quien corresponda y ver quién confirmó haberlos leído.', [self::ADMINISTRATIVO]],
            'gestionar-encuestas' => ['Administrar encuestas de evaluación', 'Armar cuestionarios, aplicarlos y consultar los resultados. Quien lo tiene ve los promedios de cada docente.', [self::ADMINISTRATIVO]],
            'ver-accesos' => ['Ver la bitácora de accesos', 'Consultar el registro y la gráfica de entradas y salidas de las cuentas.', [self::ADMINISTRATIVO]],
            'configurar-facturacion' => ['Configurar facturación', 'Conectar la escuela con Facturapi: credenciales, ambiente y predeterminados de CFDI.', [self::ADMINISTRATIVO]],
            'configurar-correo' => ['Configurar correo', 'Ajustar el envío de correo de la escuela.', [self::ADMINISTRATIVO]],
            'gestionar-roles' => ['Administrar roles', 'Crear roles y decidir qué puede hacer cada uno. Incluye este permiso.', [self::ADMINISTRATIVO]],
            'suplantar-usuarios' => ['Ver como otra persona', 'Entrar con la identidad de alguien más para dar soporte. Queda en bitácora.', [self::ADMINISTRATIVO]],
            'gestionar-formularios' => ['Constructor de formularios', 'Definir qué datos se piden y en qué versión.', [self::ADMINISTRATIVO]],
            'gestionar-calendario' => ['Administrar el calendario', 'Publicar eventos y días feriados, y decidir a quién le llegan. Todos ven su agenda; sólo con esto se escribe en ella. Los avisos van aparte.', [self::ADMINISTRATIVO]],
            'ver-indicadores' => ['Ver indicadores financieros', 'La UMA y el tipo de cambio en el panel. Para quien cobra, factura o arma becas; a un alumno no le dice nada.', [self::ADMINISTRATIVO]],
            /*
             * Separado de `gestionar-roles` aunque se configure POR rol: quien
             * diseña el gafete de la escuela es quien ve la imagen institucional
             * —a veces la propia dirección—, y no tiene por qué poder repartir
             * permisos. Al revés también: dar de alta un rol nuevo no debería
             * exigir saber de tipografías.
             */
            'gestionar-credenciales' => ['Configurar la credencial virtual', 'Definir el diseño, el tamaño y qué datos lleva la credencial de cada rol, y decidir si se emite.', [self::ADMINISTRATIVO]],
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
