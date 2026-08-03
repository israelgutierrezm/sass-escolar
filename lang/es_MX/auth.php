<?php

declare(strict_types=1);

return [

    /*
     * Deliberadamente vago: no se dice si falló el usuario o la contraseña.
     * Distinguirlo le confirmaría a quien prueba correos al azar cuáles están
     * dados de alta en la escuela.
     */
    'failed' => 'Los datos de acceso no coinciden con nuestros registros.',

    'password' => 'La contraseña es incorrecta.',

    'throttle' => 'Demasiados intentos de acceso. Vuelve a intentarlo en :seconds segundos.',

];
