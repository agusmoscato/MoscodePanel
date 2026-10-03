#!/usr/bin/env bash
# entorno.sh — Un solo comando para correr TODAS las pruebas del proyecto:
#
#   bash tests/entorno.sh
#
# La primera vez descarga PHP y MariaDB portables (no tocan el sistema, quedan en tests/.tools/, fuera del
# repositorio) y prepara el datadir de MariaDB; las siguientes veces reutiliza todo eso y solo corre las
# pruebas. Si MariaDB ya está corriendo en el puerto configurado (por ejemplo, lo dejaste andando de una
# corrida anterior), lo reutiliza y no lo apaga al terminar; si lo levanta este script, lo apaga al final.
#
# Variables de entorno (todas opcionales, ver tests/soporte.php): MOSCODE_TEST_DB_HOST, MOSCODE_TEST_DB_PORT,
# MOSCODE_TEST_DB_USER, MOSCODE_TEST_DB_PASS, MOSCODE_TEST_DB_NAME.
#
# Requiere: bash, curl, unzip (ya los usa este mismo proyecto). Pensado para Windows (PHP/MariaDB "Win32
# x64"); en Linux/Mac cambiá PHP_ZIP_URL y MARIADB_URL por los paquetes que correspondan, o apuntá
# MOSCODE_TEST_DB_* a un MySQL/MariaDB que ya tengas y corré directamente "php tests/run.php".
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

PHP_VERSION="8.3.35"
PHP_ZIP_URL="https://downloads.php.net/~windows/releases/php-${PHP_VERSION}-nts-Win32-vs16-x64.zip"
MARIADB_VERSION="11.4.5"
MARIADB_URL="https://archive.mariadb.org/mariadb-${MARIADB_VERSION}/winx64-packages/mariadb-${MARIADB_VERSION}-winx64.zip"

TOOLS="tests/.tools"
PHP_DIR="$TOOLS/php"
MARIADB_DIR="$TOOLS/mariadb-${MARIADB_VERSION}-winx64"
DATA_DIR="$TOOLS/mariadb-data"
HOST="${MOSCODE_TEST_DB_HOST:-127.0.0.1}"
PORT="${MOSCODE_TEST_DB_PORT:-33069}"

mkdir -p "$TOOLS"

ruta_windows() { cygpath -w "$1" 2>/dev/null || echo "$1"; }

# --- PHP portable --------------------------------------------------------------------------------------
if [ ! -f "$PHP_DIR/php.exe" ]; then
    echo "== Descargando PHP $PHP_VERSION portable (una sola vez) =="
    curl -sL -o "$TOOLS/php.zip" "$PHP_ZIP_URL"
    mkdir -p "$PHP_DIR"
    unzip -q "$TOOLS/php.zip" -d "$PHP_DIR"
    rm -f "$TOOLS/php.zip"
    cp "$PHP_DIR/php.ini-development" "$PHP_DIR/php.ini"
    sed -i 's/^;extension_dir = "ext"/extension_dir = "ext"/' "$PHP_DIR/php.ini"
    for ext in pdo_mysql mbstring curl openssl sodium mysqli; do
        sed -i "s/^;extension=$ext\$/extension=$ext/" "$PHP_DIR/php.ini"
    done
fi
PHP="$PHP_DIR/php.exe"

# --- MariaDB portable -----------------------------------------------------------------------------------
if [ ! -f "$MARIADB_DIR/bin/mariadbd.exe" ]; then
    echo "== Descargando MariaDB $MARIADB_VERSION portable (una sola vez) =="
    curl -sL -o "$TOOLS/mariadb.zip" "$MARIADB_URL"
    unzip -q "$TOOLS/mariadb.zip" -d "$TOOLS"
    rm -f "$TOOLS/mariadb.zip"
fi

if [ ! -d "$DATA_DIR" ]; then
    echo "== Inicializando el datadir de MariaDB (una sola vez) =="
    "$MARIADB_DIR/bin/mariadb-install-db.exe" --datadir="$(ruta_windows "$(pwd)/$DATA_DIR")" -D
fi

esta_arriba() { "$PHP" -r "exit(@fsockopen('$HOST', $PORT, \$e, \$s, 1) ? 0 : 1);" 2>/dev/null; }

LO_LEVANTAMOS=0
if ! esta_arriba; then
    echo "== Levantando MariaDB portable en $HOST:$PORT =="
    "$MARIADB_DIR/bin/mariadbd.exe" --datadir="$(ruta_windows "$(pwd)/$DATA_DIR")" --port="$PORT" --bind-address="$HOST" \
        --skip-grant-tables --console > "$TOOLS/mariadbd.log" 2>&1 &
    LO_LEVANTAMOS=1
    for _ in $(seq 1 30); do
        esta_arriba && break
        sleep 1
    done
    if ! esta_arriba; then
        echo "MariaDB no respondió a tiempo. Revisá $TOOLS/mariadbd.log" >&2
        exit 1
    fi
else
    echo "== MariaDB ya está corriendo en $HOST:$PORT: lo reutilizo =="
fi

CODIGO=0
"$PHP" tests/run.php || CODIGO=$?

if [ "$LO_LEVANTAMOS" = "1" ]; then
    echo "== Apagando MariaDB =="
    "$MARIADB_DIR/bin/mariadb-admin.exe" -h "$HOST" -P "$PORT" -u root shutdown 2>/dev/null || true
fi

exit $CODIGO
