#!/usr/bin/env bash
# Télécharge les bibliothèques front aux versions épinglées dans public/assets/vendor (auto-hébergées).
# 1er passage : écrit bin/vendor-assets.lock (SHA-256, à versionner).
# Passages suivants : toute empreinte différente du verrou fait échouer le script (chaîne d'approvisionnement).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/public/assets/vendor"
LOCK="$ROOT/bin/vendor-assets.lock"
NPM="https://cdn.jsdelivr.net/npm"

ASSETS=(
  "jquery/jquery.min.js|$NPM/jquery@3.7.1/dist/jquery.min.js"
  "datatables/dataTables.min.js|$NPM/datatables.net@2.1.8/js/dataTables.min.js"
  "datatables/dataTables.dataTables.min.css|$NPM/datatables.net-dt@2.1.8/css/dataTables.dataTables.min.css"
  "sweetalert2/sweetalert2.min.js|$NPM/sweetalert2@11.14.5/dist/sweetalert2.min.js"
  "sweetalert2/sweetalert2.min.css|$NPM/sweetalert2@11.14.5/dist/sweetalert2.min.css"
  "lucide/lucide.min.js|$NPM/lucide@0.460.0/dist/umd/lucide.min.js"
  "chartjs/chart.umd.js|$NPM/chart.js@4.4.7/dist/chart.umd.js"
  "exceljs/exceljs.min.js|$NPM/exceljs@4.4.0/dist/exceljs.min.js"
  "pdfmake/pdfmake.min.js|$NPM/pdfmake@0.2.14/build/pdfmake.min.js"
  "pdfmake/vfs_fonts.js|$NPM/pdfmake@0.2.14/build/vfs_fonts.js"
  "fonts/ibm-plex-sans-latin-400-normal.woff2|$NPM/@fontsource/ibm-plex-sans@5.1.0/files/ibm-plex-sans-latin-400-normal.woff2"
  "fonts/ibm-plex-sans-latin-500-normal.woff2|$NPM/@fontsource/ibm-plex-sans@5.1.0/files/ibm-plex-sans-latin-500-normal.woff2"
  "fonts/ibm-plex-sans-latin-600-normal.woff2|$NPM/@fontsource/ibm-plex-sans@5.1.0/files/ibm-plex-sans-latin-600-normal.woff2"
)

sums="$(mktemp)"
trap 'rm -f "$sums"' EXIT

for entry in "${ASSETS[@]}"; do
  path="${entry%%|*}"
  url="${entry#*|}"
  mkdir -p "$DEST/$(dirname "$path")"
  curl -fsSL --retry 3 --proto '=https' -o "$DEST/$path" "$url"
  printf '%s  %s\n' "$(sha256sum "$DEST/$path" | cut -d' ' -f1)" "$path" >> "$sums"
  echo "✓ $path"
done

if [[ -f "$LOCK" ]]; then
  if ! diff -u "$LOCK" "$sums"; then
    echo "ERREUR : empreintes différentes de $LOCK — ne pas déployer, vérifier la source." >&2
    exit 1
  fi
  echo "Empreintes conformes au verrou."
else
  cp "$sums" "$LOCK"
  echo "Verrou créé : bin/vendor-assets.lock (à versionner)."
fi
