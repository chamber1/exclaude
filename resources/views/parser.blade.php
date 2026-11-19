@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Звук и видео из ролика YouTube</div>

                <div class="card-body">
                    <form id="parser-form" novalidate>
                        {{-- 1. Link --}}
                        <div class="mb-3">
                            <label for="parser-url" class="form-label">Ссылка на ролик</label>
                            <div class="input-group has-validation">
                                <input id="parser-url" name="url" type="url" inputmode="url" required autofocus autocomplete="off"
                                       class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                                <button id="parser-inspect" class="btn btn-outline-secondary" type="button">
                                    <span id="parser-inspect-spinner" class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
                                    Проверить
                                </button>
                                <div id="parser-url-error" class="invalid-feedback"></div>
                            </div>
                            <div class="form-text">
                                Подходят ссылки youtube.com/watch, youtu.be, Shorts. Ролики до {{ $maxMinutes }} минут.
                                @if ($licensePolicy === 'creative_commons')
                                    Скачиваются только ролики, которые автор опубликовал под лицензией Creative Commons.
                                @endif
                                @if ($guest)
                                    <br>Без входа: качество до {{ $limits['max_height'] }}p, до {{ $limits['per_day'] }} загрузок в сутки.
                                    <a href="{{ route('login') }}">Войдите</a>, чтобы получить больше.
                                @endif
                            </div>
                        </div>

                        {{-- 2. What the link points to --}}
                        <div id="parser-info" class="d-none mb-3">
                            <div class="row g-3 align-items-center">
                                <div class="col-sm-4">
                                    <img id="parser-thumb" class="img-fluid rounded w-100" alt="Превью ролика">
                                </div>
                                <div class="col-sm-8">
                                    <div id="parser-title" class="fw-semibold text-break"></div>
                                    <div id="parser-meta" class="text-muted small"></div>
                                    <span id="parser-license" class="badge text-bg-success d-none mt-1">Creative Commons</span>
                                </div>
                            </div>
                        </div>

                        {{-- 3. Options (enabled once the link is checked) --}}
                        <fieldset id="parser-options" disabled>
                            <div class="mb-3">
                                <span class="form-label d-block">Что скачать</span>
                                <div class="btn-group" role="group" aria-label="Что скачать">
                                    <input type="radio" class="btn-check" name="type" id="parser-type-audio" value="audio" checked>
                                    <label class="btn btn-outline-primary" for="parser-type-audio">🎵 Звук</label>
                                    <input type="radio" class="btn-check" name="type" id="parser-type-video" value="video">
                                    <label class="btn btn-outline-primary" for="parser-type-video">🎬 Видео</label>
                                </div>
                            </div>

                            <fieldset id="parser-audio-options">
                                <div class="mb-3">
                                    <span class="form-label d-block">Формат</span>
                                    @foreach ($formats as $format)
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="format" id="parser-format-{{ $format }}" value="{{ $format }}" @checked($loop->first)>
                                            <label class="form-check-label" for="parser-format-{{ $format }}">
                                                {{ strtoupper($format) }}
                                                <small class="text-muted">{{ $format === 'mp3' ? '— открывается везде' : '— AAC, меньше размер при том же качестве' }}</small>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="mb-3">
                                    <label for="parser-bitrate" class="form-label">Битрейт</label>
                                    <select id="parser-bitrate" name="bitrate" class="form-select parser-select">
                                        @foreach ($bitrates as $bitrate)
                                            <option value="{{ $bitrate }}" @selected($bitrate === 'best')>
                                                {{ $bitrate === 'best' ? 'Максимальное' : $bitrate.' кбит/с' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div id="parser-bitrate-error" class="invalid-feedback"></div>
                                    <div id="parser-bitrate-hint" class="form-text"></div>
                                </div>
                            </fieldset>

                            <fieldset id="parser-video-options" class="d-none" disabled>
                                <div class="mb-3">
                                    <label for="parser-quality" class="form-label">Качество видео (MP4)</label>
                                    <select id="parser-quality" name="quality" class="form-select parser-select">
                                        @foreach ($qualities as $quality)
                                            @php($label = match ($quality) { 'best' => 'Максимальное', '2160' => '2160p (4K)', '1440' => '1440p (2K)', default => $quality.'p' })
                                            <option value="{{ $quality }}" data-label="{{ $label }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <div id="parser-quality-error" class="invalid-feedback"></div>
                                    <div id="parser-quality-hint" class="form-text"></div>
                                </div>
                            </fieldset>

                            <div class="d-grid d-sm-flex align-items-sm-center gap-2 gap-sm-3">
                                <button id="parser-submit" type="submit" class="btn btn-primary text-nowrap">
                                    <span id="parser-spinner" class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                                    <span id="parser-submit-text">Скачать звук</span>
                                </button>
                                <span id="parser-status" class="text-muted small" role="status"></span>
                            </div>
                        </fieldset>
                    </form>

                    {{-- 4. Current task --}}
                    <div id="parser-task" class="d-none mt-4" aria-live="polite">
                        <div class="fw-semibold text-break mb-2" id="parser-task-title"></div>
                        <div class="progress mb-2" role="progressbar" aria-label="Прогресс" aria-valuemin="0" aria-valuemax="100">
                            <div id="parser-progress" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%">0%</div>
                        </div>
                        <div id="parser-task-status" class="small text-muted mb-2"></div>
                        <div id="parser-task-error" class="alert alert-danger d-none mb-2"></div>
                        <div class="d-grid d-sm-flex gap-2">
                            <a id="parser-file" class="btn btn-success d-none" href="#" rel="nofollow">⬇ Скачать файл</a>
                            <button id="parser-new" type="button" class="btn btn-outline-secondary d-none">Новая загрузка</button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 5. Recent tasks (survive a page reload) --}}
            <div id="parser-history" class="card mt-4 d-none">
                <div class="card-header">Мои загрузки за сутки</div>
                <ul id="parser-history-list" class="list-group list-group-flush"></ul>
            </div>
        </div>
    </div>
</div>

<style>
    @media (min-width: 576px) { .parser-select { max-width: 320px; } }
</style>

<script type="module">
const ROUTES = {
    inspect: @json(route('downloads.inspect', [], false)),
    store: @json(route('downloads.store', [], false)),
    index: @json(route('downloads.index', [], false)),
};
const POLL_MS = 1500;
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const $ = (id) => document.getElementById(id);

const form = $('parser-form');
const urlInput = $('parser-url');
const urlError = $('parser-url-error');
const inspectBtn = $('parser-inspect');
const options = $('parser-options');
const audioOptions = $('parser-audio-options');
const videoOptions = $('parser-video-options');
const submitBtn = $('parser-submit');
const submitText = $('parser-submit-text');
const spinner = $('parser-spinner');
const qualitySelect = $('parser-quality');
const fields = {
    bitrate: { input: $('parser-bitrate'), error: $('parser-bitrate-error') },
    quality: { input: qualitySelect, error: $('parser-quality-error') },
};

let inspected = null;   // { url, info } of the last successfully checked link
let inspecting = null;  // { url, promise } while a check is running
let pollTimer = null;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

async function api(url, { method = 'GET', body } = {}) {
    const response = await fetch(url, {
        method,
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body,
        credentials: 'same-origin',
    });
    const data = await response.json().catch(() => ({}));
    return { ok: response.ok, status: response.status, data };
}

function errorText(res) {
    if (res.status === 419) return 'Сессия устарела. Обновите страницу.';
    if ([422, 429, 503].includes(res.status)) {
        return res.data.errors?.url?.[0] || res.data.message || 'Не удалось выполнить запрос.';
    }
    return 'Ошибка сервера (HTTP ' + res.status + '). Попробуйте позже.';
}

const mb = (bytes) => (bytes / 1048576).toFixed(bytes < 10485760 ? 1 : 0).replace('.', ',') + ' МБ';
const pad = (n) => String(n).padStart(2, '0');
const duration = (s) => s == null ? '' : (s >= 3600 ? Math.floor(s / 3600) + ':' + pad(Math.floor(s % 3600 / 60)) : Math.floor(s / 60)) + ':' + pad(s % 60);
const currentType = () => form.querySelector('input[name="type"]:checked')?.value || 'audio';
const submitLabel = () => (currentType() === 'video' ? 'Скачать видео' : 'Скачать звук');
const isActive = (task) => task.status === 'pending' || task.status === 'processing';

// Client-side mirror of App\Downloads\Platforms\YouTube, used to react while typing.
function videoId(value) {
    try {
        const raw = value.trim();
        const url = new URL(/^https?:\/\//i.test(raw) ? raw : 'https://' + raw);
        const host = url.hostname.toLowerCase();
        let id = null;
        if (host === 'youtu.be') {
            id = url.pathname.split('/')[1];
        } else if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'].includes(host)) {
            id = url.pathname === '/watch' ? url.searchParams.get('v') : (url.pathname.match(/^\/(shorts|embed|live)\/([^/]+)/) || [])[2];
        }
        return /^[A-Za-z0-9_-]{11}$/.test(id || '') ? id : null;
    } catch {
        return null;
    }
}

function setUrlError(message) {
    urlError.textContent = message || '';
    urlInput.classList.toggle('is-invalid', !!message);
}

function setFieldErrors(errors = {}) {
    for (const [name, field] of Object.entries(fields)) {
        const message = errors[name]?.[0] || '';
        field.error.textContent = message;
        field.input.classList.toggle('is-invalid', !!message);
    }
}

// ---------------------------------------------------------------------------
// Options and hints
// ---------------------------------------------------------------------------

function updateType() {
    const video = currentType() === 'video';
    // A disabled fieldset is left out of FormData, so only the visible options are sent.
    audioOptions.disabled = video;
    audioOptions.classList.toggle('d-none', video);
    videoOptions.disabled = !video;
    videoOptions.classList.toggle('d-none', !video);
    submitText.textContent = submitLabel();
}

function updateBitrateHint() {
    const format = form.querySelector('input[name="format"]:checked')?.value;
    const value = fields.bitrate.input.value;
    const perMinute = (kbps) => (kbps * 60 / 8 / 1024).toFixed(1).replace('.', ',') + ' МБ/мин';
    let hint;
    if (value === 'best') {
        hint = format === 'mp3'
            ? 'MP3 VBR наивысшего качества: битрейт подстраивается под исходник (обычно 130–245 кбит/с, ≈ ' + perMinute(130) + ' – ' + perMinute(245) + ').'
            : 'Исходная дорожка без перекодирования (обычно ~130 кбит/с, ≈ ' + perMinute(130) + ').';
    } else {
        const kbps = Number(value);
        hint = 'Постоянный битрейт, ≈ ' + perMinute(kbps) + '.';
        if (kbps > 160) hint += ' Звук на YouTube обычно ~130–160 кбит/с: выше этого качество не вырастет, увеличится только размер файла.';
    }
    $('parser-bitrate-hint').textContent = hint;
}

const VIDEO_MB_PER_MIN = { best: 140, 2160: 140, 1440: 70, 1080: 30, 720: 18, 480: 8, 360: 3 };

function updateQualityHint() {
    const value = qualitySelect.value;
    const codec = ['best', '2160', '1440'].includes(value)
        ? 'Выше 1080p доступны только VP9/AV1: файл может не открыться в старых плеерах.'
        : 'H.264 + AAC — открывается в любом плеере.';
    $('parser-quality-hint').textContent = `≈ ${VIDEO_MB_PER_MIN[value]} МБ/мин. ${codec} Если такого качества нет, берётся ближайшее ниже.`;
}

/** Marks qualities the video does not have or the visitor's plan does not allow. */
function applyQualities(info) {
    let preferred = null;
    for (const option of qualitySelect.options) {
        const q = info.qualities.find((x) => x.value === option.value) || { available: true, allowed: true };
        let label = option.dataset.label;
        if (!q.available) label += ' — нет в ролике';
        else if (!q.allowed) label += info.limits.guest ? ' — после входа' : ' — на другом тарифе';
        option.textContent = label;
        option.disabled = !q.available || !q.allowed;
        if (!option.disabled && option.value !== 'best' && Number(option.value) <= 1080 && preferred === null) preferred = option.value;
    }
    if (qualitySelect.selectedOptions[0]?.disabled || qualitySelect.value === 'best') {
        qualitySelect.value = preferred || 'best';
    }
    updateQualityHint();
}

// ---------------------------------------------------------------------------
// 1–2. Link check
// ---------------------------------------------------------------------------

function resetInspection() {
    inspected = null;
    options.disabled = true;
    $('parser-info').classList.add('d-none');
}

function inspect() {
    const url = urlInput.value.trim();
    if (inspected?.url === url) return Promise.resolve(inspected);
    if (inspecting?.url === url) return inspecting.promise;

    setUrlError('');
    if (!videoId(url)) {
        resetInspection();
        setUrlError(url ? 'Это не похоже на ссылку на ролик YouTube.' : 'Вставьте ссылку на ролик.');
        return Promise.resolve(null);
    }

    const body = new FormData();
    body.append('url', url);
    $('parser-inspect-spinner').classList.remove('d-none');
    inspectBtn.disabled = true;

    const promise = api(ROUTES.inspect, { method: 'POST', body }).then((res) => {
        if (urlInput.value.trim() !== url) return null; // the link changed meanwhile
        if (!res.ok) {
            resetInspection();
            setUrlError(errorText(res));
            return null;
        }
        const info = res.data;
        inspected = { url, info };
        $('parser-thumb').src = info.thumbnail || `https://i.ytimg.com/vi/${info.id}/hqdefault.jpg`;
        $('parser-title').textContent = info.title;
        $('parser-meta').textContent = [info.uploader, duration(info.duration), info.max_available_height ? 'до ' + info.max_available_height + 'p' : null]
            .filter(Boolean).join(' · ');
        $('parser-license').classList.toggle('d-none', !info.creative_commons);
        $('parser-info').classList.remove('d-none');
        applyQualities(info);
        options.disabled = pollTimer !== null;
        return inspected;
    }).catch(() => {
        setUrlError('Не удалось связаться с сервером.');
        return null;
    }).finally(() => {
        inspecting = null;
        $('parser-inspect-spinner').classList.add('d-none');
        inspectBtn.disabled = false;
    });

    inspecting = { url, promise };
    return promise;
}

let typingTimer = null;
urlInput.addEventListener('input', () => {
    setUrlError('');
    if (inspected && inspected.url !== urlInput.value.trim()) resetInspection();
    clearTimeout(typingTimer);
    if (videoId(urlInput.value)) typingTimer = setTimeout(inspect, 700);
});
urlInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && options.disabled) { e.preventDefault(); inspect(); }
});
inspectBtn.addEventListener('click', inspect);

// ---------------------------------------------------------------------------
// 3. Start a task
// ---------------------------------------------------------------------------

function setBusy(busy) {
    submitBtn.disabled = busy;
    spinner.classList.toggle('d-none', !busy);
    submitText.textContent = busy ? 'Отправляю…' : submitLabel();
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFieldErrors();
    if (!(await inspect())) return;

    // Collect fields before disabling anything: disabled controls are not included in FormData.
    const body = new FormData(form);
    body.set('url', inspected.url);
    setBusy(true);

    try {
        const res = await api(ROUTES.store, { method: 'POST', body });
        if (!res.ok) {
            const errors = res.data.errors || {};
            setFieldErrors(errors);
            if (errors.url || !Object.keys(errors).some((k) => k in fields)) setUrlError(errorText(res));
            return;
        }
        showTask(res.data);
        startPolling(res.data.status_url);
        loadHistory();
    } catch {
        setUrlError('Не удалось связаться с сервером.');
    } finally {
        setBusy(false);
    }
});

// ---------------------------------------------------------------------------
// 4. Progress
// ---------------------------------------------------------------------------

const STATUS_TEXT = {
    pending: 'В очереди. Загрузка начнётся, как только освободится обработчик.',
    processing: 'Скачиваю на сервер…',
    completed: 'Готово.',
    failed: 'Не получилось.',
    expired: 'Срок хранения файла истёк, он удалён с сервера.',
};

function showTask(task) {
    $('parser-task').classList.remove('d-none');
    $('parser-task-title').textContent = task.title || '';

    const bar = $('parser-progress');
    const percent = task.status === 'completed' ? 100 : task.progress;
    bar.style.width = percent + '%';
    bar.textContent = percent + '%';
    bar.parentElement.setAttribute('aria-valuenow', String(percent));
    bar.classList.toggle('progress-bar-animated', isActive(task));
    bar.classList.toggle('bg-success', task.status === 'completed');
    bar.classList.toggle('bg-danger', task.status === 'failed');

    let status = STATUS_TEXT[task.status] || task.status;
    if (task.status === 'processing' && task.progress >= 95) status = 'Конвертирую…';
    if (task.status === 'completed' && task.file) {
        const minutes = Math.max(1, Math.round((new Date(task.expires_at) - Date.now()) / 60000));
        status = `Готово: «${task.file.name}», ${mb(task.file.size)}. Ссылка одноразовая и действует ${minutes} мин.`;
    }
    if (task.status === 'expired' && task.downloaded) status = 'Файл скачан и удалён с сервера.';
    $('parser-task-status').textContent = status;

    const error = $('parser-task-error');
    error.textContent = task.error || '';
    error.classList.toggle('d-none', !task.error);

    const file = $('parser-file');
    file.classList.toggle('d-none', !task.file);
    if (task.file) {
        file.href = task.file.url;
        file.setAttribute('download', task.file.name);
        file.classList.remove('disabled');
    }
    $('parser-new').classList.toggle('d-none', isActive(task));
    options.disabled = isActive(task) || !inspected;
}

function startPolling(statusUrl) {
    clearTimeout(pollTimer);
    const tick = async () => {
        try {
            const res = await api(statusUrl);
            if (res.ok) {
                showTask(res.data);
                if (!isActive(res.data)) {
                    pollTimer = null;
                    loadHistory();
                    return;
                }
            } else if (res.status === 404) {
                pollTimer = null;
                return;
            }
        } catch { /* network hiccup: try again */ }
        pollTimer = setTimeout(tick, POLL_MS);
    };
    pollTimer = setTimeout(tick, 0);
    options.disabled = true;
}

$('parser-file').addEventListener('click', (e) => {
    const link = e.currentTarget;
    if (link.classList.contains('disabled')) { e.preventDefault(); return; }
    // Single-use link: the browser starts the download, the button is spent.
    setTimeout(() => {
        link.classList.add('disabled');
        $('parser-task-status').textContent = 'Загрузка началась. После скачивания файл удаляется с сервера.';
        loadHistory();
    }, 500);
});

$('parser-new').addEventListener('click', () => {
    $('parser-task').classList.add('d-none');
    options.disabled = !inspected;
    urlInput.focus();
    urlInput.select();
});

// ---------------------------------------------------------------------------
// 5. History (and resuming after a reload)
// ---------------------------------------------------------------------------

const HISTORY_STATE = { pending: 'в очереди', completed: 'готово', failed: 'ошибка' };

async function loadHistory(resume = false) {
    const res = await api(ROUTES.index).catch(() => null);
    if (!res?.ok) return;
    const tasks = Array.isArray(res.data) ? res.data : (res.data.data || []);
    const list = $('parser-history-list');
    list.replaceChildren();

    for (const task of tasks) {
        const item = document.createElement('li');
        item.className = 'list-group-item d-flex flex-column flex-sm-row justify-content-between gap-1';
        const title = document.createElement('span');
        title.className = 'text-break';
        title.textContent = (task.type === 'video' ? '🎬 ' : '🎵 ') + (task.title || task.id);
        const state = document.createElement('span');
        state.className = 'small text-nowrap ' + ({ completed: 'text-success', failed: 'text-danger' }[task.status] || 'text-muted');
        state.textContent = task.status === 'processing' ? task.progress + '%'
            : task.status === 'expired' ? (task.downloaded ? 'скачано' : 'удалено') : HISTORY_STATE[task.status];
        item.append(title, state);
        if (task.status !== 'expired') {
            item.setAttribute('role', 'button');
            item.classList.add('list-group-item-action');
            item.addEventListener('click', () => {
                showTask(task);
                if (isActive(task)) startPolling(task.status_url);
                $('parser-task').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        }
        list.append(item);
    }
    $('parser-history').classList.toggle('d-none', tasks.length === 0);

    if (resume) {
        const active = tasks.find(isActive);
        if (active) { showTask(active); startPolling(active.status_url); }
    }
}

// ---------------------------------------------------------------------------

form.querySelectorAll('input[name="type"]').forEach((r) => r.addEventListener('change', () => { setFieldErrors(); updateType(); }));
form.querySelectorAll('input[name="format"]').forEach((r) => r.addEventListener('change', updateBitrateHint));
fields.bitrate.input.addEventListener('change', () => { setFieldErrors(); updateBitrateHint(); });
qualitySelect.addEventListener('change', () => { setFieldErrors(); updateQualityHint(); });

updateType();
updateBitrateHint();
updateQualityHint();
loadHistory(true);
</script>
@endsection
