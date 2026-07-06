/**
 * Supplier Production Dashboard — Supplier JavaScript
 */
(function ($) {
    'use strict';

    // Make entire order row clickable.
    $(document).on('click', '.spd-order-row', function (e) {
        if ($(e.target).is('a')) return; // Let direct link clicks work normally.
        var href = $(this).data('href');
        if (href) window.location.href = href;
    });

    // Update production status via AJAX.
    $('#spd-update-status-btn').on('click', function () {
        var $btn      = $(this);
        var $select   = $('#spd-prod-status-select');
        var $feedback = $('#spd-status-feedback');
        var orderId   = $select.data('order-id');
        var status    = $select.val();

        if (!orderId || !status) return;

        $btn.prop('disabled', true);
        showFeedback($feedback, spdSupplier.i18n.saving, 'saving');

        $.post(spdSupplier.ajaxUrl, {
            action:   'spd_update_status',
            nonce:    spdSupplier.nonce,
            order_id: orderId,
            status:   status
        })
        .done(function (res) {
            if (res.success) {
                showFeedback($feedback, res.data.message, 'success');
            } else {
                showFeedback($feedback, res.data.message || spdSupplier.i18n.error, 'error');
            }
        })
        .fail(function () {
            showFeedback($feedback, spdSupplier.i18n.error, 'error');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Save supplier notes via AJAX.
    $('#spd-save-notes-btn').on('click', function () {
        var $btn      = $(this);
        var $textarea = $('#spd-supplier-notes');
        var $feedback = $('#spd-notes-feedback');
        var orderId   = $textarea.data('order-id');
        var notes     = $textarea.val();

        if (!orderId) return;

        $btn.prop('disabled', true);
        showFeedback($feedback, spdSupplier.i18n.saving, 'saving');

        $.post(spdSupplier.ajaxUrl, {
            action:   'spd_save_notes',
            nonce:    spdSupplier.nonce,
            order_id: orderId,
            notes:    notes
        })
        .done(function (res) {
            if (res.success) {
                showFeedback($feedback, res.data.message, 'success');
            } else {
                showFeedback($feedback, res.data.message || spdSupplier.i18n.error, 'error');
            }
        })
        .fail(function () {
            showFeedback($feedback, spdSupplier.i18n.error, 'error');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    /**
     * Show inline feedback message.
     */
    function showFeedback($el, message, type) {
        $el.text(message)
           .removeClass('spd-feedback--success spd-feedback--error spd-feedback--saving')
           .addClass('spd-feedback--' + type)
           .css('opacity', 1);

        if (type !== 'saving') {
            setTimeout(function () {
                $el.css('opacity', 0);
            }, 3000);
        }
    }

})(jQuery);
