#!/usr/bin/env bash
# Desde WSL: instala o actualiza el sincronizador de la galería en jorgesv.
#   scripts/galeria/install_jorgesv.sh
# Deja /srv/blondor-galeria (código, galeria.env, out/), construye la imagen, activa el timer y autoriza la llave
# de jorgesv en el cPanel. No ejecuta la sincronización.
set -euo pipefail
cd "$(dirname "$0")"

ssh -o BatchMode=yes jorgesv 'sudo install -d -o jorge -g jorge /srv/blondor-galeria /srv/blondor-galeria/src /srv/blondor-galeria/out'
rsync -a --delete --exclude=node_modules --exclude='*.test.mjs' ./ jorgesv:/srv/blondor-galeria/src/

ssh -o BatchMode=yes jorgesv 'set -euo pipefail
cd /srv/blondor-galeria
install -m 0755 src/run.sh run.sh
if [ ! -f galeria.env ]; then
  root=$(sudo sed -n "s/^GOOGLE_DRIVE_ROOT_FOLDER_ID=//p" /srv/publicador/app/.env)
  cat > galeria.env <<ENV
GOOGLE_DRIVE_ROOT_FOLDER_ID=$root
GALERIA_FOLDER_NAME=imagenes
KEY_FILE=/srv/publicador/secrets/google-drive-service-account.json
SSH_KEY=/home/jorge/.ssh/id_ed25519_blondor_galeria
DEST_HOST=cbl116984@srv28.cpanelhost.cl
DEST_DIR=blondor/site/assets/galeria
ENV
  chmod 0600 galeria.env
fi
[ -f ~/.ssh/id_ed25519_blondor_galeria ] || ssh-keygen -q -t ed25519 -N "" -C "jorgesv-galeria" -f ~/.ssh/id_ed25519_blondor_galeria
docker build -q -t blondor-galeria src >/dev/null
sudo install -m 0644 src/blondor-galeria.service src/blondor-galeria.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now blondor-galeria.timer
'

# La llave de jorgesv entra a ~/.ssh/authorized_keys del cPanel (una sola vez).
pub=$(ssh -o BatchMode=yes jorgesv 'cat ~/.ssh/id_ed25519_blondor_galeria.pub')
ssh -o BatchMode=yes blondor.web "mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && (grep -qxF '$pub' ~/.ssh/authorized_keys || echo '$pub' >> ~/.ssh/authorized_keys)"
echo "Listo. Primera corrida: ssh jorgesv sudo systemctl start blondor-galeria.service"
