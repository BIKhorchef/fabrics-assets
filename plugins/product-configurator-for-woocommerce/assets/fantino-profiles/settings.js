/* Fantino Configurator Profiles — media uploader for the loader icon field */
(function ($) {
    'use strict';

    var frame;
    var i18n = window.fantino_pc_settings_i18n || {};

    $('#fantino-pc-upload-icon').on('click', function (e) {
        e.preventDefault();

        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title:    i18n.select_title  || 'Select Loading Icon',
            button:   { text: i18n.select_button || 'Use this image' },
            multiple: false,
            library:  { type: ['image'] }
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#fantino_loading_icon_id').val(attachment.id);
            $('#fantino_loading_icon_url').val(attachment.url);

            var $preview = $('#fantino-pc-icon-preview');
            $preview.empty().append(
                $('<img alt="" />').attr('src', attachment.url)
            ).show();

            $('#fantino-pc-remove-icon').show();
        });

        frame.open();
    });

    $('#fantino-pc-remove-icon').on('click', function (e) {
        e.preventDefault();
        $('#fantino_loading_icon_id').val('');
        $('#fantino_loading_icon_url').val('');
        $('#fantino-pc-icon-preview').hide().empty();
        $(this).hide();
    });

})(jQuery);
