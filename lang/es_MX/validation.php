<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mensajes de validación
|--------------------------------------------------------------------------
|
| El sistema corre con `locale = es_MX`, pero mientras este archivo no
| existió Laravel caía al idioma de respaldo y le enseñaba al usuario cosas
| como «The fecha de cierre field must be a date after or equal to fecha de
| apertura». No era un descuido de una pantalla: era TODA la validación del
| sistema, en cualquier formulario que no llevara mensajes escritos a mano.
|
| Aquí están las 148 claves que trae el framework, no las que hoy se usan: en
| cuanto alguien agregue una regla nueva a un controlador, su mensaje ya está
| en español sin que haya que acordarse de volver aquí.
|
| El nombre del campo lo resuelve Laravel solo, convirtiendo `cierra_en` en
| «cierra en». Cuando esa conversión no diga nada útil, se corrige en el
| arreglo `attributes` del final, no en el mensaje.
|
*/

return [

    'accepted' => 'Debes aceptar :attribute.',
    'accepted_if' => 'Debes aceptar :attribute cuando :other sea :value.',
    'active_url' => ':attribute no es una URL válida.',
    'after' => ':attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':attribute debe ser una fecha igual o posterior a :date.',
    'alpha' => ':attribute solo puede contener letras.',
    'alpha_dash' => ':attribute solo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':attribute solo puede contener letras y números.',
    'any_of' => ':attribute no es válido.',
    'array' => ':attribute debe ser una lista.',
    'ascii' => ':attribute solo puede contener caracteres alfanuméricos y símbolos de un byte.',
    'before' => ':attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute debe ser una fecha igual o anterior a :date.',
    'between' => [
        'array' => ':attribute debe tener entre :min y :max elementos.',
        'file' => ':attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute debe estar entre :min y :max.',
        'string' => ':attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => ':attribute debe ser verdadero o falso.',
    'can' => ':attribute contiene un valor no autorizado.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'contains' => 'A :attribute le falta un valor obligatorio.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => ':attribute no es una fecha válida.',
    'date_equals' => ':attribute debe ser una fecha igual a :date.',
    'date_format' => ':attribute no corresponde con el formato :format.',
    'decimal' => ':attribute debe tener :decimal decimales.',
    'declined' => 'Debes rechazar :attribute.',
    'declined_if' => 'Debes rechazar :attribute cuando :other sea :value.',
    'different' => ':attribute y :other deben ser distintos.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'digits_between' => ':attribute debe tener entre :min y :max dígitos.',
    'dimensions' => ':attribute tiene dimensiones de imagen no válidas.',
    'distinct' => ':attribute tiene un valor repetido.',
    'doesnt_contain' => ':attribute no puede contener ninguno de los siguientes: :values.',
    'doesnt_end_with' => ':attribute no puede terminar con ninguno de los siguientes: :values.',
    'doesnt_start_with' => ':attribute no puede comenzar con ninguno de los siguientes: :values.',
    'email' => ':attribute no es un correo electrónico válido.',
    'encoding' => ':attribute debe usar la codificación :encoding.',
    'ends_with' => ':attribute debe terminar con alguno de los siguientes: :values.',
    'enum' => 'El valor de :attribute no es válido.',
    'exists' => 'El valor de :attribute no existe.',
    'extensions' => ':attribute debe tener alguna de estas extensiones: :values.',
    'file' => ':attribute debe ser un archivo.',
    'filled' => ':attribute no puede quedar vacío.',
    'gt' => [
        'array' => ':attribute debe tener más de :value elementos.',
        'file' => ':attribute debe pesar más de :value kilobytes.',
        'numeric' => ':attribute debe ser mayor que :value.',
        'string' => ':attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute debe tener :value elementos o más.',
        'file' => ':attribute debe pesar :value kilobytes o más.',
        'numeric' => ':attribute debe ser mayor o igual que :value.',
        'string' => ':attribute debe tener :value caracteres o más.',
    ],
    'hex_color' => ':attribute debe ser un color hexadecimal válido.',
    'image' => ':attribute debe ser una imagen.',
    'in' => 'El valor de :attribute no es válido.',
    'in_array' => 'El valor de :attribute no existe en :other.',
    'in_array_keys' => ':attribute debe incluir al menos una de estas claves: :values.',
    'integer' => ':attribute debe ser un número entero.',
    'ip' => ':attribute debe ser una dirección IP válida.',
    'ipv4' => ':attribute debe ser una dirección IPv4 válida.',
    'ipv6' => ':attribute debe ser una dirección IPv6 válida.',
    'json' => ':attribute debe ser una cadena JSON válida.',
    'list' => ':attribute debe ser una lista.',
    'lowercase' => ':attribute debe ir en minúsculas.',
    'lt' => [
        'array' => ':attribute debe tener menos de :value elementos.',
        'file' => ':attribute debe pesar menos de :value kilobytes.',
        'numeric' => ':attribute debe ser menor que :value.',
        'string' => ':attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute no puede tener más de :value elementos.',
        'file' => ':attribute debe pesar :value kilobytes o menos.',
        'numeric' => ':attribute debe ser menor o igual que :value.',
        'string' => ':attribute debe tener :value caracteres o menos.',
    ],
    'mac_address' => ':attribute debe ser una dirección MAC válida.',
    'max' => [
        'array' => ':attribute no puede tener más de :max elementos.',
        'file' => ':attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':attribute no puede ser mayor que :max.',
        'string' => ':attribute no puede tener más de :max caracteres.',
    ],
    'max_digits' => ':attribute no puede tener más de :max dígitos.',
    'mimes' => ':attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => ':attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':attribute debe tener al menos :min elementos.',
        'file' => ':attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':attribute debe ser al menos :min.',
        'string' => ':attribute debe tener al menos :min caracteres.',
    ],
    'min_digits' => ':attribute debe tener al menos :min dígitos.',
    'missing' => ':attribute no debe enviarse.',
    'missing_if' => ':attribute no debe enviarse cuando :other sea :value.',
    'missing_unless' => ':attribute no debe enviarse salvo que :other sea :value.',
    'missing_with' => ':attribute no debe enviarse cuando :values esté presente.',
    'missing_with_all' => ':attribute no debe enviarse cuando :values estén presentes.',
    'multiple_of' => ':attribute debe ser múltiplo de :value.',
    'not_in' => 'El valor de :attribute no es válido.',
    'not_regex' => 'El formato de :attribute no es válido.',
    'numeric' => ':attribute debe ser un número.',
    'password' => [
        'letters' => ':attribute debe contener al menos una letra.',
        'mixed' => ':attribute debe contener al menos una mayúscula y una minúscula.',
        'numbers' => ':attribute debe contener al menos un número.',
        'symbols' => ':attribute debe contener al menos un símbolo.',
        'uncompromised' => ':attribute apareció en una filtración de datos. Elige otra contraseña.',
    ],
    'present' => ':attribute debe estar presente.',
    'present_if' => ':attribute debe estar presente cuando :other sea :value.',
    'present_unless' => ':attribute debe estar presente salvo que :other sea :value.',
    'present_with' => ':attribute debe estar presente cuando :values esté presente.',
    'present_with_all' => ':attribute debe estar presente cuando :values estén presentes.',
    'prohibited' => ':attribute no está permitido.',
    'prohibited_if' => ':attribute no está permitido cuando :other sea :value.',
    'prohibited_if_accepted' => ':attribute no está permitido cuando se acepta :other.',
    'prohibited_if_declined' => ':attribute no está permitido cuando se rechaza :other.',
    'prohibited_unless' => ':attribute no está permitido salvo que :other sea :values.',
    'prohibits' => ':attribute impide que :other esté presente.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => ':attribute es obligatorio.',
    'required_array_keys' => ':attribute debe incluir estas claves: :values.',
    'required_if' => ':attribute es obligatorio cuando :other sea :value.',
    'required_if_accepted' => ':attribute es obligatorio cuando se acepta :other.',
    'required_if_declined' => ':attribute es obligatorio cuando se rechaza :other.',
    'required_unless' => ':attribute es obligatorio salvo que :other sea :values.',
    'required_with' => ':attribute es obligatorio cuando :values está presente.',
    'required_with_all' => ':attribute es obligatorio cuando :values están presentes.',
    'required_without' => ':attribute es obligatorio cuando :values no está presente.',
    'required_without_all' => ':attribute es obligatorio cuando ninguno de :values está presente.',
    'same' => ':attribute y :other deben coincidir.',
    'size' => [
        'array' => ':attribute debe contener :size elementos.',
        'file' => ':attribute debe pesar :size kilobytes.',
        'numeric' => ':attribute debe ser :size.',
        'string' => ':attribute debe tener :size caracteres.',
    ],
    'starts_with' => ':attribute debe comenzar con alguno de los siguientes: :values.',
    'string' => ':attribute debe ser texto.',
    'timezone' => ':attribute debe ser una zona horaria válida.',
    'unique' => ':attribute ya está en uso.',
    'uploaded' => 'No se pudo subir :attribute.',
    'uppercase' => ':attribute debe ir en mayúsculas.',
    'url' => ':attribute no es una URL válida.',
    'ulid' => ':attribute debe ser un ULID válido.',
    'uuid' => ':attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensajes por campo
    |--------------------------------------------------------------------------
    |
    | Para cuando una regla necesita decir algo distinto en un campo concreto.
    | Se prefiere el `messages()` del propio controlador cuando el mensaje sólo
    | tiene sentido en esa pantalla; esto es para lo que se repite.
    |
    */

    'custom' => [
        'password' => [
            'confirmed' => 'Las contraseñas no coinciden.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nombres de los campos
    |--------------------------------------------------------------------------
    |
    | Laravel convierte `abre_en` en «abre en», que casi siempre basta. Aquí
    | van sólo los que quedarían raros o ambiguos leídos así, y los que el
    | usuario ve con otro nombre en la pantalla: el mensaje tiene que hablar
    | del campo que la persona está mirando, no del nombre de la columna.
    |
    */

    'attributes' => [
        'abre_en' => 'la fecha de apertura',
        'cierra_en' => 'la fecha de cierre',
        'vigente_desde' => 'el inicio de vigencia',
        'vigente_hasta' => 'el fin de vigencia',
        'inscripcion_desde' => 'la apertura de inscripción',
        'inscripcion_hasta' => 'el cierre de inscripción',
        'inicia_en' => 'la fecha de inicio',
        'termina_en' => 'la fecha de término',
        'fecha_inicio' => 'la fecha de inicio',
        'fecha_fin' => 'la fecha de fin',
        'curp' => 'la CURP',
        'rfc' => 'el RFC',
        'email' => 'el correo',
        'password' => 'la contraseña',
        'password_confirmation' => 'la confirmación de la contraseña',
        'identificador' => 'el correo o CURP',
        'nombre' => 'el nombre',
        'clave' => 'la clave',
        'descripcion' => 'la descripción',
        'telefono' => 'el teléfono',
        'campus_id' => 'el campus',
        'carrera_id' => 'la carrera',
        'plan_id' => 'el plan de estudios',
        'grupo_id' => 'el grupo',
        'ciclo_id' => 'el ciclo',
        'asignatura_id' => 'la asignatura',
        'docente_id' => 'el docente',
        'alumno_id' => 'el alumno',
        'rol_id' => 'el rol',
        'archivo' => 'el archivo',
        'archivos' => 'los archivos',
    ],

];
