(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.mcws-copy-text');
        if (!button) {
            return;
        }

        var textArea = document.getElementById(button.dataset.copyTarget);
        if (!textArea) {
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textArea.value);
            return;
        }

        textArea.select();
        document.execCommand('copy');
    });
}());
