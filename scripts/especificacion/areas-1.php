<?php

/**
 * Contenido de la especificación — áreas 1 a 3.
 *
 * Las listas de tablas y pantallas se escriben aquí a mano PERO salieron de
 * consultar el sistema (modelos con su tabla real, y rutas con el `can:` que
 * exige cada una), no de la memoria ni de la spec original.
 */

return [

    // ─────────────────────────────────────────────────────────────────────
    [
        'clave' => 'fundacion',
        'titulo' => 'Fundación: escuelas, personas, roles y permisos',
        'resumen' => 'La capa sobre la que se apoya todo lo demás: cómo se separan las escuelas entre sí, quién es cada quien dentro de una, y qué puede hacer.',

        'no_tecnico' => [
            [
                'subtitulo' => 'Cada escuela, por separado',
                'texto' => "Acadion atiende a muchas escuelas a la vez, y los datos de una no conviven con los de otra: cada escuela tiene su propia base de datos, con sus alumnos, sus calificaciones y su dinero. No es una separación por filtro —una columna que dice a quién pertenece cada renglón— sino física.\n"
                    ."La consecuencia práctica es que una consulta mal escrita no puede enseñarle a una escuela los datos de otra, porque esos datos no están ahí. Y una escuela se puede respaldar, mudar o dar de baja sin tocar a las demás.\n"
                    .'Aparte existe un registro central, común a todas: la lista de escuelas y los catálogos que no son de nadie —países, entidades federativas, sexos—. Ahí no hay datos de alumnos.',
            ],
            [
                'subtitulo' => 'El sistema conoce PERSONAS, no alumnos',
                'texto' => "La ficha básica es la de una persona: su nombre, su CURP, sus datos de contacto. Sobre esa persona se cuelgan los papeles que va teniendo: aspirante, alumna de una carrera y también de otra, docente, madre de familia, empleada.\n"
                    ."Esto importa porque en una escuela real la misma persona hace varias cosas. Una docente puede estar estudiando una maestría en la misma institución, y su hijo puede ser alumno. Con una ficha por papel habría tres versiones de la misma persona y tres domicilios que se contradicen; con una sola, corregir su teléfono lo corrige en todas partes.\n"
                    .'También significa que el aspirante tiene cuenta desde el primer día, antes de existir como alumno.',
            ],
            [
                'subtitulo' => 'La matrícula es el alumno, no la persona',
                'texto' => "Quien estudia dos carreras tiene dos matrículas, y cada una lleva su propia historia: su avance, sus materias, sus adeudos, su situación. Puede estar al corriente en una y dada de baja en la otra.\n"
                    .'Por eso, cuando el sistema pregunta «¿de qué alumno?», normalmente pregunta por una matrícula. Corregir la identidad de la persona alcanza a las dos; darla de baja alcanza sólo a una.',
            ],
            [
                'subtitulo' => 'Roles: lo que se es, y lo que se hace',
                'texto' => "Hay dos niveles. Arriba están las seis **facetas**, que dicen qué se ES dentro de la escuela: administrativo, docente, alumno, aspirante, tutor educativo y padre de familia. Debajo cuelgan los **roles funcionales**, que dicen qué se HACE: encargada de admisiones, director de campus, auxiliar de control escolar, cajera. Un rol funcional hereda todo lo de su faceta y añade lo suyo.\n"
                    ."La escuela crea sus propios roles desde la pantalla y decide qué lleva cada uno. Los roles de ejemplo se pueden borrar; las seis facetas no, porque hay código que las conoce por nombre, pero sus permisos sí se editan.\n"
                    .'Quien tiene varios papeles conmuta entre ellos desde su propio menú, y la pantalla cambia entera: la misma persona ve el sistema como docente o como madre de familia según con qué rol esté trabajando.',
            ],
            [
                'subtitulo' => 'Permisos: llaves, no casillas decorativas',
                'texto' => "Un permiso es una llave concreta que el código consulta antes de dejar hacer algo. No se inventan desde la pantalla —los declara el sistema— pero sí se reparten: la escuela decide qué rol lleva cuáles.\n"
                    ."Cada permiso pertenece a una o más facetas, y un rol sólo puede recibir los de la suya. Un administrativo no puede concederse el permiso de «ver mis materias» del docente, porque ese permiso no significa nada sin la asignación de materias que lo acompaña.\n"
                    .'Y el permiso casi nunca decide solo: dice QUÉ se puede hacer, mientras que otra cosa dice SOBRE QUIÉN. El docente tiene permiso de capturar calificaciones; sobre qué materias, lo dice la tabla de asignaciones. La cajera tiene permiso de registrar pagos; sobre qué campus, lo dice su rol.',
            ],
            [
                'subtitulo' => 'Cada quien entra a lo suyo',
                'texto' => "El menú lateral no es una lista fija: se arma con lo que esa persona puede abrir, según su faceta, sus permisos y qué módulos tenga encendidos la escuela. Además se puede ajustar por rol, escondiendo entradas que sobran para ese oficio.\n"
                    .'El panel de inicio funciona igual. No hay una pantalla de inicio por rol escrita a mano: hay tarjetas que declaran qué permiso exigen, y cada quien ve las que le tocan. Un rol nuevo, armado desde la pantalla de roles, obtiene su panel sin que nadie programe nada.',
            ],
        ],

        'operacion' => [
            [
                'flujo' => 'Crear un rol nuevo y repartirle permisos',
                'quien' => 'Dirección o sistemas, con «gestionar-roles»',
                'pasos' => [
                    'Entrar a Plataforma → Roles y permisos.',
                    'Crear el rol y elegir de qué faceta cuelga: eso decide qué permisos se le pueden dar y qué secciones verá.',
                    'Palomear los permisos, agrupados por dominio. Cada uno explica qué concede.',
                    'Opcional: en Plataforma → Menú, esconder para ese rol las entradas que no le sirven.',
                    'Opcional: en Plataforma → Panel por rol, ajustar qué tarjetas ve al entrar.',
                    'Asignar el rol a las personas desde Plataforma → Usuarios.',
                ],
            ],
            [
                'flujo' => 'Dar de alta a alguien del personal',
                'quien' => 'Con «gestionar-usuarios»',
                'pasos' => [
                    'Plataforma → Usuarios → alta.',
                    'Capturar la CURP: si esa persona ya existe en la escuela, se reutiliza su ficha en vez de duplicarla.',
                    'Asignar uno o más roles. Si el rol debe alcanzar sólo a un campus, indicarlo al asignarlo.',
                    'El sistema genera la contraseña y, si se pide, envía el correo con las credenciales.',
                    'Las cuentas no se eliminan: se retiran los roles o se restablece el acceso.',
                ],
            ],
            [
                'flujo' => 'Ver el sistema como lo ve otra persona',
                'quien' => 'Con «suplantar-usuarios»',
                'pasos' => [
                    'Desde la ficha del usuario, elegir «ver como».',
                    'La sesión pasa a mostrar exactamente lo que esa persona ve, sin más permisos de los suyos.',
                    'Queda registrado en la auditoría quién suplantó a quién y cuándo.',
                    'Se sale con «Volver a mi cuenta». No se puede encadenar una suplantación dentro de otra.',
                ],
            ],
            [
                'flujo' => 'Apagar una sección que la escuela no usa',
                'quien' => 'Con «ver-configuracion»',
                'pasos' => [
                    'Plataforma → Configuración → Secciones activas.',
                    'Apagar el módulo. Su sección desaparece del menú de todos y sus direcciones dejan de responder.',
                    'Encenderlo lo devuelve tal como estaba: no se borra nada al apagar.',
                ],
            ],
        ],

        'tecnico' => [
            [
                'subtitulo' => 'Multi-tenancy con base por escuela',
                'texto' => "Se usa `stancl/tenancy` v3 en modo multi-database. Cada tenant tiene su propia base; la central guarda el registro de escuelas y los catálogos universales. La escuela se resuelve por DOMINIO, y `PreventAccessFromCentralDomains` impide alcanzar rutas de tenant desde el dominio central.\n"
                    ."Los modelos de la base central llevan el trait `CentralConnection` y viven en `App\\Models\\Landlord`. Desde el tenant se consultan por el modelo, nunca con `DB::table(...)`: la tabla no existe en esa conexión.\n"
                    .'No hay llaves foráneas cruzadas de tenant a central. Las columnas que apuntan a un catálogo central son enteros sin constraint; la relación de Eloquent sí resuelve, porque el modelo del otro lado declara su conexión.',
            ],
            [
                'subtitulo' => 'Autorización',
                'texto' => "Se usa el `can:` de Laravel, no el `permission:` de Spatie. La razón es que los roles cuelgan de la PERSONA y no del usuario, y hay un rol ACTIVO: un `Gate::before` resuelve cada comprobación contra los permisos efectivos de ese rol activo, incluidos los heredados de su faceta.\n"
                    ."Cuando dos oficios entran por la misma puerta, la puerta se declara aparte con `Gate::define` en vez de pedirle a la escuela que adivine una dependencia entre permisos. Son puertas derivadas: `entrar-promocion`, `subir-material`, `usar-rubricas`, `dirigir-a-alumnos`, `gestionar-disciplina`, `ver-servicios-del-alumno`, `ver-biblioteca` y `ver-cuentas-bancarias`.\n"
                    .'El alcance por campus vive en `persona_rol.campus_id`. `Usuario::campusVisibles()` devuelve null con alcance global y un arreglo cuando está acotado; null no es lo mismo que arreglo vacío. Al guardar, lo que el usuario no alcanza se preserva.',
            ],
            [
                'subtitulo' => 'El catálogo de permisos',
                'texto' => "`App\\Support\\CatalogoPermisos` declara cada permiso con su dominio, su etiqueta, su descripción y las facetas a las que pertenece. Es la fuente: la pantalla de roles se dibuja de ahí y el seeder siembra de ahí.\n"
                    .'El constructor de la clase valida invariantes al arrancar, y esa validación ya evitó dos defectos reales: un permiso exigido y nunca declarado falla CERRADO y en silencio, y un permiso declarado que nadie comprueba es una casilla que se palomea creyendo que concede algo.',
            ],
            [
                'subtitulo' => 'Menú y panel',
                'texto' => "El menú se declara en `resources/js/menu/catalogo.ts` y se filtra en `construir.ts` por faceta, permiso y módulo. Una entrada puede declarar un permiso alternativo (`o:`) para las puertas derivadas y uno exigido además (`y:`) cuando su grupo de rutas lleva un permiso de sección encima.\n"
                    .'El panel es un REGISTRO de tarjetas (`App\\Panel\\TarjetaPanel` y `RegistroTarjetas`), no una serie de ramas por rol. Cada tarjeta declara el permiso que exige, si aplica a esa persona y de qué módulo depende; el controlador no conoce ninguna tarjeta concreta.',
            ],
        ],

        'tablas' => [
            ['nombre' => 'personas', 'para_que' => 'La identidad: nombre, CURP, contacto, foto. Todo lo demás cuelga de aquí.'],
            ['nombre' => 'usuarios', 'para_que' => 'La cuenta de acceso de una persona. No existe una tabla `users`.'],
            ['nombre' => 'rols', 'para_que' => 'Roles de Spatie extendidos con nombre, tiempo de sesión y rol padre. Sin padre = faceta.'],
            ['nombre' => 'persona_rol', 'para_que' => 'Qué roles tiene cada persona, si están activos y a qué campus la acotan.'],
            ['nombre' => 'matricula_oferta', 'para_que' => 'El alumno: una persona en un programa concreto, con su matrícula y su situación.'],
            ['nombre' => 'menus_rol', 'para_que' => 'Qué entradas del menú se esconden para un rol y en qué orden se muestran.'],
            ['nombre' => 'tarjetas_rol', 'para_que' => 'Qué tarjetas del panel ve cada rol.'],
            ['nombre' => 'modulos / modulos_activos', 'para_que' => 'El catálogo de secciones y cuáles tiene encendidas la escuela.'],
            ['nombre' => 'configuraciones', 'para_que' => 'Las reglas de operación que la escuela ajusta desde pantalla.'],
            ['nombre' => 'auditoria', 'para_que' => 'Rastro de quién hizo qué, incluidas las suplantaciones.'],
            ['nombre' => 'bitacora_accesos', 'para_que' => 'Entradas al sistema y envíos de credenciales.'],
            ['nombre' => 'temas / tema_tokens', 'para_que' => 'Colores de la escuela, por fila y no en un blob de configuración.'],
        ],

        'pantallas' => [
            ['ruta' => '/panel', 'que_hace' => 'El inicio de cada quien, armado con las tarjetas que su rol alcanza.', 'permiso' => 'sesión'],
            ['ruta' => '/plataforma/roles', 'que_hace' => 'Crear roles y repartir permisos.', 'permiso' => 'gestionar-roles'],
            ['ruta' => '/plataforma/menu', 'que_hace' => 'Qué ve cada rol en la barra lateral.', 'permiso' => 'gestionar-roles'],
            ['ruta' => '/plataforma/tarjetas', 'que_hace' => 'Qué tarjetas del panel ve cada rol.', 'permiso' => 'gestionar-roles'],
            ['ruta' => '/plataforma/usuarios', 'que_hace' => 'Alta de cuentas, asignación de roles y restablecimiento de contraseña.', 'permiso' => 'gestionar-usuarios'],
            ['ruta' => '/plataforma/modulos', 'que_hace' => 'Encender y apagar secciones completas.', 'permiso' => 'ver-configuracion'],
            ['ruta' => '/plataforma/configuracion', 'que_hace' => 'Las reglas de operación de la escuela.', 'permiso' => 'ver-configuracion'],
            ['ruta' => '/plataforma/accesos', 'que_hace' => 'Quién ha entrado al sistema.', 'permiso' => 'ver-accesos'],
            ['ruta' => '/mi-perfil', 'que_hace' => 'Los datos y preferencias de quien entra.', 'permiso' => 'sesión'],
            ['ruta' => '/mis-datos', 'que_hace' => 'Los datos personales que cada quien mantiene de sí mismo.', 'permiso' => 'sesión'],
        ],

        'reglas' => [
            ['regla' => 'El login es de personas, no de alumnos.', 'porque' => 'Un aspirante necesita sesión antes de tener matrícula, y la misma persona puede ser docente y madre de familia.'],
            ['regla' => 'El alumno es la matrícula, no la persona.', 'porque' => 'Una persona puede cursar dos programas con avances y situaciones distintas; corregir su identidad alcanza a los dos, darla de baja sólo a uno.'],
            ['regla' => 'Sin llaves foráneas cruzadas de tenant a central.', 'porque' => 'Son bases distintas: la restricción no se puede declarar. Las relaciones se resuelven por Eloquent.'],
            ['regla' => 'Autorización con el `can:` de Laravel, nunca con el `permission:` de Spatie.', 'porque' => 'Los roles cuelgan de la persona y hay un rol activo; el `Gate::before` resuelve contra los permisos efectivos de ese rol.'],
            ['regla' => 'Un permiso pertenece a una faceta y sólo se concede a roles de ella.', 'porque' => 'Si un administrativo pudiera darse «ver mis materias», el conmutador de rol dejaría de tener sentido y el alcance por asignación quedaría colgando.'],
            ['regla' => 'El permiso dice QUÉ; la asignación dice SOBRE QUIÉN.', 'porque' => 'El rol docente tiene «asentar-acta», así que el permiso solo no separa al docente de la materia del personal de control escolar.'],
            ['regla' => 'Cuando dos oficios entran por la misma puerta, se declara una puerta derivada.', 'porque' => 'Un middleware con el permiso de uno rebota al otro, y una casilla aparte sería una dependencia que la escuela no tiene por qué adivinar.'],
            ['regla' => 'Las seis facetas están protegidas: su clave no se toca.', 'porque' => 'Hay código que las conoce por nombre. Sus permisos sí se editan.'],
            ['regla' => 'No puedes quitarle «gestionar-roles» a tu propio rol activo.', 'porque' => 'Sería encerrarse fuera de la única pantalla que puede deshacerlo.'],
            ['regla' => 'Un rol nuevo obtiene su panel solo.', 'porque' => 'Las tarjetas declaran el permiso que exigen; no hay ramas por rol que alguien tenga que ampliar.'],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    [
        'clave' => 'academico',
        'titulo' => 'Estructura académica y formularios',
        'resumen' => 'El mapa de lo que la escuela ofrece —campus, carreras, planes, materias— y la herramienta con la que arma los cuestionarios que necesita.',

        'no_tecnico' => [
            [
                'subtitulo' => 'De la institución a la materia',
                'texto' => "La estructura baja por niveles: la institución tiene campus; los campus ofrecen carreras; cada carrera tiene uno o más planes de estudio; cada plan ordena sus materias por periodo y les pone créditos.\n"
                    ."Encima de eso está la OFERTA: qué carrera, con qué plan, se imparte en qué campus. Es lo que un aspirante elige y a lo que una matrícula queda atada.\n"
                    .'Se puede tener el plan 2019 y el plan 2024 conviviendo: quien entró antes termina con el suyo.',
            ],
            [
                'subtitulo' => 'Seriación: qué va antes de qué',
                'texto' => 'El plan puede declarar que una materia exige otra aprobada. El sistema lo revisa al inscribir y lo impide, explicando qué falta. No es un aviso que se pueda ignorar por descuido: es parte de la validación de inscripción.',
            ],
            [
                'subtitulo' => 'Cómo se califica cada materia',
                'texto' => "Cada materia del plan lleva un esquema de evaluación: de qué se compone la calificación y cuánto pesa cada parte —exámenes, actividades, participación—. Para no capturarlo materia por materia hay plantillas reutilizables que se aplican en lote y reparten los porcentajes.\n"
                    .'Lo importante: el esquema se MATERIALIZA en cada materia. Cambiar la plantilla después no altera lo que ya se está calificando, porque las calificaciones apuntan al esquema real de esa materia y no a la plantilla.',
            ],
            [
                'subtitulo' => 'Formularios que arma la escuela',
                'texto' => "Cuando hace falta preguntar algo que el sistema no trae —una encuesta de ingreso, una ficha médica, un consentimiento— la escuela arma el cuestionario desde una pantalla: campos, tipos, opciones y preguntas que sólo aparecen si se contestó algo antes.\n"
                    .'Un formulario se puede versionar: la versión 2 no cambia lo que ya contestó quien contestó la 1. Y en cuanto tiene una respuesta capturada se congela, porque cambiarle una pregunta dejaría las respuestas viejas contestando otra cosa.',
            ],
        ],

        'operacion' => [
            [
                'flujo' => 'Dar de alta una carrera y dejarla lista para inscribir',
                'quien' => 'Control escolar, con «editar-catalogo-academico»',
                'pasos' => [
                    'Académico → Carreras: alta de la carrera con su nivel de estudios y su identificador oficial.',
                    'Académico → Planes de estudio: crear el plan, con su tipo de periodo (semestre, cuatrimestre, módulo) y sus créditos.',
                    'Dentro del plan, cargar la malla: qué materia va en qué periodo y con cuántos créditos.',
                    'Si aplica, declarar la seriación entre materias.',
                    'Aplicar una plantilla de evaluación al plan, o capturar el esquema materia por materia.',
                    'Académico → Oferta: publicar la combinación carrera + plan + campus. Sin oferta no hay dónde inscribir.',
                ],
            ],
            [
                'flujo' => 'Armar un formulario y publicarlo',
                'quien' => 'Con «gestionar-formularios»',
                'pasos' => [
                    'Formularios → nuevo: nombre y a quién va dirigido.',
                    'Agregar campos, eligiendo tipo y opciones. Un campo puede declararse condicional: aparece sólo si otro se contestó de cierta forma.',
                    'Asignarlo al ámbito que corresponda (por rol, por oferta) y marcar si es obligatorio.',
                    'En cuanto alguien lo conteste, el formulario queda congelado. Para cambiarlo se crea una versión nueva.',
                ],
            ],
            [
                'flujo' => 'Apagar un catálogo que ya no se usa',
                'quien' => 'Con «editar-catalogo-academico»',
                'pasos' => [
                    'Académico → Catálogos, columna de acciones.',
                    'Sólo se puede apagar lo que nadie usa: el sistema revisa todas las tablas que lo referencian antes de dejar.',
                    'Apagado, deja de aparecer en los desplegables, pero lo que ya lo tenía asignado lo conserva y se sigue leyendo.',
                ],
            ],
        ],

        'tecnico' => [
            [
                'subtitulo' => 'La oferta y su unicidad',
                'texto' => "La tabla es `oferta`, en SINGULAR. La combinación que no se duplica es (carrera, plan, campus); el alta reparte por campus y la modalidad es un atributo opcional que se aplica a todas.\n"
                    .'El TURNO no es de la oferta sino del GRUPO: no distingue una oferta de otra, así que `oferta.turno_id` se retiró.',
            ],
            [
                'subtitulo' => 'Esquema de evaluación materializado',
                'texto' => "`plantillas_evaluacion` + `plantilla_componentes` son el molde; `esquema_evaluacion` es lo que rige de verdad en cada materia del plan, y `calificaciones_componente` apunta ahí.\n"
                    .'`AplicadorPlantillaEvaluacion` se niega a re-aplicar sobre una materia con calificaciones capturadas o con actividades del LMS colgando de sus componentes, y devuelve el motivo concreto de cada bloqueo — porque la salida es distinta en cada caso: vaciar celdas de captura, o mover las actividades.',
            ],
            [
                'subtitulo' => 'Nivel de estudios: el del tenant',
                'texto' => '`niveles_estudio` dejó de ser universal y vive en el tenant (`App\\Models\\Academico\\NivelEstudio`). El modelo de landlord se conservó sólo como semilla y sigue contestando, que es lo que lo hace peligroso: devuelve la lista sembrada mientras las carreras de la escuela apuntan a las suyas. Un test prohíbe el import viejo.',
            ],
            [
                'subtitulo' => 'Formularios sin JSON',
                'texto' => 'El modelo es relacional: `formularios`, `campos_formulario`, `opciones_campo` y las respuestas en `respuestas_campo`. Al versionar, los campos condicionales se re-atan al padre de SU versión, no al de la anterior.',
            ],
        ],

        'tablas' => [
            ['nombre' => 'instituciones / campus', 'para_que' => 'La escuela y sus planteles, con coordenadas opcionales.'],
            ['nombre' => 'carreras', 'para_que' => 'Los programas que ofrece, con su identificador oficial.'],
            ['nombre' => 'planes_estudio', 'para_que' => 'Cada versión de un programa, con su tipo de periodo y sus créditos.'],
            ['nombre' => 'asignaturas', 'para_que' => 'El catálogo de materias de la escuela.'],
            ['nombre' => 'plan_materias', 'para_que' => 'La malla: qué materia va en qué periodo de qué plan.'],
            ['nombre' => 'seriacion', 'para_que' => 'Qué materia exige cuál aprobada antes.'],
            ['nombre' => 'esquema_evaluacion', 'para_que' => 'De qué se compone la calificación de una materia y cuánto pesa cada parte.'],
            ['nombre' => 'plantillas_evaluacion', 'para_que' => 'Moldes reutilizables que se aplican en lote a un plan.'],
            ['nombre' => 'oferta', 'para_que' => 'Carrera + plan + campus: lo que se puede cursar.'],
            ['nombre' => 'niveles_estudio', 'para_que' => 'Los niveles que administra la escuela. Vive en el tenant, no en la central.'],
            ['nombre' => 'formularios / campos_formulario', 'para_que' => 'Los cuestionarios que arma la escuela y sus preguntas.'],
            ['nombre' => 'respuestas_campo', 'para_que' => 'Lo contestado, campo por campo.'],
        ],

        'pantallas' => [
            ['ruta' => '/academico/instituciones', 'que_hace' => 'Datos de la institución y su logo.', 'permiso' => 'ver-catalogo-academico'],
            ['ruta' => '/academico/campus', 'que_hace' => 'Planteles, con su identificador oficial y sus coordenadas.', 'permiso' => 'ver-catalogo-academico'],
            ['ruta' => '/academico/carreras', 'que_hace' => 'Programas educativos.', 'permiso' => 'ver-catalogo-academico'],
            ['ruta' => '/academico/planes', 'que_hace' => 'Planes de estudio, malla, seriación y esquema de evaluación.', 'permiso' => 'ver-catalogo-academico'],
            ['ruta' => '/academico/asignaturas', 'que_hace' => 'Catálogo de materias.', 'permiso' => 'ver-catalogo-academico'],
            ['ruta' => '/academico/ofertas', 'que_hace' => 'Qué se imparte, dónde.', 'permiso' => 'ver-catalogo-academico'],
            ['ruta' => '/academico/plantillas', 'que_hace' => 'Plantillas de evaluación reutilizables.', 'permiso' => 'ver-catalogo-academico'],
            ['ruta' => '/academico/catalogos', 'que_hace' => 'Catálogos académicos, con su interruptor de activo.', 'permiso' => 'ver-catalogo-academico'],
            ['ruta' => '/formularios', 'que_hace' => 'Constructor de formularios, con versionado.', 'permiso' => 'gestionar-formularios'],
        ],

        'reglas' => [
            ['regla' => 'La tabla es `oferta`, en singular, y `planes_estudio` no se llama como su modelo.', 'porque' => 'El nombre de una tabla se pregunta, no se adivina; consultar por Eloquent evita el problema entero.'],
            ['regla' => 'El turno es del grupo, no de la oferta.', 'porque' => 'No distingue una oferta de otra. La combinación que no se duplica es carrera + plan + campus.'],
            ['regla' => 'Las plantillas de evaluación se materializan, no se leen en vivo.', 'porque' => 'Las calificaciones apuntan al esquema de la materia; si se leyera la plantilla, editarla cambiaría notas ya puestas.'],
            ['regla' => 'Una materia con calificaciones capturadas no se re-aplica.', 'porque' => 'Reemplazar su esquema dejaría los números colgando y desengancharía en silencio las actividades del LMS que ponderan a esos componentes.'],
            ['regla' => '`niveles_estudio` es del tenant; el modelo de landlord sólo es semilla.', 'porque' => 'El viejo sigue contestando con la lista sembrada mientras las carreras apuntan a las suyas: falla sin error, devolviendo otra cosa.'],
            ['regla' => 'Un formulario se congela en cuanto tiene una respuesta.', 'porque' => 'Cambiar una pregunta dejaría las respuestas anteriores contestando algo distinto de lo que se les preguntó.'],
            ['regla' => 'Un catálogo sólo se apaga si nadie lo usa, y el filtro va escrito en cada desplegable.', 'porque' => 'Un filtro global también afectaría a las lecturas por id, y entonces apagar un nivel dejaría el historial de una alumna sin su nivel, sin error.'],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    [
        'clave' => 'admisiones',
        'titulo' => 'Admisiones, promoción y portal del aspirante',
        'resumen' => 'Desde que alguien pregunta por la escuela hasta que se convierte en alumno con matrícula: el embudo, los asesores, el expediente y el portal donde el interesado hace su parte.',

        'no_tecnico' => [
            [
                'subtitulo' => 'El interesado entra al embudo',
                'texto' => "Todo prospecto ocupa una etapa del embudo: contactado, en seguimiento, documentación, y así hasta inscribirse. La escuela define sus propias etapas.\n"
                    .'Da igual por dónde entre —una llamada, una visita, el formulario de la página web—: nace en la primera etapa y con un asesor asignado. Antes había una puerta que no lo hacía y los prospectos capturados por personal quedaban invisibles para el CRM; hoy entran todos igual.',
            ],
            [
                'subtitulo' => 'Los asesores y cómo se reparten',
                'texto' => "La escuela registra a sus asesores de promoción y decide cómo se reparte lo que llega: a mano, se lo queda quien lo registra, o por turno.\n"
                    .'El turno se calcula por CARGA —cuántos prospectos abiertos tiene cada quien— y no con un contador guardado. Un contador se desincroniza cuando alguien se da de baja o cuando entran dos altas a la vez, y a partir de ahí reparte torcido para siempre sin que nadie lo note. Se cuentan los abiertos y no los históricos, porque contar los históricos castigaría a quien más ha inscrito.',
            ],
            [
                'subtitulo' => 'La ficha del aspirante es el centro',
                'texto' => "Todo lo del prospecto ocurre en su ficha: sus datos, su expediente de documentos, sus formularios, sus cargos, su etapa, su asesor y su bitácora de contactos.\n"
                    ."La bitácora no sólo registra: AGENDA. Una llamada se agenda, y después se cierra con su desenlace o se cancela con su motivo. Son la misma cosa en dos momentos, por eso viven en un solo sitio y no en dos listas que habría que volver a mezclar.\n"
                    .'El catálogo de desenlaces distingue hablar con alguien de marcarle sin éxito. Sin esa distinción, «se le llamó seis veces» no dice si alguna vez lo atendieron.',
            ],
            [
                'subtitulo' => 'El interesado también trabaja',
                'texto' => "El aspirante entra a «Mi solicitud» y ve su avance sobre cuatro pasos: sus datos, sus documentos, sus formularios y su pago. Sube lo que le piden y consulta lo que debe.\n"
                    .'Ese avance NO es la etapa del embudo. El embudo lo mueve promoción con su criterio; el avance sólo informa. Y el cálculo es el mismo lo llene quien lo llene, así que la escuela ve exactamente lo que ve el aspirante.',
            ],
            [
                'subtitulo' => 'Convertirse en alumno',
                'texto' => "La matrícula nace al final, no al principio. Cuando el prospecto cumple lo que la escuela exige —que puede incluir tener sus documentos validados y su pago hecho, si así lo configuró— se le convierte en alumno y se genera su matrícula con la regla de formato que la escuela definió.\n"
                    .'Lo que el aspirante ya pagó no se pierde: sus cargos y pagos se religan a la matrícula nueva dentro de la misma operación.',
            ],
            [
                'subtitulo' => 'Prospectos que no prosperan',
                'texto' => "Un prospecto se descarta con FECHA y MOTIVO, ambos obligatorios. No basta con una etiqueta que diga «rechazado»: al revisar por qué se cayó un prospecto, lo que se pregunta es cuándo y por qué.\n"
                    .'Marcar un desenlace de contacto que cierra el embudo lo descarta solo, usando ese resultado como motivo. Y a quien ya está inscrito no se le descarta por ninguna de las dos vías.',
            ],
        ],

        'operacion' => [
            [
                'flujo' => 'Capturar un prospecto que llamó por teléfono',
                'quien' => 'Promoción, con «crear-aspirantes»',
                'pasos' => [
                    'Aspirantes → nuevo.',
                    'Capturar sus datos y el CAMPUS, que es obligatorio: sin campus no hay entre quiénes repartir.',
                    'Elegir el origen (cómo se enteró) y la oferta que le interesa.',
                    'Al guardar, el sistema lo pone en la primera etapa del embudo y le asigna asesor según la regla configurada.',
                ],
            ],
            [
                'flujo' => 'Dar seguimiento y agendar el siguiente contacto',
                'quien' => 'El asesor, con «ver-mis-prospectos»; promoción con «gestionar-promocion» ve a todos',
                'pasos' => [
                    'Abrir la ficha del aspirante.',
                    'Registrar el contacto hecho, con su tipo y su desenlace.',
                    'Agendar el siguiente: queda en la agenda del panel, y lo vencido se marca en rojo.',
                    'Una tarea agendada se cierra con desenlace, se cancela con motivo o se reprograma. No se cierra dos veces ni se borra.',
                    'Si el desenlace cierra el embudo, el prospecto queda descartado con ese motivo.',
                ],
            ],
            [
                'flujo' => 'Publicar un formulario en la página de la escuela',
                'quien' => 'Con «gestionar-promocion»',
                'pasos' => [
                    'Promoción → Formularios web.',
                    'Elegir el cuestionario y el modo: captación (sólo deja el prospecto) o inscripción (además le crea su cuenta).',
                    'Copiar el código del iframe y pegarlo en la página de la escuela.',
                    'Lo que llegue entra al embudo con su origen marcado y su asesor asignado.',
                ],
            ],
            [
                'flujo' => 'Convertir un aspirante en alumno',
                'quien' => 'Con «convertir-aspirante»',
                'pasos' => [
                    'Desde la ficha, pedir la conversión.',
                    'El sistema revisa los impedimentos: expediente y pago si la escuela los exige, y que no exista ya una matrícula para esa misma oferta.',
                    'Se genera la matrícula con la regla de formato configurada.',
                    'Sus cargos y pagos como aspirante quedan religados a la matrícula, en la misma transacción.',
                    'Si tenía asesor con comisión, la comisión se devenga en ese momento y su monto queda congelado.',
                ],
            ],
        ],

        'tecnico' => [
            [
                'subtitulo' => 'La conversión es una transacción',
                'texto' => "`ConvertidorAspirante` comprueba impedimentos, crea la matrícula con `GeneradorMatricula`, religa lo financiero con `ReligadorFinanzas` y devenga comisiones con `DevengadorComisiones`, todo dentro de la misma transacción.\n"
                    .'Los impedimentos configurables (`aspirante.exige_documentos_para_convertir`, `aspirante.exige_pago_para_convertir`) le preguntan a `ProgresoSolicitud` en vez de recalcular: es lo que ya se le enseña al aspirante en su portal, y duplicar el cálculo dejaría dos verdades.',
            ],
            [
                'subtitulo' => 'Los contadores de matrícula',
                'texto' => '`contadores_matricula` NO tiene id autoincremental: eso rompía el incremento atómico y producía duplicados. La regla de formato es configurable y el ámbito del consecutivo también.',
            ],
            [
                'subtitulo' => 'El desenlace se deriva',
                'texto' => "No hay catálogo de «situación del aspirante». INSCRITO se deriva de tener `matricula_oferta` para SU oferta de interés —por la oferta y no por «tiene alguna matrícula», porque quien ya estudia una carrera y se postula a otra sigue siendo prospecto abierto para ésa—. RECHAZADO es `descartado_en` + `motivo_descarte`.\n"
                    .'`Aspirante::matriculaDeSuOferta()` sólo funciona CORRELACIONADA (`whereHas`, `withExists`); precargarla con `with()` revienta porque la relación se consulta sola y la tabla del padre no está en el FROM.',
            ],
            [
                'subtitulo' => 'El formulario público escribe datos de un desconocido',
                'texto' => "Va en Blade y no en Inertia: se carga dentro del sitio de la escuela y no debe arrastrar la SPA administrativa. El token es UUID y no consecutivo.\n"
                    .'Salvaguardas: nunca sobreescribe una persona existente, deduplica sólo por CURP, no repite prospecto, no toca credenciales de quien ya tenía cuenta, y lleva honeypot y `throttle`.',
            ],
        ],

        'tablas' => [
            ['nombre' => 'aspirantes', 'para_que' => 'El prospecto: su oferta de interés, su etapa, su origen, su campus y su desenlace.'],
            ['nombre' => 'etapas_crm', 'para_que' => 'Las etapas del embudo que define la escuela.'],
            ['nombre' => 'origenes_aspirante', 'para_que' => 'Cómo se enteró, con bandera de autogestivo.'],
            ['nombre' => 'asesores / situaciones_asesor', 'para_que' => 'Quién promueve y en qué situación está. Un asesor se apaga, no se borra.'],
            ['nombre' => 'aspirante_asesor', 'para_que' => 'Qué asesor lleva a qué prospecto. Es el alcance, no el permiso.'],
            ['nombre' => 'seguimientos_aspirante', 'para_que' => 'La bitácora que además agenda: agendado, realizado o cancelado.'],
            ['nombre' => 'resultados_seguimiento', 'para_que' => 'Desenlaces, con banderas de «cuenta como contacto» y «cierra el embudo».'],
            ['nombre' => 'comisiones / reglas_comision', 'para_que' => 'Lo que gana el asesor cuando su prospecto se inscribe. El monto se congela al devengarse.'],
            ['nombre' => 'expedientes / expediente_documentos', 'para_que' => 'Los papeles entregados y su validación.'],
            ['nombre' => 'documentos_requeridos', 'para_que' => 'Qué papeles se le piden a cada clase de persona (ámbito).'],
            ['nombre' => 'reglas_matricula / contadores_matricula', 'para_que' => 'Con qué se arma la matrícula y por dónde va el consecutivo.'],
            ['nombre' => 'formularios_publicos', 'para_que' => 'La publicación embebible: su token, su modo y su formulario.'],
        ],

        'pantallas' => [
            ['ruta' => '/aspirantes', 'que_hace' => 'Listado de prospectos, con filtros y vista de lista o cuadrícula.', 'permiso' => 'ver-aspirantes'],
            ['ruta' => '/promocion', 'que_hace' => 'El embudo por etapas.', 'permiso' => 'entrar-promocion'],
            ['ruta' => '/promocion/asesores', 'que_hace' => 'Padrón de asesores y su campus.', 'permiso' => 'gestionar-promocion'],
            ['ruta' => '/promocion/comisiones', 'que_hace' => 'Lo devengado por cada asesor.', 'permiso' => 'entrar-promocion'],
            ['ruta' => '/promocion/publicaciones', 'que_hace' => 'Formularios web embebibles.', 'permiso' => 'gestionar-promocion'],
            ['ruta' => '/documentos', 'que_hace' => 'Qué papeles se piden, por ámbito.', 'permiso' => 'gestionar-documentos'],
            ['ruta' => '/admisiones/reglas-matricula', 'que_hace' => 'Formato de la matrícula y sus consecutivos.', 'permiso' => 'configurar-matriculas'],
            ['ruta' => '/mi-solicitud', 'que_hace' => 'El portal del interesado: sus datos, documentos, formularios y pagos.', 'permiso' => 'llenar-mi-solicitud'],
            ['ruta' => '/p/{token}', 'que_hace' => 'El formulario público embebible, sin sesión.', 'permiso' => 'público'],
        ],

        'reglas' => [
            ['regla' => 'La matrícula nace al final, no al principio.', 'porque' => 'Un aspirante no tiene matrícula; se genera al convertirlo, con la regla configurable de la escuela.'],
            ['regla' => 'Un aspirante dado de alta a mano nace en la primera etapa.', 'porque' => 'Antes sólo lo hacía el formulario público, y el prospecto capturado por personal quedaba invisible para el CRM.'],
            ['regla' => 'El turno del reparto va por carga, no por un contador guardado.', 'porque' => 'Un contador se desincroniza al darse alguien de baja o con dos altas a la vez, y reparte torcido para siempre sin que nadie lo note.'],
            ['regla' => 'El campus del aspirante es obligatorio al capturarlo.', 'porque' => 'Sin campus no hay entre quiénes repartir. La regla vive en el FormRequest para decir «elige el campus» en vez de reventar en la base.'],
            ['regla' => 'Agendar y registrar son la misma tabla.', 'porque' => 'Una llamada agendada y una hecha son la misma cosa en dos momentos; separarlas obligaría a volver a mezclarlas en la pantalla.'],
            ['regla' => 'El descarte exige fecha y motivo.', 'porque' => 'Una fila de catálogo que diga «rechazado» no dice ni cuándo ni por qué, que es justo lo que se pregunta al revisar.'],
            ['regla' => '«Inscrito» se deriva de tener matrícula PARA SU OFERTA.', 'porque' => 'Quien ya estudia una carrera y se postula a otra sigue siendo un prospecto abierto para ésa.'],
            ['regla' => 'El avance de la solicitud no es la etapa del embudo.', 'porque' => 'El embudo lo mueve promoción con su criterio; el avance sólo informa, y son cuatro pasos fijos por decisión del cliente.'],
            ['regla' => 'El formulario público nunca sobreescribe una persona existente.', 'porque' => 'Los datos los escribe un desconocido. Deduplica sólo por CURP y no toca credenciales de quien ya tenía cuenta.'],
            ['regla' => '`contadores_matricula` no lleva id autoincremental.', 'porque' => 'Rompía el incremento atómico y producía matrículas duplicadas.'],
        ],
    ],
];
