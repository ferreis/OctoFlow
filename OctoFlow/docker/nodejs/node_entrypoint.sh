#!/bin/bash

set -euo pipefail

cd /home/node/app

SENTINEL_FILE="/home/node/app/naorodar.txt"
STATE_DIR="/home/node/app/.install-state"

mkdir -p "${STATE_DIR}"

run_install() {
  local app_dir="$1"
  local app_name
  local package_json
  local package_lock
  local signature_file
  local current_signature
  local previous_signature

  package_json="${app_dir}/package.json"
  package_lock="${app_dir}/package-lock.json"

  if [ ! -f "${package_json}" ]; then
    return
  fi

  app_name="$(basename "${app_dir}")"
  signature_file="${STATE_DIR}/${app_name}.sha256"
  current_signature="$(
    {
      sha256sum "${package_json}"
      if [ -f "${package_lock}" ]; then
        sha256sum "${package_lock}"
      fi
    } | sha256sum | awk '{print $1}'
  )"
  previous_signature="$(cat "${signature_file}" 2>/dev/null || true)"

  if [ -d "${app_dir}/node_modules" ] && [ "${current_signature}" = "${previous_signature}" ]; then
    echo "[frontend] Dependencias de ${app_dir} ja estao atualizadas"
    return
  fi

  echo "[frontend] Instalando dependencias em ${app_dir}"
  (
    cd "${app_dir}"
    npm install
  )

  printf '%s\n' "${current_signature}" > "${signature_file}"
}

if [ -x /home/node/app/roda.sh ]; then
  /bin/bash /home/node/app/roda.sh
elif [ ! -e "${SENTINEL_FILE}" ]; then
  echo "[frontend] Instalação inicial detectada"
  run_install /home/node/app/Components-app
  run_install /home/node/app/Host-app
  {
    echo "este arquivo e gerado automaticamente"
    echo "as dependencias sao revalidadas automaticamente no proximo up"
  } > "${SENTINEL_FILE}"
else
  echo ""
  echo "Arquivo naorodar.txt existente"
  echo ""
fi

run_install /home/node/app/Components-app
run_install /home/node/app/Host-app

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
