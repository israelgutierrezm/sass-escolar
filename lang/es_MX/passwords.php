<?php

declare(strict_types=1);

return [

    'reset' => 'Tu contraseña se restableció.',
    'sent' => 'Te enviamos por correo el enlace para restablecer tu contraseña.',
    'throttled' => 'Espera un momento antes de volver a intentarlo.',
    'token' => 'El enlace para restablecer la contraseña no es válido o ya expiró.',

    /*
     * Se responde lo mismo exista o no la cuenta —quien la pide ve siempre el
     * mensaje de «te enviamos el enlace»—, así que este texto rara vez llega a
     * la pantalla. Traducido de todos modos: si algún día se muestra, no puede
     * salir en inglés.
     */
    'user' => 'No encontramos ninguna cuenta con ese correo.',

];
