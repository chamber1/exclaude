@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">Веб-камера</div>

                <div class="card-body">
                    <div id="webcam-error" class="alert alert-danger d-none" role="alert"></div>

                    <div class="ratio ratio-4x3 bg-dark rounded overflow-hidden mb-3">
                        {{-- Video is the hidden source; frames (and the mask) are painted onto the canvas. --}}
                        <video id="webcam-video" autoplay playsinline muted style="position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none;"></video>
                        <canvas id="webcam-canvas" class="w-100 h-100" style="object-fit: cover;"></canvas>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button id="webcam-start" type="button" class="btn btn-primary">Включить камеру</button>
                        <button id="webcam-stop" type="button" class="btn btn-outline-secondary" disabled>Выключить</button>

                        <div class="form-check form-switch ms-2 mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="webcam-mask">
                            <label class="form-check-label" for="webcam-mask">Маска клоуна 🤡</label>
                        </div>
                        <small id="webcam-mask-status" class="text-muted"></small>

                        <button id="webcam-capture" type="button" class="btn btn-success ms-auto" disabled>Сделать снимок</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Мои снимки</div>

                <div class="card-body">
                    <p id="webcam-empty" class="text-muted mb-0 {{ $snapshots->isEmpty() ? '' : 'd-none' }}">Снимков пока нет.</p>

                    <div id="webcam-gallery" class="row g-3">
                        @foreach ($snapshots as $snapshot)
                            <div class="col-6 col-md-4" data-snapshot>
                                <figure class="figure mb-0 w-100">
                                    <a href="{{ route('webcam.show', $snapshot) }}" target="_blank">
                                        <img src="{{ route('webcam.show', $snapshot) }}" class="figure-img img-fluid rounded" alt="Снимок {{ $snapshot->created_at->format('d.m.Y H:i:s') }}" loading="lazy">
                                    </a>
                                    <figcaption class="figure-caption d-flex justify-content-between align-items-center">
                                        <span>{{ $snapshot->created_at->format('d.m.Y H:i:s') }}</span>
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0" data-delete="{{ route('webcam.destroy', $snapshot) }}">Удалить</button>
                                    </figcaption>
                                </figure>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="module">
const MEDIAPIPE_URL = 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14';
const FACE_MODEL_URL = 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task';

const video = document.getElementById('webcam-video');
const canvas = document.getElementById('webcam-canvas');
const ctx = canvas.getContext('2d');
const startBtn = document.getElementById('webcam-start');
const stopBtn = document.getElementById('webcam-stop');
const captureBtn = document.getElementById('webcam-capture');
const maskToggle = document.getElementById('webcam-mask');
const maskStatus = document.getElementById('webcam-mask-status');
const errorBox = document.getElementById('webcam-error');
const gallery = document.getElementById('webcam-gallery');
const emptyText = document.getElementById('webcam-empty');
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
const storeUrl = @json(route('webcam.store'));
const destroyUrlTemplate = @json(route('webcam.destroy', ['snapshot' => '__ID__']));

let stream = null;
let landmarker = null;
let landmarkerLoading = null;
let lastVideoTime = -1;
let lastFaces = [];

function showError(message) {
    errorBox.textContent = message;
    errorBox.classList.remove('d-none');
}

function hideError() {
    errorBox.classList.add('d-none');
}

function setRunning(running) {
    startBtn.disabled = running;
    stopBtn.disabled = !running;
    captureBtn.disabled = !running;
}

// ---------------------------------------------------------------------------
// Camera
// ---------------------------------------------------------------------------

async function startCamera() {
    hideError();

    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        showError('Браузер не даёт доступ к камере на этой странице. Откройте сайт по https:// или через http://localhost.');
        return;
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false,
        });
        video.srcObject = stream;
        await video.play();
        setRunning(true);
        requestAnimationFrame(render);
    } catch (e) {
        const messages = {
            NotAllowedError: 'Доступ к камере запрещён. Разрешите его в настройках браузера и попробуйте снова.',
            NotFoundError: 'Камера не найдена.',
            NotReadableError: 'Камера занята другим приложением.',
        };
        showError(messages[e.name] || 'Не удалось включить камеру: ' + e.message);
    }
}

function stopCamera() {
    stream?.getTracks().forEach(track => track.stop());
    stream = null;
    video.srcObject = null;
    lastFaces = [];
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    setRunning(false);
}

// ---------------------------------------------------------------------------
// Face landmarks (MediaPipe Face Landmarker, loaded lazily on first use)
// ---------------------------------------------------------------------------

function loadLandmarker() {
    landmarkerLoading ??= (async () => {
        const { FilesetResolver, FaceLandmarker } = await import(MEDIAPIPE_URL + '/vision_bundle.mjs');
        const fileset = await FilesetResolver.forVisionTasks(MEDIAPIPE_URL + '/wasm');
        const options = (delegate) => ({
            baseOptions: { modelAssetPath: FACE_MODEL_URL, delegate },
            runningMode: 'VIDEO',
            numFaces: 3,
        });

        try {
            return await FaceLandmarker.createFromOptions(fileset, options('GPU'));
        } catch {
            return await FaceLandmarker.createFromOptions(fileset, options('CPU'));
        }
    })();

    return landmarkerLoading;
}

async function onMaskToggle() {
    if (!maskToggle.checked || landmarker) {
        maskStatus.textContent = '';
        return;
    }

    maskToggle.disabled = true;
    maskStatus.textContent = 'Загрузка модели лица…';
    try {
        landmarker = await loadLandmarker();
        maskStatus.textContent = '';
    } catch (e) {
        landmarkerLoading = null;
        maskToggle.checked = false;
        maskStatus.textContent = '';
        showError('Не удалось загрузить модель распознавания лица: ' + e.message);
    } finally {
        maskToggle.disabled = false;
    }
}

// ---------------------------------------------------------------------------
// Clown mask drawing (MediaPipe 468-point face mesh indices)
// ---------------------------------------------------------------------------

const FACE_OVAL = [10, 338, 297, 332, 284, 251, 389, 356, 454, 323, 361, 288, 397, 365, 379, 378, 400, 377, 152, 148, 176, 149, 150, 136, 172, 58, 132, 93, 234, 127, 162, 21, 54, 103, 67, 109];
const LIPS_OUTER = [61, 146, 91, 181, 84, 17, 314, 405, 321, 375, 291, 409, 270, 269, 267, 0, 37, 39, 40, 185];
const LEFT_BROW = [70, 63, 105, 66, 107];
const RIGHT_BROW = [336, 296, 334, 293, 300];
const WIG_POINTS = [127, 162, 21, 54, 103, 67, 297, 332, 284, 251, 389, 356];
const WIG_COLORS = ['#ff3b30', '#ff9500', '#ffcc00', '#34c759', '#007aff', '#af52de'];
const EYES = [
    { top: 159, bottom: 145, inner: 133, outer: 33 },
    { top: 386, bottom: 374, inner: 362, outer: 263 },
];

function drawClownMask(landmarks, W, H) {
    const P = (i) => ({ x: landmarks[i].x * W, y: landmarks[i].y * H });
    const add = (a, v, k) => ({ x: a.x + v.x * k, y: a.y + v.y * k });
    const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    const mid = (...pts) => ({
        x: pts.reduce((s, p) => s + p.x, 0) / pts.length,
        y: pts.reduce((s, p) => s + p.y, 0) / pts.length,
    });

    const faceW = dist(P(234), P(454));
    const center = mid(P(234), P(454));
    const chin = P(152);
    const forehead = P(10);
    const upLen = dist(forehead, chin) || 1;
    const up = { x: (forehead.x - chin.x) / upLen, y: (forehead.y - chin.y) / upLen };
    const side = { x: -up.y, y: up.x };

    const polygon = (points) => {
        ctx.beginPath();
        points.forEach((p, i) => (i ? ctx.lineTo(p.x, p.y) : ctx.moveTo(p.x, p.y)));
        ctx.closePath();
    };

    ctx.save();

    // 1. White face paint.
    polygon(FACE_OVAL.map(P));
    ctx.fillStyle = 'rgba(255, 255, 255, 0.55)';
    ctx.fill();

    // 2. Rainbow wig: puffs pushed outward from the face center.
    WIG_POINTS.forEach((i, n) => {
        const p = P(i);
        const d = dist(p, center) || 1;
        const out = add(p, { x: (p.x - center.x) / d, y: (p.y - center.y) / d }, faceW * 0.12);
        ctx.beginPath();
        ctx.arc(out.x, out.y, faceW * 0.17, 0, Math.PI * 2);
        ctx.fillStyle = WIG_COLORS[n % WIG_COLORS.length];
        ctx.fill();
    });

    // 3. Blue triangles above and below each eye.
    ctx.fillStyle = 'rgba(30, 111, 255, 0.85)';
    EYES.forEach(({ top, bottom, inner, outer }) => {
        const eyeW = dist(P(inner), P(outer));
        const t = P(top);
        const b = P(bottom);
        const half = eyeW * 0.28;

        polygon([add(t, side, -half), add(t, side, half), add(t, up, eyeW * 0.9)]);
        ctx.fill();
        polygon([add(b, side, -half), add(b, side, half), add(b, up, -eyeW * 0.9)]);
        ctx.fill();
    });

    // 4. High arched black eyebrows.
    ctx.strokeStyle = '#111';
    ctx.lineWidth = faceW * 0.025;
    ctx.lineCap = 'round';
    [LEFT_BROW, RIGHT_BROW].forEach((brow) => {
        ctx.beginPath();
        brow.map(P).forEach((p, i, all) => {
            const lift = faceW * (0.07 + 0.04 * Math.sin((i / (all.length - 1)) * Math.PI));
            const q = add(p, up, lift);
            i ? ctx.lineTo(q.x, q.y) : ctx.moveTo(q.x, q.y);
        });
        ctx.stroke();
    });

    // 5. Rosy cheeks.
    ctx.fillStyle = 'rgba(255, 59, 48, 0.45)';
    [50, 280].forEach((i) => {
        const p = P(i);
        ctx.beginPath();
        ctx.arc(p.x, p.y, faceW * 0.09, 0, Math.PI * 2);
        ctx.fill();
    });

    // 6. Big red mouth: outer lip contour scaled up around the mouth center.
    const mouthCenter = mid(P(13), P(14));
    polygon(LIPS_OUTER.map(P).map((p) => ({
        x: mouthCenter.x + (p.x - mouthCenter.x) * 1.4,
        y: mouthCenter.y + (p.y - mouthCenter.y) * 1.6,
    })));
    ctx.fillStyle = 'rgba(224, 16, 47, 0.9)';
    ctx.fill();

    // 7. Red ball nose with a highlight.
    const nose = P(1);
    const r = faceW * 0.12;
    const gradient = ctx.createRadialGradient(nose.x - r * 0.35, nose.y - r * 0.35, r * 0.1, nose.x, nose.y, r);
    gradient.addColorStop(0, '#ff8a80');
    gradient.addColorStop(0.35, '#ff1744');
    gradient.addColorStop(1, '#b0001a');
    ctx.beginPath();
    ctx.arc(nose.x, nose.y, r, 0, Math.PI * 2);
    ctx.fillStyle = gradient;
    ctx.fill();

    ctx.restore();
}

// ---------------------------------------------------------------------------
// Render loop: video frame -> canvas (+ mask)
// ---------------------------------------------------------------------------

function render() {
    if (!stream) return;

    if (video.readyState >= 2 && video.videoWidth) {
        if (canvas.width !== video.videoWidth || canvas.height !== video.videoHeight) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
        }

        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        if (maskToggle.checked && landmarker) {
            if (video.currentTime !== lastVideoTime) {
                lastVideoTime = video.currentTime;
                try {
                    lastFaces = landmarker.detectForVideo(video, performance.now()).faceLandmarks;
                } catch {
                    lastFaces = [];
                }
            }
            lastFaces.forEach((face) => drawClownMask(face, canvas.width, canvas.height));
        }
    }

    requestAnimationFrame(render);
}

// ---------------------------------------------------------------------------
// Snapshots (the canvas already contains the mask, so it is saved as shown)
// ---------------------------------------------------------------------------

function addToGallery(snapshot) {
    const col = document.createElement('div');
    col.className = 'col-6 col-md-4';
    col.dataset.snapshot = '';

    const figure = document.createElement('figure');
    figure.className = 'figure mb-0 w-100';

    const link = document.createElement('a');
    link.href = snapshot.url;
    link.target = '_blank';

    const img = document.createElement('img');
    img.src = snapshot.url;
    img.className = 'figure-img img-fluid rounded';
    img.alt = 'Снимок ' + snapshot.created_at;
    link.appendChild(img);

    const caption = document.createElement('figcaption');
    caption.className = 'figure-caption d-flex justify-content-between align-items-center';

    const date = document.createElement('span');
    date.textContent = snapshot.created_at;

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'btn btn-sm btn-link text-danger p-0';
    del.dataset.delete = destroyUrlTemplate.replace('__ID__', snapshot.id);
    del.textContent = 'Удалить';

    caption.append(date, del);
    figure.append(link, caption);
    col.appendChild(figure);
    gallery.prepend(col);
    emptyText.classList.add('d-none');
}

async function capture() {
    if (!stream || !canvas.width) return;

    hideError();
    captureBtn.disabled = true;
    try {
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
        const form = new FormData();
        form.append('image', blob, 'snapshot.jpg');

        const response = await fetch(storeUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: form,
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.message || 'HTTP ' + response.status);
        }

        addToGallery(await response.json());
    } catch (e) {
        showError('Не удалось сохранить снимок: ' + e.message);
    } finally {
        captureBtn.disabled = !stream;
    }
}

gallery.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-delete]');
    if (!button || !confirm('Удалить снимок?')) return;

    button.disabled = true;
    try {
        const response = await fetch(button.dataset.delete, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        if (!response.ok) throw new Error('HTTP ' + response.status);

        button.closest('[data-snapshot]').remove();
        if (!gallery.querySelector('[data-snapshot]')) emptyText.classList.remove('d-none');
    } catch (e) {
        button.disabled = false;
        showError('Не удалось удалить снимок: ' + e.message);
    }
});

startBtn.addEventListener('click', startCamera);
stopBtn.addEventListener('click', stopCamera);
captureBtn.addEventListener('click', capture);
maskToggle.addEventListener('change', onMaskToggle);
window.addEventListener('beforeunload', stopCamera);
</script>
@endsection
