/* ==========================================================================
   Voltika — Navbar functionality
   Search toggle, side panel, sticky nav, mega menu hover, preloader
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
    var $searchForm  = $('#top-search');
    var $searchInput = $searchForm.find('input');

    $(document).on('click', '.x-search-trigger', function (e) {
        e.preventDefault();
        $searchForm.toggleClass('active');
        if ($searchForm.hasClass('active')) {
            $searchForm.removeAttr('style').show();
            $searchInput.focus();
        } else {
            $searchForm.hide();
        }
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#top-search, .x-search-trigger').length) {
            $searchForm.removeClass('active').hide();
        }
    });

    // ── Side panel ──────────────────────────────────────────────────────────
    var $navWrap = $('.nav-wrap');
    var $overlay = $('<div class="nav-overlay"></div>').appendTo('body');

    function openPanel() {
        $navWrap.addClass('open');
        $overlay.addClass('visible');
        $('body').addClass('panel-open');
    }

    function closePanel() {
        $navWrap.removeClass('open');
        $overlay.removeClass('visible');
        $('body').removeClass('panel-open');
    }

    $(document).on('click', '.overlay-trigger', function (e) {
        e.preventDefault();
        openPanel();
    });

    $(document).on('click', '.nav-trigger-close', function (e) {
        e.preventDefault();
        closePanel();
    });

    $overlay.on('click', function () {
        closePanel();
    });

    // ── Mega menu hover (desktop) ───────────────────────────────────────────
    if ($(window).width() >= 992) {
        $(document).on('mouseenter', '#navbar-inner-container .dropdown', function () {
            $(this).addClass('show').find('> .dropdown-menu').addClass('show');
        }).on('mouseleave', '#navbar-inner-container .dropdown', function () {
            $(this).removeClass('show').find('> .dropdown-menu').removeClass('show');
        });
    }

})(jQuery);
