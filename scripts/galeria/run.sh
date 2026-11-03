#!/usr/bin/env bash
# En jorgesv: baja la carpeta «imagenes» del Drive de Publicador y la sube a blondor.cl (site/assets/galeria/).
# Lo ejecuta blondor-galeria.timer cada hora. Manual: sudo systemctl start blondor-galeria.service
set -euo pipefail

BASE=/srv/blondor-galeria
OUT="$BASE/out"
# shellcheck source=/dev/null
. "$BASE/galeria.env"   # GOOGLE_DRIVE_ROOT_FOLDER_ID, GALERIA_FOLDER_NAME, KEY_FILE, SSH_KEY, DEST_HOST, DEST_DIR
mkdir -p "$OUT"

if [ ! -r "$KEY_FILE" ]; then
  echo "Falta la llave de Drive en $KEY_FILE (la misma de Publicador)." >&2
  exit 1
fi

docker run --rm --user "$(id -u):$(id -g)" \
  -v "$KEY_FILE:/run/drive-key.json:ro" \
  -v "$OUT:/out" \
  -e GOOGLE_APPLICATION_CREDENTIALS=/run/drive-key.json \
  -e GOOGLE_DRIVE_ROOT_FOLDER_ID \
  -e GALERIA_FOLDER_NAME \
  blondor-galeria

# El cPanel no tiene rsync: se usa tar por ssh. Orden para que el sitio nunca lea un galeria.json que apunte a fotos
# que aún no llegan o ya se borraron: 1) fotos nuevas, 2) galeria.json, 3) borrar las que ya no están.
remote() { ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$DEST_HOST" "$@"; }

there=$(remote "mkdir -p $DEST_DIR && cd $DEST_DIR && ls -1")
mapfile -t missing < <(cd "$OUT" && ls -1 -- *.webp 2>/dev/null | grep -vxF -f <(printf '%s\n' "$there") || true)

if [ "${#missing[@]}" -gt 0 ]; then
  tar -C "$OUT" -cf - -- "${missing[@]}" | remote "tar -xf - -C $DEST_DIR"
  echo "Subidas ${#missing[@]} fotos."
fi

remote "cat > $DEST_DIR/.galeria.json.tmp && mv $DEST_DIR/.galeria.json.tmp $DEST_DIR/galeria.json" < "$OUT/galeria.json"

(cd "$OUT" && ls -1 -- *.webp 2>/dev/null || true) | remote "cd $DEST_DIR && cat > .keep && for f in *.webp; do [ -e \"\$f\" ] || continue; grep -qxF \"\$f\" .keep || rm -f \"\$f\"; done; rm -f .keep"
echo "Galería publicada en $DEST_HOST:$DEST_DIR"
