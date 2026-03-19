(function () {
    var config = window.WPGSStats || {};
    var canTrack = Boolean(config.ajaxUrl && config.action);

    var viewedLists = new Set();
    var copyResetTimers = new WeakMap();

    function sendEvent(eventName, listId) {
        if (!canTrack || !eventName || !listId) {
            return;
        }

        var payload = new URLSearchParams();
        payload.set('action', String(config.action));
        if (config.nonce) {
            payload.set('nonce', String(config.nonce));
        }
        payload.set('event', String(eventName));
        payload.set('list_id', String(listId));

        var body = payload.toString();

        if (navigator.sendBeacon) {
            var blob = new Blob([body], {type: 'application/x-www-form-urlencoded; charset=UTF-8'});
            navigator.sendBeacon(config.ajaxUrl, blob);
            return;
        }

        fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            credentials: 'same-origin',
            keepalive: true,
            body: body
        }).catch(function () {
            return null;
        });
    }

    function trackVisibleLists() {
        if (!canTrack) {
            return;
        }

        var lists = document.querySelectorAll('.wpgs-list[data-list-id]');
        if (!lists.length) {
            return;
        }

        lists.forEach(function (element) {
            var listId = String(element.getAttribute('data-list-id') || '').trim();
            if (!listId || viewedLists.has(listId)) {
                return;
            }

            viewedLists.add(listId);
            sendEvent('view', listId);
        });
    }

    function resetCopyButton(button) {
        if (!button) {
            return;
        }

        button.classList.remove('is-copied', 'is-failed');
        button.textContent = String(button.getAttribute('data-copy-label') || 'Copy');
    }

    function scheduleCopyButtonReset(button) {
        var existingTimer = copyResetTimers.get(button);
        if (existingTimer) {
            clearTimeout(existingTimer);
        }

        var timer = window.setTimeout(function () {
            resetCopyButton(button);
            copyResetTimers.delete(button);
        }, 1600);

        copyResetTimers.set(button, timer);
    }

    function setCopiedState(button) {
        if (!button) {
            return;
        }

        button.classList.add('is-copied');
        button.classList.remove('is-failed');
        button.textContent = String(button.getAttribute('data-copied-label') || 'Copied');
        scheduleCopyButtonReset(button);
    }

    function setFailedState(button) {
        if (!button) {
            return;
        }

        button.classList.add('is-failed');
        button.classList.remove('is-copied');
        button.textContent = String(button.getAttribute('data-failed-label') || 'Failed');
        scheduleCopyButtonReset(button);
    }

    function copyWithFallback(value) {
        var helper = document.createElement('textarea');
        helper.value = value;
        helper.setAttribute('readonly', 'readonly');
        helper.style.position = 'fixed';
        helper.style.top = '-9999px';
        helper.style.opacity = '0';

        document.body.appendChild(helper);
        helper.focus();
        helper.select();

        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }

        document.body.removeChild(helper);
        return copied;
    }

    function copyAddress(button) {
        var value = String(button.getAttribute('data-copy-address') || '').trim();
        if (!value) {
            setFailedState(button);
            return;
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function' && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(function () {
                setCopiedState(button);
            }).catch(function () {
                if (copyWithFallback(value)) {
                    setCopiedState(button);
                    return;
                }

                setFailedState(button);
            });

            return;
        }

        if (copyWithFallback(value)) {
            setCopiedState(button);
            return;
        }

        setFailedState(button);
    }

    document.addEventListener('click', function (event) {
        if (!event.target || typeof event.target.closest !== 'function') {
            return;
        }

        var copyButton = event.target.closest('.wpgs-copy-address');
        if (copyButton) {
            copyAddress(copyButton);
            return;
        }

        var target = event.target.closest('.wpgs-list[data-list-id] .wpgs-table tbody tr, .wpgs-list[data-list-id] .wpgs-card');
        if (!target) {
            return;
        }

        var list = target.closest('.wpgs-list[data-list-id]');
        if (!list) {
            return;
        }

        var listId = String(list.getAttribute('data-list-id') || '').trim();
        if (!listId) {
            return;
        }

        sendEvent('click', listId);
    }, {passive: true});

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            trackVisibleLists();

            document.querySelectorAll('.wpgs-copy-address').forEach(function (button) {
                resetCopyButton(button);
            });
        });
    } else {
        trackVisibleLists();

        document.querySelectorAll('.wpgs-copy-address').forEach(function (button) {
            resetCopyButton(button);
        });
    }
}());
