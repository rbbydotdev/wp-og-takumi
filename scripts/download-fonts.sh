#!/bin/sh
# Download static Google Font TTFs via the Google Fonts CSS API.
# Uses a legacy user-agent to get TTF (not WOFF2) format.
# Run from the fonts/ directory.
set -e

UA="Mozilla/4.0"

download_family() {
    family="$1"
    safe_name=$(echo "$family" | tr -d ' ')

    echo "Downloading ${family}..."

    css=$(curl -fsSL -H "User-Agent: $UA" \
        "https://fonts.googleapis.com/css2?family=$(echo "$family" | tr ' ' '+'):wght@400;600;700")

    echo "$css" | grep -o 'https://[^)]*\.ttf' | while IFS= read -r url; do
        # Extract weight from the @font-face block preceding this URL
        weight=$(echo "$css" | grep -B5 "$url" | grep 'font-weight' | grep -o '[0-9]*')

        case "$weight" in
            400) label="Regular" ;;
            600) label="SemiBold" ;;
            700) label="Bold" ;;
            *)   label="w${weight}" ;;
        esac

        outfile="${safe_name}-${label}.ttf"
        echo "  -> ${outfile}"
        curl -fsSL -o "$outfile" "$url"
    done
}

download_family "Playfair Display"
download_family "Source Sans 3"

echo ""
echo "Done."
ls -la *.ttf
