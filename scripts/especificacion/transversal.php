<?php

/** Contenido de la especificación — lo que atraviesa a todos los módulos. */

return [

    'arquitectura' => [
        [
            'subtitulo' => 'Una base de datos por escuela',
            'texto' => "Se usa `stancl/tenancy` v3 en modo multi-database. La escuela se resuelve por DOMINIO: cada una entra por el suyo, y `PreventAccessFromCentralDomains` impide alcanzar una ruta de tenant desde el dominio central.\n"
                ."Aparte está la base CENTRAL, con el registro de escuelas, los catálogos universales (países, entidades federativas, sexos) y los créditos de emisión. Ahí no hay datos de alumnos.\n"
                .'La consecuencia de fondo: el aislamiento no depende de que cada consulta recuerde filtrar. Los datos de otra escuela no están en la conexión, así que una consulta mal escrita no puede alcanzarlos.',
        ],
        [
            'subtitulo' => 'Sin llaves foráneas cruzadas',
            'texto' => "Las columnas del tenant que apuntan a un catálogo central (`personas.sexo_id`, `programas_academicos.nivel_estudios_id`…) son enteros SIN constraint: son bases distintas y la restricción no se puede declarar.\n"
                ."Las relaciones de Eloquent sí resuelven, porque los modelos de landlord usan el trait `CentralConnection`. Desde el tenant se consultan por el modelo y nunca con `DB::table(...)`: la tabla no existe en esa conexión y la consulta revienta con «table doesn't exist».",
        ],
        [
            'subtitulo' => 'Modelos organizados por capa y módulo',
            'texto' => "`App\\Models\\Landlord\\` para la base central, y para el tenant: `Identidad`, `Academico`, `Admisiones`, `ControlEscolar`, `Asistencia`, `Finanzas`, `Lms`, `Emision`, `Nomina`, `Bolsa`, `Movilidad`, `Disciplina`, `Encuestas`, `Formularios`, `Captacion`, `Reportes`, `Plataforma`, `Correo`, `Facturacion`.\n"
                .'Son unas 265 tablas. Toda tabla de tenant lleva `$table->auditoria()` —el macro que agrega `created_by` y `updated_by` además de las marcas de tiempo— y su modelo el trait `TieneAuditoria`.',
        ],
        [
            'subtitulo' => 'Los seeders van separados',
            'texto' => "`LandlordDatabaseSeeder` se corre explícitamente contra la base central. `DatabaseSeeder` es el seeder RAÍZ del tenant y stancl lo ejecuta por escuela. No se mezclan.\n"
                .'Los catálogos configurables de cada escuela se siembran con valores razonables y la escuela los ajusta; lo que es catálogo OFICIAL —los de la SEP— se siembra con sus valores exactos y no se toca.',
        ],
        [
            'subtitulo' => 'El frontend',
            'texto' => "Inertia con Vue 3 y TypeScript, y Tailwind 4. Una pantalla es un controlador que devuelve `Inertia::render('Ruta/Del/Componente', [...props])`; el componente vive en `resources/js/Pages/` con esa misma ruta. No hay una capa de API entre los dos: los props del controlador son las props del componente.\n"
                .'El menú se declara en `resources/js/menu/catalogo.ts` y se filtra por faceta, permiso y módulo. El tema —los colores de la escuela— se guarda por FILA en `tema_tokens` y cae en cascada: tema de la escuela, tema del usuario, y ajuste individual.',
        ],
        [
            'subtitulo' => 'Agregar un módulo nuevo',
            'texto' => "El recorrido es: migración con `\$table->auditoria()` y modelo con `TieneAuditoria`; permisos en `App\\Support\\CatalogoPermisos` con su faceta y su descripción; rutas en `routes/tenant.php` bajo `can:` y, si el módulo se puede apagar, bajo `modulo:`; entrada en `resources/js/menu/catalogo.ts` con el mismo permiso que exige la ruta; pantalla en `resources/js/Pages/`; y una suite en `scripts/` que corra contra la base real con `DB::rollBack()`.\n"
                .'Dos redes lo vigilan: una comprueba que toda pantalla con permiso se alcance desde algún sitio, y otra que el menú no ofrezca nada que la ruta niegue.',
        ],
        [
            'subtitulo' => 'Documentos en PDF',
            'texto' => "`App\\Documentos\\DocumentoPdf` sobre mpdf, con membrete repetido en cada hoja, «Hoja X de Y» y marca de agua nativa. Lo usan el historial académico y este mismo documento.\n"
                .'Regla de maqueta: mpdf NO entiende `flex` ni `grid` —los dibuja como bloques apilados, sin avisar—. Lo que necesita columnas se hace con tablas y anchos en porcentaje.',
        ],
    ],

    'seguridad' => [
        [
            'subtitulo' => 'Permiso y alcance, siempre los dos',
            'texto' => "El permiso dice QUÉ puede hacer un rol. Otra cosa dice SOBRE QUIÉN: la asignación del docente a sus materias, el vínculo del tutor con sus tutorados, el del padre con sus hijos, el del asesor con sus prospectos, o el campus del rol.\n"
                .'Es la regla que más se repite en el sistema, y la que más veces ha evitado una fuga. Un permiso solo casi nunca alcanza a cerrar una puerta: el rol docente tiene «asentar-acta», así que ese permiso no distingue al docente de la materia del personal de control escolar.',
        ],
        [
            'subtitulo' => 'El `can:` de Laravel, no el de Spatie',
            'texto' => "Los roles cuelgan de la PERSONA, no del usuario, y hay un rol ACTIVO entre los suyos. Un `Gate::before` resuelve cada comprobación contra los permisos efectivos de ese rol activo, incluidos los que hereda de su faceta.\n"
                .'Usar el middleware `permission:` de Spatie miraría los roles del modelo autenticado y se saltaría esa resolución.',
        ],
        [
            'subtitulo' => 'Puertas derivadas',
            'texto' => "Cuando dos oficios entran por la misma pantalla, la puerta se declara aparte con `Gate::define` en vez de pedirle a la escuela que adivine una dependencia entre permisos. Un middleware con el permiso de uno rebotaría al otro.\n"
                .'Hoy son ocho: `entrar-captacion`, `subir-material`, `usar-rubricas`, `dirigir-a-alumnos`, `gestionar-disciplina`, `ver-servicios-del-alumno`, `ver-recursos-digitales` y `ver-cuentas-bancarias`. No son asignables ni están en el catálogo.',
        ],
        [
            'subtitulo' => 'Un permiso compartido no cierra una puerta administrativa',
            'texto' => "Cinco permisos pertenecen a más de una faceta. El caso que más importa es `ver-adeudos`: lo tienen administrativo, ALUMNO y PADRE DE FAMILIA, porque el alumno lo necesita para su estado de cuenta, y de él cuelga todo el módulo de finanzas.\n"
                ."Cada pantalla administrativa de finanzas añade encima el permiso de su oficio. Dos se habían quedado sin añadirlo, y con eso cualquier alumno con sesión alcanzaba la cola de comprobantes —con nombre, monto y referencia bancaria de otras familias— y el catálogo de cuentas con sus CLABE.\n"
                .'La lección se repite al agregar pantallas: si el único `can:` de una ruta administrativa es un permiso que también tiene el alumno, esa ruta está abierta.',
        ],
        [
            'subtitulo' => 'Alcance por campus',
            'texto' => "Vive en `persona_rol.campus_id`. `Usuario::campusVisibles()` devuelve `null` con alcance global y un arreglo cuando está acotado, y null NO es lo mismo que arreglo vacío.\n"
                .'Al guardar, lo que el usuario no alcanza se preserva: nunca se destruye lo que no se ve.',
        ],
        [
            'subtitulo' => 'Archivos privados',
            'texto' => "Nada que sea dato personal vive en `public/`: los documentos del expediente, las fotos, los CFDI, las grabaciones y los comprobantes están en el disco privado y los sirve una ruta del sistema.\n"
                ."Pero el disco privado no protege por sí solo: sólo mueve la pregunta a la ruta que sirve el archivo. Si esa ruta no comprueba quién llama, el archivo está abierto a cualquiera con sesión. Ya pasó una vez con las fotos de las personas, y por eso hoy cada uno de esos endpoints resuelve la pertenencia dentro del controlador.\n"
                .'Cuando se niega, se responde 404 y no 403: un 403 confirma que ese registro existe, y enumerando identificadores se puede levantar el padrón sin ver un solo archivo.',
        ],
        [
            'subtitulo' => 'Suplantación y auditoría',
            'texto' => "Quien tiene `suplantar-usuarios` puede ver el sistema como lo ve otra persona, sin escalar privilegios y sin encadenar una suplantación dentro de otra. Queda el rastro de quién suplantó a quién.\n"
                .'La auditoría general registra creaciones y cambios con el usuario responsable, gracias al macro `auditoria()` que llevan todas las tablas del tenant.',
        ],
        [
            'subtitulo' => 'Datos que salen del sistema',
            'texto' => "Las exportaciones neutralizan lo que Excel interpretaría como fórmula: medio reporte escolar es texto que escribió alguien de fuera.\n"
                ."Los reportes registran qué se PIDIÓ —reporte, filtros, columnas— y nunca lo que salió, para que la bitácora no sea una segunda puerta a los datos.\n"
                .'Y no existe el destinatario «una dirección de correo suelta» en los reportes programados: o recibe un enlace que exige sesión y no puede abrirlo, o recibe el adjunto, y entonces un padrón con CURP sale cada semana a una dirección que la escuela no controla.',
        ],
    ],

    'operacion_sistema' => [
        [
            'subtitulo' => 'Lo que hay que instalar',
            'texto' => "PHP 8.3, MySQL 8 con InnoDB, un servidor web, y el despachador de tareas programadas. El despachador NO es opcional: sin él no se emiten cargos, no se recalcula la cartera, no se envían reportes programados, no se recogen las grabaciones de Meet y no se procesa la cola.\n"
                .'Se instala con los archivos de `deploy/scheduler/`, por systemd (recomendado) o por cron. Corre con el usuario del servidor web y no con root: las tareas escriben archivos que después tiene que leer PHP-FPM.',
        ],
        [
            'subtitulo' => 'Cada minuto, no cada cinco',
            'texto' => 'Laravel no programa nada por su cuenta: en cada invocación mira el reloj y decide qué toca. Con una corrida cada cinco minutos, una tarea horaria se dispara a destiempo y una de cada minuto se salta cuatro de cada cinco.',
        ],
        [
            'subtitulo' => 'La cola de trabajos',
            'texto' => "Tres cosas se hacen fuera del ciclo de la petición: timbrar una factura, archivar una grabación y enviar correos. Con la cola en base de datos, encolar es insertar una fila — y alguien tiene que venir a tomarla.\n"
                ."El trabajador lo levanta el propio despachador, cada minuto, y sale en cuanto la cola se vacía. Así, instalar el despachador es instalar la cola: no hay una segunda pieza que alguien pueda olvidar.\n"
                ."Un solo trabajador sirve a todas las escuelas: el trabajo despachado dentro de una escuela cae en la tabla de la base CENTRAL con la escuela serializada dentro, y se reinicializa al ejecutarlo.\n"
                .'La reserva de una fila dura más que el trabajo más largo. Con el valor por omisión de Laravel —90 segundos— un archivado de media hora se ejecutaba en paralelo consigo mismo: el mismo video bajándose varias veces y el trabajo acabando en fallidos sin que nada hubiera fallado.',
        ],
        [
            'subtitulo' => 'Cómo se sabe que sigue vivo',
            'texto' => "El problema real de un despachador es que cuando deja de correr NO FALLA: no hay excepción, no hay log, no hay alerta. Simplemente no pasa nada, y el síntoma llega semanas después por otro lado.\n"
                ."Por eso hay un latido que se escribe cada minuto, y un comando que lo lee: `php artisan scheduler:estado`. Dice dos cosas —si el despachador da señales y si la cola avanza— y devuelve código de salida 1 cuando algo está caído, para engancharlo a la vigilancia del servidor sin leer su texto.\n"
                .'De la cola distingue tres situaciones, porque confundirlas da alarmas falsas: lo que ESPERA (sin reservar y con su turno llegado, que es lo único que mide si hay quien trabaje), lo que está EN PROCESO, y lo DIFERIDO que aguarda su reintento.',
        ],
        [
            'subtitulo' => 'Mantenimiento',
            'texto' => "`acadion:auditar-datos` busca filas que apuntan a registros que ya no existen: MySQL sólo comprueba las foráneas al ESCRIBIR, así que una resiembra con las comprobaciones apagadas deja filas envenenadas que sólo estorban el día que alguien toca el esquema. Por omisión sólo informa; con `--reparar` pone en NULL lo que admite null, columna por columna y no todo o nada.\n"
                ."`reportes:purgar-ejecuciones` y `tutorias:purgar-accesos` recortan las bitácoras que crecen solas.\n"
                .'Los trabajos que agotaron sus reintentos quedan en fallidos y NADIE los reintenta solo: `queue:failed` los lista y `queue:retry all` los vuelve a encolar. Un timbrado fallido es una factura que no existe ante el SAT.',
        ],
        [
            'subtitulo' => 'Las pruebas',
            'texto' => "Dos suites. `php artisan test` corre contra MySQL —no SQLite, porque las migraciones usan índice de texto completo, `INSERT IGNORE` y `UPDATE ... JOIN`— sobre dos bases que se crean solas.\n"
                ."Y las suites de integración de `scripts/`, que corren contra la base real de una escuela y deshacen todo con `DB::rollBack()`. Son 108, se corren todas de una vez, y prueban justo lo que un esquema de mentira no puede probar.\n"
                .'La disciplina que las hace valer: cada regla se comprueba MUTÁNDOLA. Una prueba que no falla cuando se rompe lo que dice probar no prueba nada, y este proyecto lleva varias encontradas así.',
        ],
    ],

    'integraciones' => [
        ['servicio' => 'SEP · web service', 'para_que' => 'Enviar certificados y títulos electrónicos.', 'como' => 'WSDL de producción y la e.firma de la institución. Los XML validan antes contra los XSD oficiales que viven en el repositorio.'],
        ['servicio' => 'PAC de facturación', 'para_que' => 'Timbrar el CFDI 4.0 de colegiaturas y el de nómina.', 'como' => 'Credenciales del PAC por razón social, cifradas. Hay un PAC falso que recorre el flujo entero sin timbrar nada.'],
        ['servicio' => 'Stripe, Conekta, MercadoPago, OpenPay, PayPal', 'para_que' => 'Cobro en línea con tarjeta.', 'como' => 'Credenciales por pasarela. `config/pagos.php` tiene modo `fake`; el default es `real`, para que a un despliegue que olvide la variable le toque cobrar y no simular.'],
        ['servicio' => 'Zoom', 'para_que' => 'Clases en línea y sus grabaciones en la nube.', 'como' => 'Cuenta con licencias: cada una sostiene UNA reunión a la vez. El webhook de grabaciones comprueba firma HMAC, y sin secreto configurado se rechaza.'],
        ['servicio' => 'Google Meet / Calendar / Drive', 'para_que' => 'Clases en línea y archivado de grabaciones.', 'como' => 'Google Workspace con una cuenta de servicio con delegación en todo el dominio. Meet no tiene webhook: hay que preguntarle, y por eso el recolector corre cada hora.'],
        ['servicio' => 'Dropbox', 'para_que' => 'Destino alternativo para archivar grabaciones.', 'como' => 'Credenciales de la aplicación. Un destino a la vez: con dos habría que decidir cuál enlace ve el alumno.'],
        ['servicio' => 'Banxico', 'para_que' => 'Tipo de cambio FIX para la tarjeta de indicadores.', 'como' => '`BANXICO_TOKEN`, gratuito. Sin él se muestra la referencia del BCE y se DICE que es referencia, porque con ésa no se timbra.'],
        ['servicio' => 'Open-Meteo', 'para_que' => 'Clima y calidad del aire del campus.', 'como' => 'Sin llave. Usa las coordenadas del campus y no la IP: desde la red de la escuela todo sale por el mismo enlace, y la IP es un dato personal.'],
        ['servicio' => 'date.nager.at', 'para_que' => 'Precargar los feriados oficiales del año.', 'como' => 'Sin llave. Llegan como BORRADOR: un feriado oficial no siempre es día sin clases, y esa decisión es de la dirección.'],
        ['servicio' => 'Correo saliente', 'para_que' => 'Credenciales de acceso, avisos y reportes programados.', 'como' => 'SMTP configurable por escuela desde Plataforma → Envío de correos.'],
        ['servicio' => 'Google SSO', 'para_que' => 'Entrar con cuenta de Google.', 'como' => 'Credenciales de OAuth. Opcional; el acceso con correo o CURP y contraseña sigue funcionando.'],
    ],

    'glosario' => [
        ['termino' => 'Tenant', 'definicion' => 'Una escuela. Cada una tiene su propia base de datos y entra por su propio dominio.'],
        ['termino' => 'Landlord / base central', 'definicion' => 'El registro de escuelas y los catálogos que no son de nadie. Ahí no hay datos de alumnos.'],
        ['termino' => 'Persona', 'definicion' => 'La identidad de alguien. Sobre ella se cuelgan sus papeles: aspirante, alumna, docente, madre de familia.'],
        ['termino' => 'Faceta', 'definicion' => 'Lo que alguien ES en la escuela: administrativo, docente, alumno, aspirante, tutor educativo o padre de familia. Son seis y no se borran.'],
        ['termino' => 'Rol funcional', 'definicion' => 'Lo que alguien HACE. Cuelga de una faceta, hereda sus permisos y lo crea la escuela.'],
        ['termino' => 'Rol activo', 'definicion' => 'Con cuál de sus roles está trabajando ahora quien entró. Cambia lo que ve y lo que puede hacer.'],
        ['termino' => 'Matrícula', 'definicion' => 'Una persona cursando un programa concreto. Es «el alumno»: quien estudia dos programas académicos tiene dos.'],
        ['termino' => 'Oferta', 'definicion' => 'La combinación de programa académico, plan de estudios y campus. Es lo que se puede cursar.'],
        ['termino' => 'Asignatura-grupo', 'definicion' => 'Una materia abierta en un grupo de un ciclo. La unidad sobre la que se inscribe, se pasa lista y se califica.'],
        ['termino' => 'Esquema de evaluación', 'definicion' => 'De qué se compone la calificación de una materia y cuánto pesa cada parte.'],
        ['termino' => 'Acta', 'definicion' => 'El cierre de una materia. Emite folio, asienta el historial y ya no se edita; para corregir se emite un acta de corrección.'],
        ['termino' => 'Historial', 'definicion' => 'La historia escolar asentada, renglón por renglón. Para los totales cuenta el mejor intento por materia.'],
        ['termino' => 'Adeudo', 'definicion' => 'Un cargo emitido a una matrícula o a un aspirante. Su estatus se deriva de lo que se le ha pagado.'],
        ['termino' => 'Plan de cobro', 'definicion' => 'Qué se cobra, a quién aplica y con qué calendario. Gana el más específico vigente.'],
        ['termino' => 'Timbrado', 'definicion' => 'Enviar un comprobante al PAC para que lo certifique ante el SAT. Timbrado, ya no se edita.'],
        ['termino' => 'CSD', 'definicion' => 'Certificado de sello digital: con él se firma lo que se manda al SAT o a la SEP.'],
        ['termino' => 'Módulo', 'definicion' => 'Una sección del sistema que la escuela enciende o apaga. Apagada, desaparece del menú y sus direcciones dejan de responder.'],
        ['termino' => 'Puerta derivada', 'definicion' => 'Un permiso calculado que abre una pantalla a la que entran dos oficios. No es asignable.'],
        ['termino' => 'Permiso', 'definicion' => 'Una llave que el código consulta. No se crean desde pantalla; se reparten entre roles.'],
        ['termino' => 'Alcance', 'definicion' => 'Sobre quién o sobre qué aplica un permiso: un campus, unas materias, unos hijos, unos prospectos.'],
        ['termino' => 'Despachador', 'definicion' => 'Lo que invoca al sistema cada minuto para que corran las tareas programadas. Sin él no ocurre nada de lo automático.'],
        ['termino' => 'Cola de trabajos', 'definicion' => 'Lo que se hace fuera de la petición: timbrar, archivar una grabación, mandar correo.'],
        ['termino' => 'Fuente de reporte', 'definicion' => 'Un dominio con sus columnas y filtros. Cada reporte es una pregunta concreta sobre una fuente.'],
        ['termino' => 'Vista guardada', 'definicion' => 'Filtros, columnas y orden de un reporte, guardados con un nombre y compartibles.'],
    ],
];
