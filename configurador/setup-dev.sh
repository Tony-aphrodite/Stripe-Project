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
        https://stackpath.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css
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

echo ""
echo "Done. Start the server with:"
echo "  php -S localhost:4000"
