/* LearnCAT User Import — Admin JS */
(function ($) {
    'use strict';

    $(function () {

        // Toggle column reference table
        $('.lcui-toggle-ref').on('click', function () {
            $('#lcui-col-ref').slideToggle(200);
        });

        // Warn before large imports
        $('#lcui-import-form').on('submit', function (e) {
            var file = document.getElementById('lcui_csv').files[0];
            var isDry = $('input[name="lcui_dry_run"]').is(':checked');

            if (!file) return;

            if (!isDry && file.size > 1024 * 1024) {
                if (!confirm('This file is larger than 1 MB. Large imports may take a while. Continue?')) {
                    e.preventDefault();
                    return;
                }
            }

            $('input[name="lcui_submit"]').val('Importing…').prop('disabled', true);
        });

        // Auto-expand results section if present
        if ($('.lcui-results').length) {
            $('html, body').animate({ scrollTop: $('.lcui-results').offset().top - 40 }, 400);
        }

    });

}(jQuery));
