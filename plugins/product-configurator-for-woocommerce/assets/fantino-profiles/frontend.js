/* Fantino Configurator Profiles — frontend (Phase 3, no-reload + loading + safe reset)
 *
 * Why the old code broke when switching Business -> close -> Premium:
 *   PC.fe.open() at product_configurator.js:350 takes a "same product, just
 *   reopen" branch when product_id === PC.fe.active_product. That branch
 *   does `this.modal.open()` — but PC.fe.modal had been nulled after the
 *   first close, so it threw "Cannot read properties of null (reading 'open')".
 *
 * Fix: before each profile open we force PC.fe.active_product = null so
 * PCW always takes its fresh-modal branch. We also explicitly close + remove
 * any lingering modal, drop PC.productData['prod_<id>'], and clear PCW body
 * classes / saved-config sessionStorage. Then we re-fetch filtered data,
 * install it into PC.productData, and call PC.fe.open with reset=true.
 */
(function ($) {
    'use strict';

    var cfg = window.fantino_pc_frontend || {};
    var productId      = parseInt(cfg.product_id, 10) || 0;
    var paramName      = cfg.param || 'config_profile';
    var sessionKey     = cfg.session_key || ('fantino_pc_profile_' + productId);
    var ajaxUrl        = cfg.ajax_url || '/wp-admin/admin-ajax.php';
    var DEBUG          = !!cfg.debug;
    var loadingText    = cfg.loading_text    || 'Loading your configuration…';
    var loadingIconUrl = cfg.loading_icon_url || '';

    // Public namespace.
    window.FantinoPC = window.FantinoPC || {};
    window.FantinoPC.profiles      = cfg.profiles || {};
    window.FantinoPC.activeProfile = cfg.active_profile || null;

    if (!window.FantinoPC.activeProfile) {
        try {
            var stored = sessionStorage.getItem(sessionKey);
            if (stored) { window.FantinoPC.activeProfile = stored; }
        } catch (e) { /* ignore */ }
    }

    function log() {
        if (!DEBUG && !window.FANTINO_PC_DEBUG) { return; }
        try { console.log.apply(console, ['Fantino PC:'].concat([].slice.call(arguments))); } catch (e) {}
    }

    /* =============================================================
     * 1. Loading-state controller
     * ============================================================= */

    var LOADING_TIMEOUT_MS = 15000;
    var loadingTimer = null;
    var $overlay = null;
    var $clickedBtn = null;
    var clickedBtnOriginalText = null;

    function showLoading($btn) {
        if ($btn && $btn.length) {
            $clickedBtn = $btn;
            clickedBtnOriginalText = $btn.text();
            $btn.addClass('fantino-pc-loading').attr('aria-busy', 'true');
            // Visually replace the label without losing layout.
            $btn.attr('data-fantino-original-text', clickedBtnOriginalText);
        }
        // Disable every other trigger so the user can't kick off two opens.
        $('.fantino-pc-profile-trigger').addClass('fantino-pc-disabled');

        if (!$overlay) {
            var $inner = $('<div class="fantino-pc-overlay__inner"></div>');

            if (loadingIconUrl) {
                $('<img class="fantino-pc-custom-icon" aria-hidden="true" alt="" />')
                    .attr('src', loadingIconUrl)
                    .appendTo($inner);
            } else {
                $('<div class="fantino-pc-spinner" aria-hidden="true"></div>').appendTo($inner);
            }

            $('<div class="fantino-pc-overlay__text"></div>').text(loadingText).appendTo($inner);

            $overlay = $('<div class="fantino-pc-overlay" role="status" aria-live="polite"></div>')
                .append($inner)
                .appendTo(document.body);
        }
        $overlay.addClass('is-visible');

        clearTimeout(loadingTimer);
        loadingTimer = setTimeout(function () {
            log('loading timeout reached — forcing hideLoading');
            hideLoading();
        }, LOADING_TIMEOUT_MS);
    }

    function hideLoading() {
        clearTimeout(loadingTimer);
        loadingTimer = null;
        if ($clickedBtn && $clickedBtn.length) {
            $clickedBtn.removeClass('fantino-pc-loading').removeAttr('aria-busy').removeAttr('data-fantino-original-text');
        }
        $clickedBtn = null;
        clickedBtnOriginalText = null;
        $('.fantino-pc-profile-trigger').removeClass('fantino-pc-disabled');
        if ($overlay) { $overlay.removeClass('is-visible'); }
    }

    /* =============================================================
     * 2. PCW state reset (defensive — checks before touching anything)
     * ============================================================= */

    function safeRemove(view) {
        if (!view) { return; }
        try {
            if (typeof view.remove === 'function')      { view.remove(); }
            else if (typeof view.destroy === 'function'){ view.destroy(); }
        } catch (e) { log('view.remove() threw — ignoring', e); }
    }

    function resetPcwState(pid) {
        log('resetPcwState for pid', pid);
        if (!window.PC) { return; }

        // Close + remove any modal currently held by PCW.
        if (PC.fe) {
            if (PC.fe.opened && typeof PC.fe.close === 'function') {
                try { PC.fe.close(); } catch (e) { log('PC.fe.close() threw', e); }
            }
            if (PC.fe.modal) {
                safeRemove(PC.fe.modal);
                PC.fe.modal = null;
            }
            // Force PCW into its "fresh product" branch on next open() — see line 350-356
            // of product_configurator.js. Without this, PCW calls this.modal.open() on null.
            PC.fe.opened         = false;
            PC.fe.active_product = null;
            PC.fe.parent_product = null;

            // Clear any lazily-cached refs we know about (defensive — properties may not exist).
            ['layers', 'angles', 'contents', 'currentProductData'].forEach(function (k) {
                if (k in PC.fe) {
                    try { delete PC.fe[k]; } catch (e) { PC.fe[k] = null; }
                }
            });
        }

        // Drop cached unfiltered data so a stale snapshot can't bleed into the next open.
        if (PC.productData && PC.productData['prod_' + pid]) {
            try { delete PC.productData['prod_' + pid]; }
            catch (e) { PC.productData['prod_' + pid] = undefined; }
        }

        // Strip body classes set by PC.fe.open().
        try { document.body.classList.remove('configurator_is_opened', 'configurator_is_inline'); }
        catch (e) {}

        // Wipe any per-product saved-configuration in sessionStorage so the new
        // profile doesn't inherit prior selections (which can trigger phantom
        // "X is required" validation errors after a profile switch).
        try {
            sessionStorage.removeItem('mkl_pc_saved_config_' + pid);
            sessionStorage.removeItem('PC_saved_config_' + pid);
        } catch (e) {}

        // Let PCW addons clean themselves up if they listen for this hook.
        if (window.wp && wp.hooks && typeof wp.hooks.doAction === 'function') {
            try { wp.hooks.doAction('PC.fe.reset_product'); } catch (e) {}
        }
    }

    /* =============================================================
     * 3. Wait for PCW frontend API
     * ============================================================= */

    function waitForPcFe(maxAttempts, intervalMs) {
        return new Promise(function (resolve, reject) {
            var n = 0;
            function check() {
                if (window.PC && PC.fe && typeof PC.fe.open === 'function') {
                    resolve();
                    return;
                }
                if (++n >= maxAttempts) {
                    reject(new Error('PC.fe.open not available'));
                    return;
                }
                setTimeout(check, intervalMs);
            }
            check();
        });
    }

    /* =============================================================
     * 4. Open routine
     * ============================================================= */

    function openWithProfile(slug, pid, $trigger) {
        log('opening profile', slug, 'for product', pid);

        // 1. Mark active profile.
        window.FantinoPC.activeProfile = slug;
        try { sessionStorage.setItem('fantino_pc_profile_' + pid, slug); } catch (e) {}

        // 2. Soft URL update — no reload.
        try {
            var url = new URL(window.location.href);
            url.searchParams.set(paramName, slug);
            window.history.replaceState({}, '', url.toString());
        } catch (e) {}

        // 3. Loading UI.
        showLoading($trigger);

        // 4. Reset previous PCW instance/state.
        resetPcwState(pid);

        // 5. Build the AJAX URL — fresh every time, includes config_profile + cache buster.
        var fetchUrl = ajaxUrl
            + (ajaxUrl.indexOf('?') === -1 ? '?' : '&')
            + 'action=pc_get_data&data=init&fe=1&id=' + encodeURIComponent(pid)
            + '&' + paramName + '=' + encodeURIComponent(slug)
            + '&pc-no-transient=1'
            + '&_v=' + Date.now();

        return safeFetchJson(fetchUrl)
            .then(function (data) {
                if (!data || !data.layers) {
                    throw new Error('Profile data missing layers');
                }
                log('profile data loaded', { layers: data.layers.length, content: (data.content || []).length });

                // Install filtered data into the slot PC.fe.open reads at line 367.
                window.PC = window.PC || {};
                PC.productData = PC.productData || {};
                PC.productData['prod_' + pid] = data;

                // Wait for PCW JS to be ready, then call open. Retry once on throw.
                return waitForPcFe(50, 100).then(function () {
                    return attemptOpen(pid, $trigger, false);
                });
            })
            .then(function () {
                hideLoading();
            })
            .catch(function (err) {
                console.error('Fantino PC: failed to open profile "' + slug + '"', err);
                hideLoading();
            });
    }

    function attemptOpen(pid, $trigger, isRetry) {
        return new Promise(function (resolve, reject) {
            try {
                log('PC.fe.open', { pid: pid, retry: isRetry });
                PC.fe.open(pid, pid, $trigger || $('.configure-product[data-product_id="' + pid + '"]').first(), true);
                resolve();
            } catch (err) {
                if (isRetry) {
                    log('PC.fe.open threw on retry — giving up', err);
                    reject(err);
                    return;
                }
                log('PC.fe.open threw — resetting state and retrying once', err);
                resetPcwState(pid);
                setTimeout(function () {
                    attemptOpen(pid, $trigger, true).then(resolve, reject);
                }, 100);
            }
        });
    }

    /* =============================================================
     * 5. Click handler
     * ============================================================= */

    $(document).on('click', '.fantino-pc-profile-trigger', function (e) {
        var $btn = $(this);
        if ($btn.hasClass('fantino-pc-disabled') || $btn.hasClass('fantino-pc-loading')) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        var slug = String($btn.data('pc-profile') || $btn.attr('data-pc-profile') || '').trim();
        // Elementor Button Widget support: detect slug from class fantino-profile-{slug}
        if (!slug) {
            var classes = ($btn.attr('class') || '').split(/\s+/);
            for (var i = 0; i < classes.length; i++) {
                if (classes[i].indexOf('fantino-profile-') === 0) {
                    slug = classes[i].slice('fantino-profile-'.length);
                    break;
                }
            }
        }
        if (!slug) { return; }

        e.preventDefault();
        e.stopPropagation();

        var pid = parseInt(
            $btn.data('product_id') || $btn.attr('data-product_id') || $btn.data('product-id') || productId,
            10
        ) || productId;
        if (!pid) { return; }

        openWithProfile(slug, pid, $btn);
    });

    /* =============================================================
     * 6. Param injection (defense in depth) for any later PCW request
     * ============================================================= */

    if (typeof window.fetch === 'function') {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            try {
                var slug = window.FantinoPC && window.FantinoPC.activeProfile;
                if (slug && typeof input === 'string' && input.indexOf('pc_get_data') !== -1) {
                    if (input.indexOf(paramName + '=') === -1) {
                        var sep = input.indexOf('?') === -1 ? '?' : '&';
                        input += sep + paramName + '=' + encodeURIComponent(slug)
                            + '&pc-no-transient=1';
                    }
                }
            } catch (err) {}
            return origFetch.call(this, input, init);
        };
    }

    if (window.jQuery && jQuery.ajaxPrefilter) {
        jQuery.ajaxPrefilter(function (options) {
            var slug = window.FantinoPC && window.FantinoPC.activeProfile;
            if (!slug || !options || !options.url) { return; }
            var inUrl  = options.url.indexOf('pc_get_data') !== -1;
            var inData = (typeof options.data === 'string' && options.data.indexOf('pc_get_data') !== -1);
            if (!inUrl && !inData) { return; }
            if (options.url.indexOf(paramName + '=') === -1) {
                var sep = options.url.indexOf('?') === -1 ? '?' : '&';
                options.url += sep + paramName + '=' + encodeURIComponent(slug)
                    + '&pc-no-transient=1';
            }
        });
    }

    /* =============================================================
     * 7. Helpers + public API
     * ============================================================= */

    function safeFetchJson(url) {
        if (typeof window.fetch === 'function') {
            return window.fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            });
        }
        return new Promise(function (resolve, reject) {
            $.ajax({ url: url, dataType: 'json', cache: false, success: resolve, error: reject });
        });
    }

    window.FantinoPC.openWithProfile = function (slug, pid) {
        var p = parseInt(pid, 10) || productId;
        var $btn = $('.fantino-pc-profile-trigger[data-pc-profile="' + slug + '"][data-product_id="' + p + '"]').first();
        if (!$btn.length) { $btn = $('.fantino-pc-profile-trigger[data-pc-profile="' + slug + '"]').first(); }
        if (!$btn.length) { $btn = $('.configure-product[data-product_id="' + p + '"]').first(); }
        if (!$btn.length) { $btn = $('.configure-product').first(); }
        return openWithProfile(slug, p, $btn);
    };

    window.FantinoPC.clearProfile = function (pid) {
        var p = parseInt(pid, 10) || productId;
        window.FantinoPC.activeProfile = null;
        try { sessionStorage.removeItem('fantino_pc_profile_' + p); } catch (e) {}
    };

    window.FantinoPC.resetPcwState = function (pid) {
        resetPcwState(parseInt(pid, 10) || productId);
    };
})(jQuery);
