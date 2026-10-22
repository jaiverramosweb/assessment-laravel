#!/usr/bin/env bash
# Builds the upload package for cPanel: dist/assessment-app.zip
# Everything that needs Node or Composer runs here, so the server only needs PHP 8.3.
#
# Usage (Git Bash on Windows, or any bash):
#   scripts/build-release.sh
#   PHP_BIN=/path/to/php8.3 scripts/build-release.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-C:/laragon/bin/php/php-8.3.24-nts-Win32-vs16-x64/php.exe}"
COMPOSER_PHAR="${COMPOSER_PHAR:-C:/ProgramData/ComposerSetup/bin/composer.phar}"
STAGE="$ROOT/dist/assessment-app"
ZIP="$ROOT/dist/assessment-app.zip"

cd "$ROOT"
if [ -n "$(git status --porcelain)" ]; then
	echo "Hay cambios sin commit: el paquete se arma desde el último commit. Haga commit primero." >&2
	exit 1
fi

echo "==> Compilando assets (Vite)"
npm run build >/dev/null

echo "==> Copiando el código del último commit"
rm -rf "$ROOT/dist"
mkdir -p "$STAGE"
git archive HEAD | tar -x -C "$STAGE"
cp -r public/build "$STAGE/public/build"
rm -rf "$STAGE/tests" "$STAGE/odd" "$STAGE/scripts" "$STAGE/resources/js" "$STAGE/resources/css" \
	"$STAGE/node_modules" "$STAGE/phpunit.xml" "$STAGE/vite.config.ts" "$STAGE/svelte.config.js" \
	"$STAGE/tsconfig.json" "$STAGE/package.json" "$STAGE/package-lock.json"

echo "==> Instalando dependencias PHP de producción (plataforma PHP 8.3)"
(cd "$STAGE" && "$PHP_BIN" "$COMPOSER_PHAR" install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet)

echo "==> Comprimiendo"
if command -v /c/Windows/System32/tar.exe >/dev/null 2>&1; then
	(cd "$ROOT/dist" && /c/Windows/System32/tar.exe -a -cf assessment-app.zip assessment-app)
else
	(cd "$ROOT/dist" && zip -qr assessment-app.zip assessment-app)
fi

echo "Paquete listo: $ZIP ($(du -h "$ZIP" | cut -f1))"
