#!/usr/bin/env bash
# Arma un video corto «antes y después» para la galería de blondor.cl a partir de un video de iPhone (4K, HDR, HEVC):
#   <nombre>.mp4   montaje vertical de 720 px, H.264 sin audio (se reproduce solo y en loop en la grilla), SDR
#   <nombre>.webp  imagen fija (para quien tiene ahorro de datos o movimiento reducido)
#
# Uso: scripts/galeria/video.sh <original> <carpeta salida> <nombre> <seg_imagen_fija> <tramo> [<tramo> ...]
#   tramo = inicio:duración:velocidad:ETIQUETA   (segundos del original; velocidad 1 = normal, 8 = 8 veces más rápido)
#   ej.: scripts/galeria/video.sh original.mp4 site/assets/galeria/video brushing 118 \
#          11.5:4.5:1:ANTES 16:84:8:BRUSHING 100:4:1:DESPUÉS 112:11:1:DESPUÉS
# Requiere Docker (imagen jrottenberg/ffmpeg).
set -euo pipefail

IN=$(realpath "$1"); OUT=$(realpath -m "$2"); NAME=$3; POSTER=$4; shift 4
[ $# -ge 1 ] || { echo "Falta al menos un tramo inicio:duración:velocidad:ETIQUETA." >&2; exit 2; }
mkdir -p "$OUT"

FONT=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf
IMG=jrottenberg/ffmpeg:7.1-ubuntu2404
ff() {
  docker run --rm --user "$(id -u):$(id -g)" -v "$(dirname "$IN"):/in:ro" -v "$OUT:/out" -v "$FONT:/font.ttf:ro" \
    "$IMG" -hide_banner -loglevel error -y "$@" 2>&1 | grep -v Fontconfig || true
}
SRC="/in/$(basename "$IN")"

# HDR (HLG/PQ) -> SDR bt709. Sin esto los colores del iPhone se ven lavados en el navegador.
SDR="zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,format=yuv420p"
# Etiqueta abajo a la izquierda, sobre el área segura de la grilla.
label() {
  printf "drawtext=fontfile=/font.ttf:text='%s':fontsize=30:fontcolor=white:box=1:boxcolor=0x0a3c66@0.85:boxborderw=14|22:x=36:y=h-th-150" "$1"
}

inputs=(); chains=""; i=0
for seg in "$@"; do
  IFS=: read -r start dur speed text <<<"$seg"
  inputs+=(-ss "$start" -t "$dur" -i "$SRC")
  chains+="[$i:v:0]$SDR,scale=720:-2,setsar=1,setpts=(PTS-STARTPTS)/$speed,fps=30,$(label "$text")[v$i];"
  i=$((i + 1))
done
concat=""; for ((j = 0; j < i; j++)); do concat+="[v$j]"; done

echo "Montaje..."
ff "${inputs[@]}" -filter_complex "${chains}${concat}concat=n=$i:v=1:a=0[v]" -map "[v]" -an \
  -c:v libx264 -preset slow -crf 29 -profile:v high -pix_fmt yuv420p -movflags +faststart "/out/.$NAME.mp4"

# El iPhone marca el video con «girar -90°»; los píxeles ya salen verticales, así que la marca se anula sin recodificar
# (si no, los navegadores lo giran por segunda vez).
ff -display_rotation:v:0 0 -i "/out/.$NAME.mp4" -c copy -movflags +faststart "/out/$NAME.mp4"
rm -f "$OUT/.$NAME.mp4"

echo "Imagen fija..."
ff -ss "$POSTER" -i "$SRC" -map 0:v:0 -frames:v 1 -vf "$SDR,scale=720:-2" -c:v libwebp -quality 80 "/out/$NAME.webp"

ls -la "$OUT"
