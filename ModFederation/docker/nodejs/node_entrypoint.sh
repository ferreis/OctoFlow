#!/bin/bash

set -euo pipefail

cd /home/node/app

SENTINEL_FILE="/home/node/app/naorodar.txt"

run_install() {
  local app_dir="$1"

  if [ ! -f "${app_dir}/package.json" ]; then
    return
  fi

  echo "[frontend] Instalando dependencias em ${app_dir}"
  (
    cd "${app_dir}"
    npm install
  )
}

if [ -x /home/node/app/roda.sh ]; then
  /bin/bash /home/node/app/roda.sh
elif [ ! -e "${SENTINEL_FILE}" ]; then
  echo "[frontend] Instalacao inicial detectada"
  run_install /home/node/app/Components-app
  run_install /home/node/app/Host-app
  {
    echo "este arquivo e gerado automaticamente"
    echo "ao apagar este arquivo, o npm install ira rodar no proximo up"
  } > "${SENTINEL_FILE}"
else
  echo ""
  echo "Arquivo naorodar.txt existente"
  echo ""
fi

if [ -x /home/node/app/up_node.sh ]; then
  exec /bin/bash /home/node/app/up_node.sh
fi

echo "[frontend] Iniciando OctoFlow components (5175 preview) e OctoFlow host (5173 dev)"

trap 'kill 0' SIGINT SIGTERM EXIT

(
  cd /home/node/app/Components-app
  npm run build
  npm run preview -- --host 0.0.0.0 --port 5175 --strictPort
) &

(
  cd /home/node/app/Host-app
  npm run dev -- --host 0.0.0.0 --port 5173 --strictPort
) &

wait -n
