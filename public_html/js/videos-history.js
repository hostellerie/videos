(function () {
    'use strict';

    var root = document.querySelector('.videos-history');
    if (!root) {
        return;
    }
    var buttons = root.querySelectorAll('[data-filter]');
    var cards = root.querySelectorAll('.videos-history-card');
    var empty = root.querySelector('.videos-history-empty');

    function applyFilter(filter) {
        var visible = 0;
        var i;
        for (i = 0; i < cards.length; i += 1) {
            if (filter === 'all'
                    || (filter === 'watched'
                        && cards[i].className.indexOf('is-watched') !== -1)
                    || (filter === 'rated'
                        && cards[i].className.indexOf('is-rated') !== -1)) {
                cards[i].style.display = '';
                visible += 1;
            } else {
                cards[i].style.display = 'none';
            }
        }
        if (empty) {
            empty.hidden = visible !== 0;
        }
    }

    root.onclick = function (event) {
        var target = event.target || event.srcElement;
        var filter;
        var i;
        if (!target || target.getAttribute('data-filter') === null) {
            return;
        }
        filter = target.getAttribute('data-filter');
        for (i = 0; i < buttons.length; i += 1) {
            buttons[i].className = buttons[i] === target ? 'is-active' : '';
            buttons[i].setAttribute(
                'aria-pressed',
                buttons[i] === target ? 'true' : 'false'
            );
        }
        applyFilter(filter);
    };
}());

