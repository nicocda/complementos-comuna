# Despliegue en Hostinger (hosting compartido)

Guía para publicar la app en un subdominio, por ejemplo `gestion.cañadarosquin.gob.ar`, en el mismo plan donde está el WordPress de la comuna.

> El dominio tiene "ñ": en `.env` y en cualquier configuración técnica usá su forma punycode, `gestion.xn--caadarosquin-bhb.gob.ar`. Los navegadores muestran igual la versión con ñ.

## Requisitos del plan (verificados)

- PHP 8.3 o superior. Se recomienda 8.5, la misma versión que en desarrollo. Necesita las extensiones `intl`, `pdo_mysql`, `mbstring`, `fileinfo`, `zip` y `gd`.
- MySQL, cron jobs y SSH. El plan tiene los tres; SSH hay que activarlo.
- Composer en el servidor. Se usa por SSH: verificá con `composer --version`.
- No hace falta Node.js en el servidor: el panel no compila assets con Vite.

## 1. Preparar en hPanel

1. **Subdominio**: *Dominios → Subdominios* → crear `gestion`. Anotá la carpeta que le asigna.
2. **PHP**: *Avanzado → Configuración de PHP* → seleccionar **8.5** y confirmar que `intl` esté habilitada. Si la versión se cambia para todo el sitio, revisá antes que el WordPress funcione bien con esa versión.
3. **Base de datos**: *Bases de datos → Gestión* → crear una base y un usuario **nuevos**, exclusivos para esta app. No reutilices la base del WordPress. Guardá la contraseña en tu gestor de contraseñas, no en archivos sueltos.
4. **Casilla de correo** para notificaciones, por ejemplo `notificaciones@…`. En *Emails → Configurar dispositivos* figuran el servidor SMTP y el puerto.
5. **SSH**: *Avanzado → Acceso SSH* → activar. Usá una **clave SSH** (*Claves SSH → Agregar*) en lugar de contraseña.

## 2. Subir el código

El código va **fuera** de `public_html`, y solo la carpeta `public/` queda expuesta a la web:

```text
/home/<usuario>/apps/gestion          ← proyecto completo (.env, vendor, storage, ...)
/home/<usuario>/apps/gestion/public   ← lo único que debe servir el subdominio
```

Por SSH:

```bash
mkdir -p ~/apps && cd ~/apps
git clone <url-del-repo-privado> gestion   # o subir un .zip por el Administrador de archivos y descomprimirlo acá
cd gestion
composer install --no-dev --optimize-autoloader
```

Para que el subdominio sirva `public/`, reemplazá su carpeta (la que anotaste en el paso 1.1) por un enlace simbólico:

```bash
mv <carpeta-del-subdominio> <carpeta-del-subdominio>.bak
ln -s ~/apps/gestion/public <carpeta-del-subdominio>
```

Si el panel permite elegir directamente la carpeta raíz del subdominio, apuntala a `~/apps/gestion/public` y omití el enlace.

## 3. Configurar `.env`

```bash
cp .env.example .env
chmod 600 .env
php artisan key:generate
```

Editar `.env` (con `nano .env`) con al menos:

```ini
APP_NAME="Gestion Interna"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gestion.xn--caadarosquin-bhb.gob.ar

LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<base creada en 1.3>
DB_USERNAME=<usuario creado en 1.3>
DB_PASSWORD=<contraseña>

SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=<servidor SMTP de hPanel>
MAIL_PORT=<puerto de hPanel>
MAIL_SCHEME=smtps            # si el puerto es 465 (SSL); con 587 dejar "smtp"
MAIL_USERNAME=notificaciones@<dominio>
MAIL_PASSWORD=<contraseña de la casilla>
MAIL_FROM_ADDRESS=notificaciones@<dominio>
```

`APP_DEBUG` **siempre** en `false` en producción: con `true` los errores muestran rutas, consultas y variables de entorno.

## 4. Base de datos, roles y primer administrador

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan app:create-admin           # pide nombre, email y contraseña por consola
php artisan storage:link 2>/dev/null || true
php artisan filament:assets
php artisan optimize
php artisan filament:optimize
```

`DatabaseSeeder` crea usuarios de demostración **solo** con `APP_ENV=local`. En producción no corras `db:seed` sin `--class`.

## 5. Cron (cola de notificaciones)

Las notificaciones (campanita del panel y emails) salen por cola. En el hosting compartido la cola se procesa con el scheduler cada minuto.

*Avanzado → Tareas Cron → Personalizado*, cada minuto (`* * * * *`):

```text
/usr/bin/php /home/<usuario>/apps/gestion/artisan schedule:run >> /dev/null 2>&1
```

Si la versión de PHP del cron no es la del sitio, usá la ruta del binario correspondiente; hPanel la muestra en el ejemplo del formulario.

## 6. Verificación

- Abrir `https://gestion.cañadarosquin.gob.ar` → debe redirigir a `/admin/login`.
- Entrar con el administrador, crear un usuario de prueba con rol *Encargado* y cargar un aviso. A los 1–2 minutos le tiene que llegar la notificación.
- `https://…/up` devuelve 200 (chequeo de salud de Laravel).
- Revisar `storage/logs/laravel.log` si algo falla.

## 7. Actualizar a una nueva versión

```bash
cd ~/apps/gestion
php artisan down
git pull                       # o subir los archivos nuevos
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan filament:assets
php artisan optimize
php artisan filament:optimize
php artisan up
```

## Seguridad y datos personales

- La app guarda nombres de empleados, asignaciones de vehículos, patentes y costos: son **datos personales** (Ley 25.326). El acceso es solo con usuario y rol; no compartas usuarios.
- Los formularios firmados escaneados se guardan en `storage/app/private`, fuera de la web, y solo se descargan con permiso.
- Hostinger hace backups del plan; además conviene exportar la base de datos periódicamente desde phpMyAdmin y guardarla en un lugar seguro.
- Desactivá (no borres) a los usuarios que dejan de trabajar: así el historial conserva quién hizo qué.
