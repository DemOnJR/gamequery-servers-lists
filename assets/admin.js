(function () {
    var config = window.WPGSConnect || {};
    if (!config.ajaxUrl || !config.nonce) {
        return;
    }

    var button = document.getElementById('wpgs-connect-button');
    var statusNode = document.getElementById('wpgs-connect-status');
    var spinner = document.getElementById('wpgs-connect-spinner');

    if (!button || !statusNode) {
        return;
    }

    var activeSessionId = '';
    var popupWindow = null;
    var pollTimerId = 0;
    var pollInFlight = false;
    var isCompleted = false;

    function textMessage(key, fallback) {
        if (config.messages && config.messages[key]) {
            return String(config.messages[key]);
        }

        return fallback;
    }

    function setStatus(message, tone) {
        statusNode.textContent = String(message || '');
        statusNode.classList.remove('is-info', 'is-success', 'is-error');

        if (tone === 'success') {
            statusNode.classList.add('is-success');
            return;
        }

        if (tone === 'error') {
            statusNode.classList.add('is-error');
            return;
        }

        statusNode.classList.add('is-info');
    }

    function setBusy(isBusy) {
        button.disabled = Boolean(isBusy);

        if (!spinner) {
            return;
        }

        if (isBusy) {
            spinner.classList.add('is-active');
            return;
        }

        spinner.classList.remove('is-active');
    }

    function clearPolling() {
        if (pollTimerId) {
            window.clearInterval(pollTimerId);
            pollTimerId = 0;
        }
    }

    function safeClosePopup() {
        if (popupWindow && !popupWindow.closed) {
            popupWindow.close();
        }
    }

    function encodePayload(data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (key) {
            if (data[key] === undefined || data[key] === null) {
                return;
            }

            body.set(key, String(data[key]));
        });

        return body.toString();
    }

    function parseErrorMessage(payload, fallbackMessage) {
        if (!payload || typeof payload !== 'object') {
            return fallbackMessage;
        }

        if (payload.data && payload.data.message) {
            return String(payload.data.message);
        }

        if (payload.message) {
            return String(payload.message);
        }

        return fallbackMessage;
    }

    function postAjax(data) {
        return fetch(config.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            credentials: 'same-origin',
            body: encodePayload(data)
        }).then(function (response) {
            return response.json().then(function (json) {
                return {
                    ok: response.ok,
                    payload: json
                };
            }).catch(function () {
                return {
                    ok: false,
                    payload: {
                        success: false,
                        data: {
                            message: textMessage('failed', 'Connection failed. Please try again.')
                        }
                    }
                };
            });
        });
    }

    function finishFlowWithError(message) {
        clearPolling();
        setBusy(false);
        setStatus(message || textMessage('failed', 'Connection failed. Please try again.'), 'error');
        activeSessionId = '';
        pollInFlight = false;
    }

    function finishFlowWithSuccess(message) {
        clearPolling();
        setBusy(false);
        setStatus(message || textMessage('connected', 'Connected successfully. Reloading settings...'), 'success');
        isCompleted = true;
        safeClosePopup();

        var reloadDelay = Number.parseInt(config.reloadDelayMs, 10);
        if (!Number.isFinite(reloadDelay) || reloadDelay < 300) {
            reloadDelay = 1200;
        }

        window.setTimeout(function () {
            window.location.reload();
        }, reloadDelay);
    }

    function handlePollStatus(data) {
        var status = data && data.status ? String(data.status).toLowerCase() : '';

        if (status === 'pending') {
            if (popupWindow && popupWindow.closed) {
                finishFlowWithError(textMessage('closed', 'Connection window was closed before completion.'));
                return;
            }

            setStatus(textMessage('waiting', 'Waiting for your approval in the GameQuery popup...'), 'info');
            return;
        }

        if (status === 'completed') {
            finishFlowWithSuccess(textMessage('connected', 'Connected successfully. Reloading settings...'));
            return;
        }

        if (status === 'cancelled') {
            finishFlowWithError(parseErrorMessage(data, textMessage('failed', 'Connection failed. Please try again.')));
            safeClosePopup();
            return;
        }

        if (status === 'expired') {
            finishFlowWithError(parseErrorMessage(data, textMessage('failed', 'Connection failed. Please try again.')));
            safeClosePopup();
            return;
        }

        finishFlowWithError(textMessage('failed', 'Connection failed. Please try again.'));
        safeClosePopup();
    }

    function pollSession() {
        if (!activeSessionId || pollInFlight || isCompleted) {
            return;
        }

        pollInFlight = true;

        postAjax({
            action: 'wpgs_connect_poll',
            nonce: config.nonce,
            session_id: activeSessionId
        }).then(function (result) {
            var payload = result && result.payload ? result.payload : null;
            if (!payload || payload.success !== true) {
                finishFlowWithError(parseErrorMessage(payload, textMessage('failed', 'Connection failed. Please try again.')));
                return;
            }

            handlePollStatus(payload.data || {});
        }).catch(function () {
            finishFlowWithError(textMessage('failed', 'Connection failed. Please try again.'));
        }).finally(function () {
            pollInFlight = false;
        });
    }

    function startPolling() {
        clearPolling();

        var pollIntervalMs = Number.parseInt(config.pollIntervalMs, 10);
        if (!Number.isFinite(pollIntervalMs) || pollIntervalMs < 1000) {
            pollIntervalMs = 2000;
        }

        pollSession();
        pollTimerId = window.setInterval(pollSession, pollIntervalMs);
    }

    function openPopup(url) {
        var width = 540;
        var height = 760;
        var left = Math.max(0, Math.round((window.screen.width - width) / 2));
        var top = Math.max(0, Math.round((window.screen.height - height) / 2));
        var features = [
            'popup=yes',
            'toolbar=no',
            'menubar=no',
            'width=' + width,
            'height=' + height,
            'left=' + left,
            'top=' + top
        ].join(',');

        return window.open(url, 'wpgsConnectGameQuery', features);
    }

    function startConnection() {
        isCompleted = false;
        activeSessionId = '';
        clearPolling();
        safeClosePopup();
        setBusy(true);
        setStatus(textMessage('opening', 'Opening GameQuery account connection...'), 'info');

        postAjax({
            action: 'wpgs_connect_init',
            nonce: config.nonce
        }).then(function (result) {
            var payload = result && result.payload ? result.payload : null;
            if (!payload || payload.success !== true || !payload.data) {
                finishFlowWithError(parseErrorMessage(payload, textMessage('failed', 'Connection failed. Please try again.')));
                return;
            }

            var data = payload.data;
            var sessionId = data.session_id ? String(data.session_id) : '';
            var authorizeUrl = data.authorize_url ? String(data.authorize_url) : '';

            if (!sessionId || !authorizeUrl) {
                finishFlowWithError(textMessage('failed', 'Connection failed. Please try again.'));
                return;
            }

            activeSessionId = sessionId;
            popupWindow = openPopup(authorizeUrl);

            if (!popupWindow) {
                finishFlowWithError(textMessage('popupBlocked', 'Popup blocked by your browser. Please allow popups and try again.'));
                return;
            }

            setStatus(textMessage('waiting', 'Waiting for your approval in the GameQuery popup...'), 'info');
            startPolling();
        }).catch(function () {
            finishFlowWithError(textMessage('failed', 'Connection failed. Please try again.'));
        });
    }

    button.addEventListener('click', function (event) {
        event.preventDefault();
        if (button.disabled) {
            return;
        }

        startConnection();
    });

    window.addEventListener('message', function (event) {
        if (!event || !event.data || typeof event.data !== 'object') {
            return;
        }

        var expectedOrigin = config.accountOrigin ? String(config.accountOrigin).toLowerCase() : '';
        if (expectedOrigin && String(event.origin || '').toLowerCase() !== expectedOrigin) {
            return;
        }

        if (event.data.type !== 'gamequery-plugin-connect') {
            return;
        }

        if (!activeSessionId) {
            return;
        }

        if (event.data.sessionId && String(event.data.sessionId) !== activeSessionId) {
            return;
        }

        if (event.data.status === 'approved' || event.data.status === 'cancelled') {
            pollSession();
        }
    });
}());
