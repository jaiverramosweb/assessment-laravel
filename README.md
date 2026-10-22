# Plataforma de Assessments

Encuestas evaluativas con acceso por contraseña compartida, reportes de alineación por
pregunta, exportación a Excel y PDF, y análisis con IA de las respuestas abiertas.

Es la versión en **Laravel** de la plataforma original (SvelteKit + Node), hecha para correr en
un hosting cPanel **sin Node**: el servidor solo necesita PHP 8.3. Los assets se compilan en el
equipo de desarrollo y se suben ya listos.

**Stack:** Laravel 12 · PHP 8.3 · Inertia 2 + Svelte 5 · Tailwind CSS 4 · MySQL/MariaDB
(producción) o SQLite (desarrollo) ·
PhpSpreadsheet · Dompdf · API de Anthropic.

## Qué hace

| Para quién        | Funciones                                                                                                                                                                                                                     |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Administrador** | Assessments (crear, editar, duplicar, archivar, eliminar) · preguntas (editor, Excel/CSV y Word) · contraseña de acceso · participantes · invitaciones · reportes · Excel y PDF · clientes · mediciones · IA · auditoría |
| **Participante**  | Abre el enlace, escribe la contraseña de acceso y su correo, responde con autoguardado (puede salir y volver) y entrega una sola vez                                                                                          |

El detalle de cada función es el mismo de la plataforma original.

## Desarrollo local

Requisitos: PHP 8.3 (con `pdo_sqlite`, `openssl`, `zip`, `gd`, `mbstring`, `intl`), Composer, Node 22+.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan admin:create          # pide email, nombre y contraseña
npm run dev                       # en otra terminal: php artisan serve → http://localhost:8000
```

| Comando                                    | Qué hace                                                          |
| ------------------------------------------ | ----------------------------------------------------------------- |
| `php artisan test`                         | Pruebas del servidor (PHPUnit) sobre SQLite en memoria            |
| `npm test` / `npm run check`               | Pruebas de las librerías del frontend / verificación de tipos     |
| `npm run build`                            | Compila los assets en `public/build`                              |
| `php artisan admin:create`                 | Crea un administrador (`--reset --email=…` cambia su contraseña)  |
| `php artisan db:backup`                    | Respaldo en caliente (`--dir=…`, `--keep=14`)                     |
| `scripts/build-release.sh`                 | Arma `dist/assessment-app.zip` para subir a cPanel                |

Para correr las pruebas sobre MySQL/MariaDB (como en producción), cree una base vacía con
collation `utf8mb4_bin` y ejecute:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=assess_test DB_USERNAME=root DB_PASSWORD= php artisan test
```

## Despliegue en cPanel (sin Node)

El servidor de destino tiene PHP 8.3 en `/opt/cpanel/ea-php83/root/usr/bin/php`.
**Ojo:** el comando `php` de la terminal es PHP 8.0. Use siempre la ruta completa de PHP 8.3
(abajo, `$PHP`).

Condiciones del servidor que la aplicación ya tiene en cuenta:

- La base de datos es **MySQL/MariaDB** (MariaDB 10.3): el SQLite del servidor es la versión
  3.7.17, demasiado antigua.
- **`proc_open` está deshabilitado**: PHP no puede ejecutar otros programas. Por eso las tareas
  programadas corren dentro del mismo proceso, el respaldo se escribe con PHP (sin `mysqldump`)
  y Composer se ejecuta con `--no-scripts`.
- No hay Node: las pantallas compiladas (`public/build/`) se generan en el equipo de desarrollo
  y se suben a mano.

El código llega por git desde GitHub; solo `public/build/` se sube a mano.

### 1. En cPanel (solo la primera vez)

1. **Dominios** → `assesment.sisteltestserver.com` → **Manage** → **New Document Root**:
   `assesment.sisteltestserver.com/public`. Así solo `public/` queda expuesto a internet; el
   `.env` y el resto del código no se pueden descargar.
2. **MultiPHP Manager** → `assesment.sisteltestserver.com` → **PHP 8.3**.
3. **SSL/TLS Status** → **Run AutoSSL** para el subdominio. HTTPS es obligatorio: las cookies de
   sesión son `Secure`.
4. **Bases de datos MySQL** (o **MySQL Database Wizard**):
   1. Cree una base, por ejemplo `assessments` (cPanel le antepone su usuario:
      `USUARIO_assessments`).
   2. Cree un usuario con una contraseña larga y agréguelo a la base con **TODOS LOS PRIVILEGIOS**.
   3. Anote el nombre completo de la base, el usuario y la contraseña: van en el `.env`.

### 2. En la terminal: traer el código (solo la primera vez)

La carpeta del subdominio ya existe y tiene `cgi-bin` y `.well-known` (la usa el SSL), así que en
lugar de `git clone` se inicializa git dentro de ella:

```bash
cd ~/assesment.sisteltestserver.com
git init -b main
git remote add origin https://github.com/jaiverramosweb/assessment-laravel.git
git pull origin main
git branch --set-upstream-to=origin/main main
```

### 3. Subir las pantallas compiladas

En el equipo de desarrollo:

```bash
npm run build
```

Comprima la carpeta `public/build` (el zip debe contener `public/build/...`), súbala con el
**Administrador de archivos** a `~/assesment.sisteltestserver.com/`, **Extraer**, y borre el zip.
Debe quedar `~/assesment.sisteltestserver.com/public/build/manifest.json`.

### 4. En la terminal: instalar y configurar (solo la primera vez)

```bash
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
cd ~/assesment.sisteltestserver.com
# Debe listar las 7 extensiones (pdo_mysql para la base; las demás para Excel y PDF).
# Si falta alguna, actívela en "Select PHP Version".
$PHP -m | grep -iE "^(pdo_mysql|xmlreader|xmlwriter|simplexml|iconv|zip|gd)$"
$PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-scripts
$PHP artisan package:discover
cp .env.example .env
$PHP artisan key:generate
$PHP -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # clave para ACCESS_PASSWORD_KEY
```

Edite `.env` (Administrador de archivos → Editar). La línea `DB_CONNECTION=sqlite` se reemplaza:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://assesment.sisteltestserver.com
SESSION_SECURE_COOKIE=true
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=USUARIO_assessments
DB_USERNAME=USUARIO_assessments
DB_PASSWORD=           # la contraseña del usuario de la base
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=1200
ACCESS_PASSWORD_KEY=   # la clave que imprimió el último comando
ANTHROPIC_API_KEY=     # si va a usar el análisis con IA
MAIL_MAILER=smtp       # y MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS
```

Guarde `ACCESS_PASSWORD_KEY` en un lugar seguro: sin ella las contraseñas de acceso guardadas no
se pueden volver a mostrar (el ingreso de participantes sigue funcionando).

Luego cree las tablas y el primer administrador. La contraseña se escribe sin que se vea
(`read -s`) y no queda en el historial:

```bash
$PHP artisan migrate --force
read -s -p "Contraseña del administrador: " ADMIN_PASSWORD; echo; export ADMIN_PASSWORD
$PHP artisan admin:create --email=ana@empresa.com --name="Ana Pérez"
unset ADMIN_PASSWORD
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
```

### 5. Cron (análisis con IA, mantenimiento y respaldo)

cPanel → **Cron Jobs** → cada minuto (`* * * * *`), con la ruta completa de la carpeta:

```bash
/opt/cpanel/ea-php83/root/usr/bin/php /home2/USUARIO/assesment.sisteltestserver.com/artisan schedule:run >> /dev/null 2>&1
```

Ese único cron:

- procesa la cola del análisis con IA (sin el límite de tiempo de PHP en la web). Sin él, el
  análisis queda "en proceso" para siempre;
- cada hora limpia sesiones vencidas e intentos de ingreso antiguos;
- cada día a las 03:00 UTC (22:00 en Bogotá) guarda un respaldo en `storage/backups/` y conserva
  los 14 más recientes.

Cada respaldo es un archivo `.sql`. Se restaura con **phpMyAdmin** → base → **Importar**.
Descargue copias fuera del servidor de vez en cuando. Para un respaldo manual:
`$PHP artisan db:backup`.

### Actualizar a una versión nueva

```bash
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
cd ~/assesment.sisteltestserver.com
git pull
$PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-scripts
$PHP artisan package:discover
$PHP artisan migrate --force
$PHP artisan config:cache && $PHP artisan route:cache && $PHP artisan view:cache
```

Si cambiaron las pantallas (`resources/js`), suba también el `public/build` nuevo (paso 3).
`git pull` nunca toca `.env`, `public/build/`, `database/clave-cifrado.key` ni `storage/`.

### Si algo falla

- Errores de la app: `storage/logs/laravel.log`.
- "Access denied" o "Unknown database" al migrar: revise `DB_DATABASE`, `DB_USERNAME` y
  `DB_PASSWORD` (con el prefijo `USUARIO_`) y que el usuario esté agregado a la base.
- Página en blanco o error 500 al iniciar: revise permisos de escritura en `storage/` y
  `bootstrap/cache/`, y que el subdominio use PHP 8.3.
- "La página venció": la sesión expiró; recargue la página.

## Seguridad

- Contraseñas de administradores y de acceso en **Argon2id** (los hashes de la plataforma original
  son compatibles). La contraseña de acceso además se guarda **cifrada** (AES-256-GCM) para que el
  admin pueda verla; cada consulta queda en la auditoría.
- Sesiones con token aleatorio guardado como SHA-256; la del administrador vence por inactividad y
  siempre a los 7 días.
- Límite de intentos por IP y por email en el ingreso de administradores y participantes.
- Logos validados por contenido (PNG, JPG o WebP, hasta 2 MB; sin SVG).
- Encabezados de seguridad y Content-Security-Policy en producción.
