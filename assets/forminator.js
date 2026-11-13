/* Attachments must never prevent an otherwise valid written submission. */
(function ($) {
    'use strict';
    var config = window.WPClamAVForminator || { forms: [] };
    function protect(form) {
        var id = Number($(form).find('input[name="form_id"]').val() || String(form.id || '').replace('forminator-module-', ''));
        return id > 0 && (!config.forms.length || config.forms.indexOf(id) !== -1);
    }
    function adjust(root) {
        $(root).find('form.forminator-custom-form, form[id^="forminator-module-"]').addBack('form.forminator-custom-form').each(function () {
            if (!protect(this)) { return; }
            var form = this, validator = $(form).data('validator');
            $(form).find('input[type="file"]').each(function () {
                this.removeAttribute('required');
                $(this).removeClass('forminator-input-file-required');
                if (validator && validator.settings.rules[this.name]) {
                    delete validator.settings.rules[this.name].required;
                    delete validator.settings.rules[this.name].multiFileValid;
                    delete validator.settings.rules[this.name].extension;
                }
            });
        });
    }
    $(function () { adjust(document); });
    $(document).on('after.load.forminator', function () { adjust(document); });
    $(document).on('before:forminator:multiple:upload', function (event, data) {
        var form = event.target.closest && event.target.closest('form'),
            input = event.target.querySelector && event.target.querySelector('input[type="file"]');
        if (form && protect(form) && input && data instanceof FormData) {
            data.set('wp_clamav_field', input.name.replace(/\[\]$/, ''));
        }
    });
    document.addEventListener('submit', function (event) {
        if (event.target instanceof HTMLFormElement && protect(event.target)) { adjust(event.target); }
    }, true);
    document.addEventListener('click', function (event) {
        var form = event.target.closest && event.target.closest('form.forminator-custom-form');
        if (form && protect(form)) { adjust(form); }
    }, true);
})(jQuery);
