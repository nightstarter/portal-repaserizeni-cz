(function () {
    'use strict';

    function initializeShipmentCamera() {
        var root = document.getElementById('shipment-qr-scanner');
        if (!root) {
            return;
        }

        var startButton = document.getElementById('start-camera');
        var stopButton = document.getElementById('stop-camera');
        var selectorWrap = document.getElementById('camera-selector');
        var deviceSelect = document.getElementById('camera-device');
        var video = document.getElementById('qr-camera');
        var canvas = document.getElementById('qr-camera-canvas');
        var input = document.getElementById('qr_token');
        var form = document.getElementById('shipment-scan-form');
        var message = document.getElementById('camera-message');

        if (!startButton || !stopButton || !selectorWrap || !deviceSelect || !video || !canvas || !input || !form || !message) {
            return;
        }

        var stream = null;
        var frameRequest = null;
        var nativeDetector = null;
        var decoder = null;
        var running = false;
        var runId = 0;
        var lastDecodeAt = 0;
        var scanStartedAt = 0;
        var notFoundReported = false;
        var context = canvas.getContext('2d', {willReadFrequently: true});

        function text(key) {
            return root.dataset[key] || '';
        }

        function setStatus(key, isError) {
            message.textContent = text(key);
            message.classList.toggle('error', Boolean(isError));
            message.classList.toggle('muted', !isError);
        }

        function enableStart(enabled) {
            startButton.disabled = !enabled;
            startButton.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        }

        function stopTracks() {
            runId += 1;
            running = false;
            if (frameRequest !== null) {
                window.cancelAnimationFrame(frameRequest);
                frameRequest = null;
            }
            if (stream) {
                stream.getTracks().forEach(function (track) {
                    track.stop();
                });
                stream = null;
            }
            video.pause();
            video.srcObject = null;
            video.hidden = true;
            selectorWrap.hidden = true;
            stopButton.hidden = true;
            startButton.hidden = false;
        }

        function stopByUser() {
            stopTracks();
            enableStart(true);
            setStatus('cameraStopped', false);
        }

        function terminalFailure(key) {
            stopTracks();
            enableStart(false);
            setStatus(key, true);
        }

        function recoverableFailure(key) {
            stopTracks();
            enableStart(true);
            setStatus(key, true);
        }

        function errorKey(error) {
            var name = error && error.name ? error.name : '';
            if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
                return 'cameraDenied';
            }
            if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                return 'cameraNotFound';
            }
            if (name === 'NotReadableError' || name === 'TrackStartError') {
                return 'cameraInUse';
            }
            if (name === 'TypeError') {
                return 'cameraNotSupported';
            }
            return 'cameraStartFailed';
        }

        function shouldTryGenericCamera(error) {
            var key = errorKey(error);
            return key !== 'cameraDenied' && key !== 'cameraInUse' && key !== 'cameraNotSupported';
        }

        async function openInitialStream() {
            try {
                return await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: {facingMode: {ideal: 'environment'}},
                });
            } catch (firstError) {
                if (!shouldTryGenericCamera(firstError)) {
                    throw firstError;
                }
                return navigator.mediaDevices.getUserMedia({audio: false, video: true});
            }
        }

        function openSelectedStream(deviceId) {
            return navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {deviceId: {exact: deviceId}},
            });
        }

        async function populateCameraSelector() {
            if (!navigator.mediaDevices.enumerateDevices) {
                return;
            }
            var devices = (await navigator.mediaDevices.enumerateDevices()).filter(function (device) {
                return device.kind === 'videoinput';
            });
            var currentTrack = stream && stream.getVideoTracks()[0];
            var currentId = currentTrack && currentTrack.getSettings ? currentTrack.getSettings().deviceId : '';
            deviceSelect.replaceChildren();
            devices.forEach(function (device, index) {
                var option = document.createElement('option');
                option.value = device.deviceId;
                option.textContent = device.label || text('cameraLabel').replace(':number', String(index + 1));
                option.selected = device.deviceId === currentId;
                deviceSelect.appendChild(option);
            });
            selectorWrap.hidden = devices.length < 2;
        }

        async function decodeFrame() {
            if (decoder === 'native') {
                var codes = await nativeDetector.detect(video);
                return codes.length ? codes[0].rawValue : null;
            }

            if (!context || video.videoWidth < 1 || video.videoHeight < 1) {
                return null;
            }
            if (canvas.width !== video.videoWidth || canvas.height !== video.videoHeight) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
            }
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            var image = context.getImageData(0, 0, canvas.width, canvas.height);
            var result = window.jsQR(image.data, image.width, image.height, {inversionAttempts: 'attemptBoth'});
            return result && result.data ? result.data : null;
        }

        async function scanFrame(timestamp, expectedRunId) {
            if (!running || expectedRunId !== runId) {
                return;
            }

            if (video.readyState >= 2 && timestamp - lastDecodeAt >= 120) {
                lastDecodeAt = timestamp;
                try {
                    var value = await decodeFrame();
                    if (value) {
                        stopTracks();
                        setStatus('qrFound', false);
                        input.value = value;
                        form.requestSubmit();
                        return;
                    }
                    if (!notFoundReported && performance.now() - scanStartedAt >= 5000) {
                        notFoundReported = true;
                        setStatus('qrNotFound', true);
                    }
                } catch (error) {
                    recoverableFailure('cameraStartFailed');
                    return;
                }
            }

            if (running && expectedRunId === runId) {
                frameRequest = window.requestAnimationFrame(function (nextTimestamp) {
                    scanFrame(nextTimestamp, expectedRunId);
                });
            }
        }

        async function startCamera(deviceId) {
            setStatus('cameraStarting', false);
            enableStart(false);
            stopTracks();
            try {
                stream = deviceId ? await openSelectedStream(deviceId) : await openInitialStream();
                video.srcObject = stream;
                video.hidden = false;
                await video.play();
                await populateCameraSelector();
                startButton.hidden = true;
                stopButton.hidden = false;
                running = true;
                runId += 1;
                lastDecodeAt = 0;
                scanStartedAt = performance.now();
                notFoundReported = false;
                setStatus('cameraReady', false);
                var expectedRunId = runId;
                frameRequest = window.requestAnimationFrame(function (timestamp) {
                    scanFrame(timestamp, expectedRunId);
                });
            } catch (error) {
                var key = errorKey(error);
                if (key === 'cameraNotFound' || key === 'cameraNotSupported') {
                    terminalFailure(key);
                } else {
                    recoverableFailure(key);
                }
            }
        }

        async function initializeDecoder() {
            if (window.location.protocol !== 'https:' || !window.isSecureContext || !navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
                terminalFailure('cameraNotSupported');
                return;
            }

            var policy = document.permissionsPolicy || document.featurePolicy;
            if (policy && typeof policy.allowsFeature === 'function' && !policy.allowsFeature('camera')) {
                terminalFailure('cameraPolicyBlocked');
                return;
            }

            if (typeof window.BarcodeDetector === 'function' && typeof window.BarcodeDetector.getSupportedFormats === 'function') {
                try {
                    var formats = await window.BarcodeDetector.getSupportedFormats();
                    if (formats.indexOf('qr_code') !== -1) {
                        nativeDetector = new window.BarcodeDetector({formats: ['qr_code']});
                        decoder = 'native';
                    }
                } catch (error) {
                    nativeDetector = null;
                }
            }

            if (!decoder && context && typeof window.jsQR === 'function') {
                decoder = 'jsqr';
            }
            if (!decoder) {
                terminalFailure('qrFormatNotSupported');
                return;
            }

            startButton.addEventListener('click', function () {
                startCamera(null);
            });
            stopButton.addEventListener('click', stopByUser);
            deviceSelect.addEventListener('change', function () {
                if (deviceSelect.value) {
                    startCamera(deviceSelect.value);
                }
            });
            window.addEventListener('pagehide', stopTracks);
            window.addEventListener('beforeunload', stopTracks);
            startButton.dataset.cameraInitialized = 'true';
            enableStart(true);
            setStatus('cameraAvailable', false);
        }

        initializeDecoder().catch(function () {
            recoverableFailure('cameraStartFailed');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeShipmentCamera, {once: true});
    } else {
        initializeShipmentCamera();
    }
}());
