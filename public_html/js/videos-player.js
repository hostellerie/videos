(function () {
    'use strict';

    var root = document.getElementById('videos-engagement');
    if (!root) {
        return;
    }

    var videoId = root.getAttribute('data-video-id');
    var endpoint = root.getAttribute('data-endpoint');
    var tokenName = root.getAttribute('data-token-name');
    var csrfToken = root.getAttribute('data-token');
    var playbackToken = '';
    var viewSent = false;
    var ratingRequiredSeconds = 0;
    var finalProgressSent = false;
    var startPending = false;
    var player = null;
    var previousReady = window.onYouTubeIframeAPIReady;
    var status = root.querySelector('.videos-rating-status');
    var deleteRatingButton = root.querySelector('[data-delete-rating]');
    var localRatingAverage = document.getElementById(
        'videos-local-rating-average'
    );
    var localRatingCount = document.getElementById(
        'videos-local-rating-count'
    );
    var nextPanel = document.getElementById('videos-next-panel');
    var nextPosition = 0;

    initializeNextPanel();

    function initializePlayer() {
        if (player || typeof window.YT === 'undefined'
                || typeof window.YT.Player === 'undefined') {
            return;
        }
        player = new YT.Player('videos-youtube-player', {
            events: {
                onStateChange: onStateChange
            }
        });
    }

    if (typeof window.YT !== 'undefined'
            && typeof window.YT.Player !== 'undefined') {
        initializePlayer();
    } else {
        window.onYouTubeIframeAPIReady = function () {
            if (typeof previousReady === 'function') {
                previousReady();
            }
            initializePlayer();
        };
        window.setTimeout(initializePlayer, 1500);
    }

    function encode(data) {
        var values = [];
        var key;
        for (key in data) {
            if (Object.prototype.hasOwnProperty.call(data, key)) {
                values.push(
                    encodeURIComponent(key) + '='
                    + encodeURIComponent(String(data[key]))
                );
            }
        }
        return values.join('&');
    }

    function post(action, extra, callback) {
        var request = new XMLHttpRequest();
        var data = extra || {};
        data.videos_action = action;
        data.video_id = videoId;
        data[tokenName] = csrfToken;
        request.open('POST', endpoint, true);
        request.setRequestHeader(
            'Content-Type',
            'application/x-www-form-urlencoded; charset=UTF-8'
        );
        request.onreadystatechange = function () {
            var response;
            if (request.readyState !== 4) {
                return;
            }
            try {
                response = JSON.parse(request.responseText);
            } catch (ignore) {
                response = {
                    success: false,
                    error: 'invalid_json_http_' + request.status
                        + (request.responseText ? '_content' : '_empty')
                };
            }
            if (response.data && response.data.csrf_token) {
                csrfToken = response.data.csrf_token;
            }
            callback(response);
        };
        request.send(encode(data));
    }

    function onStateChange(event) {
        if (event.data === YT.PlayerState.PLAYING && !playbackToken
                && !startPending) {
            startPending = true;
            post('start', {}, function (response) {
                startPending = false;
                if (response.success) {
                    playbackToken = response.data.playback_token;
                    if (deleteRatingButton
                            && parseInt(root.getAttribute(
                                'data-current-rating'
                            ), 10) > 0) {
                        deleteRatingButton.disabled = false;
                    }
                    if (status) {
                        status.className = 'videos-rating-status';
                        status.innerHTML = root.getAttribute(
                            'data-rating-waiting'
                        );
                    }
                    window.setTimeout(checkProgress, 2000);
                } else if (status) {
                    status.className = 'videos-rating-status is-error';
                    status.innerHTML = root.getAttribute(
                        'data-playback-error'
                    ) + ' (' + response.error + ')';
                }
            });
        }
        if (event.data === YT.PlayerState.ENDED) {
            showNextPanel();
            if (viewSent && playbackToken && !finalProgressSent) {
                sendFinalProgress();
            }
        }
    }

    function initializeNextPanel() {
        var button;
        var links;
        var i;
        if (!nextPanel) {
            return;
        }
        links = nextPanel.getElementsByTagName('a');
        for (i = 0; i < links.length; i += 1) {
            attachRecommendationLink(links[i]);
        }
        button = nextPanel.querySelector('[data-next-other]');
        if (button) {
            button.onclick = function () {
                var items = nextPanel.querySelectorAll('[data-next-item]');
                var targetVideoId;
                var proof;
                if (!items.length) {
                    return;
                }
                targetVideoId = items[nextPosition].getAttribute(
                    'data-next-video'
                );
                proof = items[nextPosition].getAttribute('data-next-proof');
                recordRecommendation(
                    'skipped',
                    targetVideoId,
                    proof,
                    function () {}
                );
                items[nextPosition].className = 'videos-next-item';
                nextPosition = (nextPosition + 1) % items.length;
                items[nextPosition].className =
                    'videos-next-item is-active';
            };
        }
    }

    function attachRecommendationLink(link) {
        link.onclick = function (event) {
            var item = link;
            var targetVideoId;
            var proof;
            var destination = link.href;
            var navigated = false;
            while (item && item !== nextPanel
                    && !item.getAttribute('data-next-video')) {
                item = item.parentNode;
            }
            targetVideoId = item && item !== nextPanel
                ? item.getAttribute('data-next-video') : '';
            proof = item && item !== nextPanel
                ? item.getAttribute('data-next-proof') : '';
            if (!playbackToken || !targetVideoId) {
                return true;
            }
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            function navigate() {
                if (!navigated) {
                    navigated = true;
                    window.location.href = destination;
                }
            }
            recordRecommendation('accepted', targetVideoId, proof, navigate);
            window.setTimeout(navigate, 800);
            return false;
        };
    }

    function recordRecommendation(signal, targetVideoId, proof, callback) {
        if (!playbackToken || !targetVideoId || !proof) {
            callback();
            return;
        }
        post('recommendation', {
            playback_token: playbackToken,
            elapsed: player && typeof player.getCurrentTime === 'function'
                ? Math.floor(player.getCurrentTime() || 0) : 0,
            target_video_id: targetVideoId,
            recommendation_proof: proof,
            signal: signal
        }, function () {
            callback();
        });
    }

    function showNextPanel() {
        if (!nextPanel) {
            return;
        }
        if (nextPanel.className.indexOf('is-highlighted') === -1) {
            nextPanel.className += ' is-highlighted';
        }
        if (typeof nextPanel.scrollIntoView === 'function') {
            nextPanel.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }
    }

    function sendFinalProgress() {
        var elapsed = player && typeof player.getCurrentTime === 'function'
            ? Math.floor(player.getCurrentTime() || 0) : 0;
        finalProgressSent = true;
        post('progress', {
            playback_token: playbackToken,
            elapsed: elapsed,
            completed: 1
        }, function (response) {
            if (!response.success) {
                finalProgressSent = false;
            }
        });
    }

    function checkProgress() {
        var elapsed;
        if (!playbackToken || viewSent) {
            return;
        }
        if (!player || typeof player.getCurrentTime !== 'function') {
            return;
        }
        elapsed = Math.floor(player.getCurrentTime() || 0);
        post('view', {
            playback_token: playbackToken,
            elapsed: elapsed
        }, function (response) {
            var retry;
            var countdown;
            if (response.success && response.data.view_recorded) {
                viewSent = true;
                ratingRequiredSeconds = parseInt(
                    response.data.rating_required_seconds,
                    10
                ) || 0;
                if (response.data.rating_enabled) {
                    root.className += ' videos-rating-ready';
                    enableRatingButtons();
                } else if (ratingRequiredSeconds > 0) {
                    window.setTimeout(checkRatingReadiness, 1000);
                }
            } else if (response.success) {
                retry = parseInt(response.data.retry_after, 10) || 5;
                countdown = root.getAttribute('data-rating-countdown');
                ratingRequiredSeconds = parseInt(
                    response.data.rating_required_seconds,
                    10
                ) || 0;
                if (status && countdown && ratingRequiredSeconds > 0) {
                    status.className = 'videos-rating-status';
                    status.innerHTML = countdown.replace(
                        '%d',
                        parseInt(
                            response.data.rating_remaining_seconds,
                            10
                        ) || retry
                    );
                }
                window.setTimeout(checkProgress, retry * 1000);
            } else if (status) {
                status.className = 'videos-rating-status is-error';
                if (response.error === 'unexpected_shutdown'
                        && response.data && response.data.stage) {
                    status.innerHTML = root.getAttribute('data-rating-error')
                        + ' (unexpected_shutdown_'
                        + response.data.stage + '_'
                        + response.data.php_error_type + ')';
                } else {
                    status.innerHTML = root.getAttribute('data-rating-error')
                        + ' (' + response.error + ')';
                }
            }
        });
    }

    function checkRatingReadiness() {
        var elapsed;
        var remaining;
        var countdown;
        if (!player || typeof player.getCurrentTime !== 'function'
                || ratingRequiredSeconds <= 0) {
            return;
        }
        elapsed = Math.floor(player.getCurrentTime() || 0);
        remaining = ratingRequiredSeconds - elapsed;
        if (remaining <= 0) {
            enableRatingButtons();
            return;
        }
        countdown = root.getAttribute('data-rating-countdown');
        if (status && countdown) {
            status.className = 'videos-rating-status';
            status.innerHTML = countdown.replace('%d', remaining);
        }
        window.setTimeout(checkRatingReadiness, 1000);
    }

    function enableRatingButtons() {
        var buttons = root.querySelectorAll('[data-rating]');
        var i;
        for (i = 0; i < buttons.length; i += 1) {
            buttons[i].disabled = false;
        }
        if (status) {
            status.className = 'videos-rating-status';
            status.innerHTML = '';
        }
    }

    root.onclick = function (event) {
        var target = event.target || event.srcElement;
        var rating;
        if (target && target.getAttribute('data-delete-rating') !== null) {
            deleteCurrentRating(target);
            return;
        }
        if (!target || target.getAttribute('data-rating') === null
                || !playbackToken) {
            return;
        }
        rating = parseInt(target.getAttribute('data-rating'), 10);
        post('rate', {
            playback_token: playbackToken,
            elapsed: player && typeof player.getCurrentTime === 'function'
                ? Math.floor(player.getCurrentTime() || 0) : 0,
            rating: rating
        }, function (response) {
            var buttons = root.querySelectorAll('[data-rating]');
            var average;
            var i;
            if (response.success) {
                root.setAttribute('data-current-rating', rating);
                if (deleteRatingButton) {
                    deleteRatingButton.hidden = false;
                    deleteRatingButton.disabled = false;
                }
                for (i = 0; i < buttons.length; i += 1) {
                    if (parseInt(buttons[i].getAttribute('data-rating'), 10)
                            <= rating) {
                        buttons[i].className = 'is-selected';
                        buttons[i].setAttribute('aria-pressed', 'true');
                    } else {
                        buttons[i].className = '';
                        buttons[i].setAttribute('aria-pressed', 'false');
                    }
                }
                if (localRatingAverage) {
                    average = parseFloat(response.data.rating_average) || 0;
                    localRatingAverage.innerHTML = average.toFixed(2)
                        .replace('.', ',');
                }
                if (localRatingCount) {
                    localRatingCount.innerHTML = parseInt(
                        response.data.rating_count,
                        10
                    ) || 0;
                }
                if (status) {
                    status.className = 'videos-rating-status is-success';
                    status.innerHTML = root.getAttribute('data-rating-saved');
                }
            } else if (status) {
                status.className = 'videos-rating-status is-error';
                status.innerHTML = root.getAttribute('data-rating-error');
            }
        });
    };

    function deleteCurrentRating(button) {
        var confirmation = root.getAttribute('data-rating-delete-confirm');
        if (!playbackToken || button.disabled
                || (confirmation && !window.confirm(confirmation))) {
            return;
        }
        button.disabled = true;
        post('delete_rating', {
            playback_token: playbackToken,
            elapsed: player && typeof player.getCurrentTime === 'function'
                ? Math.floor(player.getCurrentTime() || 0) : 0
        }, function (response) {
            var buttons = root.querySelectorAll('[data-rating]');
            var average;
            var i;
            if (response.success) {
                root.setAttribute('data-current-rating', '0');
                for (i = 0; i < buttons.length; i += 1) {
                    buttons[i].className = '';
                    buttons[i].setAttribute('aria-pressed', 'false');
                }
                button.hidden = true;
                if (localRatingAverage) {
                    average = parseFloat(response.data.rating_average) || 0;
                    localRatingAverage.innerHTML = average.toFixed(2)
                        .replace('.', ',');
                }
                if (localRatingCount) {
                    localRatingCount.innerHTML = parseInt(
                        response.data.rating_count,
                        10
                    ) || 0;
                }
                if (status) {
                    status.className = 'videos-rating-status is-success';
                    status.innerHTML = root.getAttribute(
                        'data-rating-deleted'
                    );
                }
            } else {
                button.disabled = false;
                if (status) {
                    status.className = 'videos-rating-status is-error';
                    status.innerHTML = root.getAttribute(
                        'data-rating-delete-error'
                    ) + ' (' + response.error + ')';
                }
            }
        });
    }
}());
