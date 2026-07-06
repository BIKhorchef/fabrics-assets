/**
 * Supplier Production Dashboard — Admin JavaScript
 */
(function ($) {
    'use strict';

    // ── Sortable statuses table ──────────────────────────────
    $('#spd-statuses-body').sortable({
        handle: '.spd-drag-handle',
        placeholder: 'ui-sortable-placeholder',
        axis: 'y',
        opacity: 0.8
    });

    // ── Add status row ───────────────────────────────────────
    $('#spd-add-status').on('click', function () {
        var template = document.getElementById('spd-status-row-template');
        if (!template) return;
        var clone = template.content.cloneNode(true);
        $('#spd-statuses-body').append(clone);
    });

    // ── Add mapping row ──────────────────────────────────────
    $('#spd-add-mapping').on('click', function () {
        var template = document.getElementById('spd-mapping-row-template');
        if (!template) return;
        var clone = template.content.cloneNode(true);
        var index = $('#spd-mappings-body tr').length;
        // Fix the checkbox name index.
        $(clone).find('input[type="checkbox"]').attr('name', 'mapping_visible[' + index + ']');
        $('#spd-mappings-body').append(clone);
    });

    // ── Remove row (statuses or mappings) ────────────────────
    $(document).on('click', '.spd-remove-row', function () {
        if (confirm(spdAdmin.i18n.confirmDelete)) {
            $(this).closest('tr').remove();
            reindexVisibleCheckboxes();
        }
    });

    /**
     * Re-index the visible checkboxes after row removal so PHP receives correct indices.
     */
    function reindexVisibleCheckboxes() {
        $('#spd-mappings-body tr').each(function (i) {
            $(this).find('input[type="checkbox"]').attr('name', 'mapping_visible[' + i + ']');
        });
    }

})(jQuery);
