# Tipografías del proyecto

## OpenSans.ttf

**Open Sans Regular**, © 2010-2011 Google Corporation, diseñada por Steve
Matteson (Ascender). Publicada bajo la **Apache License 2.0**, que permite
redistribuirla; la atribución es esta nota.

Los datos vienen de la propia tipografía —su tabla `name` declara el copyright y
la licencia—, no de suponerlo por el nombre.

### Por qué vive aquí y no se lee del `vendor/`

El archivo llegó al proyecto dentro de `endroid/qr-code`, que lo trae para
rotular sus códigos. Leerlo de ahí funcionaría hoy y sería frágil mañana: es un
detalle interno de ese paquete, no parte de su API, así que un `composer update`
puede moverlo o quitarlo y la credencial dejaría de dibujar texto **sin que
ningún cambio nuestro lo explique**.

### Para qué se usa

La compone `App\Credencial\Compositor` sobre el lienzo de la credencial. GD sólo
sabe dibujar texto decente con una tipografía TrueType: sus fuentes internas son
mapas de bits diminutos, ilegibles a 300 dpi, y no llevan acentos —que en
«María» o «Ingeniería» no es un detalle—.
