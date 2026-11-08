# Gestión interna — módulos de trabajo

Aplicación web interna con usuarios, roles y permisos por módulo. El primer módulo es el de **comunicación y autorización de reparaciones** de la flota.

Stack: Laravel 13, Filament 5 (panel), spatie/laravel-permission, MySQL en producción y SQLite en desarrollo.

## Módulos

### Flota

- **Unidades**: número de interno (opcional para implementos S/N), tipo, descripción, patente, estado (activa / en maestranza / de baja) y unidad a la que está acoplado cada implemento.
- **Conductores**: pueden estar vinculados a un usuario del sistema y tener varias unidades asignadas.
- **Importar planilla**: CSV con columnas `NRO;TIPO;VEHICULO;NRO2;VEHICULO2;CONDUCTOR`. Ver [docs/plantilla-flota.csv](docs/plantilla-flota.csv). Si se vuelve a importar, actualiza sin duplicar.

### Reparaciones

Flujo según el procedimiento:

```text
Reportado → Pendiente de aprobación → Autorizado → En reparación → Finalizado
     │               │   └──→ Rechazado
     │               └──→ (devuelto) Reportado
     └──→ Anulado
```

1. **Aviso** del conductor: unidad, problema, tipo (rotura o falla), fecha y número correlativo. Se puede imprimir para firmar y subir el escaneo firmado.
2. **Informe técnico** del encargado: observaciones, urgencia, repuestos o servicio estimado y costo estimado. Al guardarlo se eleva a los superiores.
3. **Decisión** de los superiores: autorizar, rechazar o pedir más información.
4. **Seguimiento**: proveedor, reparación realizada, costo final y fecha.

Cada cambio queda en el historial (quién, cuándo, comentario) y notifica a quien tiene que actuar: campanita del panel y email. Cada solicitud tiene además un botón "Enviar por WhatsApp" que arma el mensaje con el link.

## Roles

| Rol | Puede |
| --- | --- |
| Administrador | Todo, incluida la gestión de usuarios |
| Superior | Ver todo, autorizar, rechazar o devolver |
| Encargado | Gestionar flota, cargar avisos, hacer informes, registrar seguimiento |
| Conductor | Cargar avisos propios y ver solo los suyos (sin costos) |

Los permisos se definen en [app/Support/Permissions.php](app/Support/Permissions.php). Para agregar un módulo nuevo, sumá sus permisos con prefijo (`modulo.accion`) y corré `php artisan db:seed --class=RolesAndPermissionsSeeder`.

## Desarrollo local

Requisitos: PHP 8.3 o superior con `intl`, `pdo_sqlite` y `fileinfo`, y Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # con APP_ENV=local crea usuarios demo (*@demo.test / "password")
php artisan serve               # http://localhost:8000
php artisan queue:work          # en otra terminal, para procesar las notificaciones
```

Los emails en local van a `storage/logs/laravel.log` (`MAIL_MAILER=log`).

## Calidad

```bash
php artisan test                # pruebas (PHPUnit)
vendor/bin/pint --test          # estilo
vendor/bin/phpstan analyse      # análisis estático (Larastan, nivel 6)
```

> Si `phpstan` queda colgado sin consumir CPU, algún firewall/antivirus (p. ej. Avast) está bloqueando la conexión local entre sus procesos. Opciones: agregar una excepción para `php.exe` o correrlo en un solo proceso con `vendor/bin/phpstan analyse --debug`.

## Despliegue

Ver [docs/despliegue-hostinger.md](docs/despliegue-hostinger.md).

## Datos personales

La base contiene nombres de empleados, asignaciones de vehículos y patentes. **No** se versionan planillas reales ni dumps de la base: la flota se carga desde la app con "Importar planilla".
