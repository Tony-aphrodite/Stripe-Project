/* ==========================================================================
   Voltika — Navbar functionality
   Preloader · Sticky nav · Search toggle · Side panel · Mega menu hover
   Matches production at voltika.mx — uses body.nav-active + #wrapper:before
   ========================================================================== */

(function ($) {
    'use strict';

    // ── Preloader ───────────────────────────────────────────────────────────
    $(window).on('load', function () {
        $('#pageLoad').fadeOut(400);
    });

    // ── Sticky nav on scroll ────────────────────────────────────────────────
    var $header = $('.main-header');
    $(window).on('scroll', function () {
        if ($(this).scrollTop() > 10) {
            $header.addClass('sticky-nav');
        } else {
            $header.removeClass('sticky-nav');
        }
    });

    // ── Search toggle ───────────────────────────────────────────────────────
    // #top-search is hidden via CSS (display:none) — .show()/.hide() override it
    var $searchForm  = $('#top-search');
    var $searchInput = $searchForm.find('input');

    $(document).on('click', '.x-search-trigger', function (e) {
        e.preventDefault();
        $searchForm.toggleClass('active');
        if ($searchForm.hasClass('active')) {
            $searchForm.show();
            $searchInput.focus();
        } else {
            $searchForm.hide();
        }
    });

    // Close search on outside click
    $(document).on('click', function (e) {
        if (!$(e.target).closest('#top-search, .x-search-trigger').length) {
            $searchForm.removeClass('active').hide();
        }
    });

    // ── Side panel ──────────────────────────────────────────────────────────
    // Production approach: toggle body.nav-active
    // CSS handles the transform animation and #wrapper:before overlay
    var $body = $('body');

    function openPanel() {
        $body.addClass('nav-active');
    }

    function closePanel() {
        $body.removeClass('nav-active');
    }

    // Hamburger icon opens the panel
    $(document).on('click', '.overlay-trigger', function (e) {
        e.preventDefault();
        openPanel();
    });

    // "Cerrar panel" button closes it
    $(document).on('click', '.nav-trigger-close', function (e) {
        e.preventDefault();
        closePanel();
    });

    // Click on the overlay (#wrapper:before) closes the panel.
    // Pseudo-elements can't receive events directly, so we listen on #wrapper
    // but only fire when the click target is NOT inside .nav-wrap.
    $('#wrapper').on('click', function (e) {
        if ($body.hasClass('nav-active') && !$(e.target).closest('.nav-wrap').length) {
            closePanel();
        }
    });

    // ── Mega menu hover (desktop ≥992px) ────────────────────────────────────
    if ($(window).width() >= 992) {
        $(document).on('mouseenter', '#navbar-inner-container .dropdown', function () {
            $(this).addClass('show').find('> .dropdown-menu').addClass('show');
        }).on('mouseleave', '#navbar-inner-container .dropdown', function () {
            $(this).removeClass('show').find('> .dropdown-menu').removeClass('show');
        });
    }

})(jQuery);
