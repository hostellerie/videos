(function () {
    'use strict';

    var root = document.querySelector('.videos-share');
    if (!root) {
        return;
    }

    var url = root.getAttribute('data-url');
    var title = root.getAttribute('data-title');
    var status = root.querySelector('.videos-share-status');
    var copyButton = root.querySelector('.videos-copy-link');
    var nativeButton = root.querySelector('.videos-native-share');

    function showCopied() {
        if (status) {
            status.innerHTML = root.getAttribute('data-copied');
        }
    }

    function legacyCopy() {
        var input = document.createElement('textarea');
        input.value = url;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'absolute';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        input.select();
        try {
            document.execCommand('copy');
            showCopied();
        } catch (ignore) {
            window.prompt('Copier le lien', url);
        }
        document.body.removeChild(input);
    }

    if (copyButton) {
        copyButton.onclick = function () {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(showCopied, legacyCopy);
            } else {
                legacyCopy();
            }
        };
    }

    if (nativeButton) {
        if (!navigator.share) {
            nativeButton.style.display = 'none';
        } else {
            nativeButton.onclick = function () {
                navigator.share({title: title, url: url});
            };
        }
    }
}());

