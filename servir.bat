@echo off
REM Servidor de desarrollo del tenant. Lo invoca .claude/launch.json.
REM
REM `cd /d "%~dp0"` ancla el arranque a la carpeta de ESTE archivo. Sin eso el
REM .bat hereda el directorio de quien lo llame, y si resulta ser otro proyecto
REM Laravel se levanta ese: la raíz responde 200 y todo lo demás 404, que se
REM diagnostica como un problema de rutas cuando en realidad se está sirviendo
REM otra aplicación.
cd /d "%~dp0"

REM 127.0.0.1 porque los tenants se resuelven por subdominio (demo.localhost) y
REM Windows los manda a la loopback sin tocar el archivo hosts.
php artisan serve --host=127.0.0.1 --port=8000
