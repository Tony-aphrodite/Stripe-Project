#!/bin/bash
# Voltika Configurador — Local dev setup
# Run once: bash setup-dev.sh
# Then: php -S localhost:4000

BASE="$(cd "$(dirname "$0")" && pwd)"

echo "Setting up local dev assets..."

# ── jQuery (real file — configurador depends on it) ──────────────
mkdir -p "$BASE/vendors/jquery"
if [ ! -f "$BASE/vendors/jquery/jquery-2.1.4.min.js" ]; then
    curl -s -o "$BASE/vendors/jquery/jquery-2.1.4.min.js" \
        https://code.jquery.com/jquery-2.1.4.min.js
    echo "  Downloaded jQuery"
else
    echo "  jQuery already present"
fi

# ── Bootstrap CSS (real file — used for nav styling) ─────────────
mkdir -p "$BASE/css"
if [ ! -f "$BASE/css/bootstrap.css" ]; then
    curl -s -o "$BASE/css/bootstrap.css" \
        https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css
    echo "  Downloaded Bootstrap CSS"
else
    echo "  Bootstrap CSS already present"
fi

# ── JS stubs (prevent parse errors — not used by configurador) ───
JS_STUBS=(
    "vendors/tether/dist/js/tether.min.js"
    "vendors/bootstrap/js/bootstrap.min.js"
    "vendors/stellar/jquery.stellar.min.js"
    "vendors/isotope/javascripts/isotope.pkgd.min.js"
    "vendors/isotope/javascripts/packery-mode.pkgd.js"
    "vendors/owl-carousel/dist/owl.carousel.js"
    "vendors/waypoint/waypoints.min.js"
    "vendors/counter-up/jquery.counterup.min.js"
    "vendors/fancyBox/source/jquery.fancybox.pack.js"
    "vendors/fancyBox/source/helpers/jquery.fancybox-thumbs.js"
    "vendors/image-stretcher-master/image-stretcher.js"
    "vendors/wow/wow.min.js"
    "vendors/rateyo/jquery.rateyo.js"
    "vendors/bootstrap-datepicker/js/bootstrap-datepicker.js"
    "vendors/bootstrap-slider-master/src/js/bootstrap-slider.js"
    "vendors/bootstrap-select/dist/js/bootstrap-select.min.js"
    "toastr/build/toastr.min.js"
    "js/mega-menu.js"
    "js/jquery.main.js"
    "js/js.js"
    "js/revolution.js"
)
for f in "${JS_STUBS[@]}"; do
    if [ ! -f "$BASE/$f" ]; then
        mkdir -p "$(dirname "$BASE/$f")"
        echo "/* stub */" > "$BASE/$f"
    fi
done
echo "  JS stubs created"

# ── CSS stubs ────────────────────────────────────────────────────
CSS_STUBS=(
    "css/fonts/icomoon/icomoon.css"
    "css/fonts/roxine-font-icon/roxine-font.css"
    "vendors/font-awesome/css/font-awesome.css"
    "vendors/owl-carousel/dist/assets/owl.carousel.min.css"
    "vendors/owl-carousel/dist/assets/owl.theme.default.min.css"
    "vendors/animate/animate.css"
    "css/main.css"
    "css/custom.css"
    "toastr/build/toastr.min.css"
)
for f in "${CSS_STUBS[@]}"; do
    if [ ! -f "$BASE/$f" ]; then
        mkdir -p "$(dirname "$BASE/$f")"
        echo "/* stub */" > "$BASE/$f"
    fi
done
echo "  CSS stubs created"

# ── Image stubs (nav/site images — not needed for configurador) ──
EMPTY_SVG='<svg xmlns="http://www.w3.org/2000/svg"/>'
# 1x1 transparent PNG (base64)
EMPTY_PNG='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='

SVG_STUBS=(
    "img/loader.svg"
    "img/msi_credito_black.svg"
    "img/menu_ciudad.svg"
    "img/menu_m03_tx.svg"
    "img/menu_m05_tx.svg"
    "img/menu_mc10_tx.svg"
    "img/menu_mino_tx.svg"
    "img/menu_pesgo_tx.svg"
    "img/menu_premium.svg"
    "img/menu_movilidad.svg"
    "img/menu_ukko_tx.svg"
)
for f in "${SVG_STUBS[@]}"; do
    if [ ! -f "$BASE/$f" ]; then
        mkdir -p "$(dirname "$BASE/$f")"
        echo "$EMPTY_SVG" > "$BASE/$f"
    fi
done

PNG_STUBS=(
    "img/logo_w.png"
    "img/logo_b.png"
    "img/menu/m03.png"
    "img/menu/m05.png"
    "img/menu/mc10.png"
    "img/menu/mino.png"
    "img/menu/pesgo.png"
    "img/menu/ukko.png"
)
for f in "${PNG_STUBS[@]}"; do
    if [ ! -f "$BASE/$f" ]; then
        mkdir -p "$(dirname "$BASE/$f")"
        echo "$EMPTY_PNG" | base64 -d > "$BASE/$f"
    fi
done
echo "  Image stubs created"

echo ""
echo "Done. Start the server with:"
echo "  php -S localhost:4000"
