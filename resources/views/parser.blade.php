@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Звук и видео из ролика YouTube</div>

                <div class="card-body">
                    <form id="parser-form" method="POST" action="{{ route('parser.download') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="parser-url" class="form-label">Ссылка на ролик</label>
                            <input id="parser-url" name="url" type="url" required autofocus
                                   class="form-control @error('url') is-invalid @enderror"
                                   placeholder="https://www.youtube.com/watch?v=..." value="{{ old('url') }}">
                            <div id="parser-url-error" class="invalid-feedback">@error('url'){{ $message }}@enderror</div>
                            <div class="form-text">Подходят ссылки youtube.com/watch, youtu.be, Shorts. Ролики до 60 минут.</div>
                        </div>

                        <div id="parser-preview" class="d-none mb-3">
                            <img id="parser-thumb" class="img-fluid rounded" style="max-height: 180px;" alt="Превью ролика">
                        </div>

                        <fieldset id="parser-options">
                            <div class="mb-3">
                                <span class="form-label d-block">Что скачать</span>
                                <div class="btn-group" role="group" aria-label="Что скачать">
                                    <input type="radio" class="btn-check" name="type" id="parser-type-audio" value="audio" @checked(old('type', 'audio') === 'audio')>
                                    <label class="btn btn-outline-primary" for="parser-type-audio">🎵 Звук</label>
                                    <input type="radio" class="btn-check" name="type" id="parser-type-video" value="video" @checked(old('type') === 'video')>
                                    <label class="btn btn-outline-primary" for="parser-type-video">🎬 Видео</label>
                                </div>
                            </div>

                            {{-- Audio options --}}
                            <fieldset id="parser-audio-options">
                                <div class="mb-3">
                                    <span class="form-label d-block">Формат</span>
                                    @foreach ($formats as $format)
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="format" id="parser-format-{{ $format }}" value="{{ $format }}"
                                                   @checked(old('format', 'mp3') === $format)>
                                            <label class="form-check-label" for="parser-format-{{ $format }}">
                                                {{ strtoupper($format) }}
                                                <small class="text-muted">{{ $format === 'mp3' ? '— открывается везде' : '— AAC, меньше размер при том же качестве' }}</small>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="mb-3">
                                    <label for="parser-bitrate" class="form-label">Битрейт</label>
                                    <select id="parser-bitrate" name="bitrate" class="form-select @error('bitrate') is-invalid @enderror" style="max-width: 320px;">
                                        @foreach ($bitrates as $bitrate)
                                            <option value="{{ $bitrate }}" @selected(old('bitrate', 'best') === $bitrate)>
                                                {{ $bitrate === 'best' ? 'Максимальное' : $bitrate.' кбит/с' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div id="parser-bitrate-error" class="invalid-feedback">@error('bitrate'){{ $message }}@enderror</div>
                                    <div id="parser-bitrate-hint" class="form-text"></div>
                                </div>
                            </fieldset>

                            {{-- Video options --}}
                            <fieldset id="parser-video-options">
                                <div class="mb-3">
                                    <label for="parser-quality" class="form-label">Качество видео (MP4)</label>
                                    <select id="parser-quality" name="quality" class="form-select @error('quality') is-invalid @enderror" style="max-width: 320px;">
                                        @foreach ($qualities as $quality)
                                            <option value="{{ $quality }}" @selected(old('quality', '1080') === $quality)>
                                                {{ match ($quality) { 'best' => 'Максимальное', '2160' => '2160p (4K)', '1440' => '1440p (2K)', default => $quality.'p' } }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div id="parser-quality-error" class="invalid-feedback">@error('quality'){{ $message }}@enderror</div>
                                    <div id="parser-quality-hint" class="form-text"></div>
                                </div>
                            </fieldset>
                        </fieldset>

                        <div class="d-flex align-items-center gap-3">
                            <button id="parser-submit" type="submit" class="btn btn-primary text-nowrap">
                                <span id="parser-spinner" class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                                <span id="parser-submit-text">Скачать звук</span>
                            </button>
                            <span id="parser-status" class="text-muted small" role="status"></span>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="module">
const form = document.getElementById('parser-form');
const urlInput = document.getElementById('parser-url');
const urlError = document.getElementById('parser-url-error');
const options = document.getElementById('parser-options');
const audioOptions = document.getElementById('parser-audio-options');
const videoOptions = document.getElementById('parser-video-options');
const submitBtn = document.getElementById('parser-submit');
const submitText = document.getElementById('parser-submit-text');
const spinner = document.getElementById('parser-spinner');
const statusText = document.getElementById('parser-status');
const preview = document.getElementById('parser-preview');
const thumb = document.getElementById('parser-thumb');
const fields = {
    bitrate: { select: document.getElementById('parser-bitrate'), error: document.getElementById('parser-bitrate-error') },
    quality: { select: document.getElementById('parser-quality'), error: document.getElementById('parser-quality-error') },
};
const bitrateHint = document.getElementById('parser-bitrate-hint');
const qualityHint = document.getElementById('parser-quality-hint');

const currentType = () => form.querySelector('input[name="type"]:checked')?.value || 'audio';
const submitLabel = () => (currentType() === 'video' ? 'Скачать видео' : 'Скачать звук');

// ---------------------------------------------------------------------------
// Audio / video switch and hints
// ---------------------------------------------------------------------------

function updateType() {
    const video = currentType() === 'video';
    // A disabled fieldset is also left out of FormData, so only the visible options are sent.
    audioOptions.disabled = video;
    audioOptions.classList.toggle('d-none', video);
    videoOptions.disabled = !video;
    videoOptions.classList.toggle('d-none', !video);
    submitText.textContent = submitLabel();
}

function mb(value) {
    return value.toFixed(value < 10 ? 1 : 0).replace('.', ',');
}

function updateBitrateHint() {
    const format = form.querySelector('input[name="format"]:checked')?.value;
    const value = fields.bitrate.select.value;
    const perMinute = (kbps) => mb(kbps * 60 / 8 / 1024) + ' МБ/мин';
    let hint;

    if (value === 'best') {
        hint = format === 'mp3'
            ? 'MP3 VBR наивысшего качества: битрейт подстраивается под исходник, обычно 130–245 кбит/с (≈ ' + perMinute(130) + ' – ' + perMinute(245) + ').'
            : 'Исходная дорожка YouTube без перекодирования (обычно ~130 кбит/с, ≈ ' + perMinute(130) + ').';
    } else {
        const kbps = Number(value);
        hint = 'Постоянный битрейт, ≈ ' + perMinute(kbps) + '.';
        if (kbps > 160) hint += ' Звук на YouTube обычно ~130–160 кбит/с, так что выше этого качество не вырастет, увеличится только размер файла.';
    }

    bitrateHint.textContent = hint;
}

// Rough MB per minute of video on YouTube (varies a lot with content).
const VIDEO_MB_PER_MIN = { best: 140, 2160: 140, 1440: 70, 1080: 30, 720: 18, 480: 8, 360: 3 };

function updateQualityHint() {
    const value = fields.quality.select.value;
    const codec = ['best', '2160', '1440'].includes(value)
        ? 'Выше 1080p YouTube отдаёт только VP9/AV1: файл может не открыться в старых плеерах и на некоторых телевизорах.'
        : 'H.264 + AAC — открывается в любом плеере.';
    const limit = value === 'best' ? 'Если такого качества нет, берётся лучшее доступное.' : 'Если такого качества нет, берётся ближайшее ниже.';

    qualityHint.textContent = `≈ ${VIDEO_MB_PER_MIN[value]} МБ/мин. ${codec} ${limit} Максимальный размер файла — 2 ГБ.`;
}

form.querySelectorAll('input[name="type"]').forEach((radio) => radio.addEventListener('change', () => { setFieldErrors({}); updateType(); }));
form.querySelectorAll('input[name="format"]').forEach((radio) => radio.addEventListener('change', updateBitrateHint));
fields.bitrate.select.addEventListener('change', () => { setFieldErrors({}); updateBitrateHint(); });
fields.quality.select.addEventListener('change', () => { setFieldErrors({}); updateQualityHint(); });

// ---------------------------------------------------------------------------
// URL preview and errors
// ---------------------------------------------------------------------------

// Client-side mirror of ParserController::extractVideoId(), used only for the preview.
function videoId(value) {
    try {
        const url = new URL(/^https?:\/\//i.test(value.trim()) ? value.trim() : 'https://' + value.trim());
        const host = url.hostname.toLowerCase();
        let id = null;
        if (host === 'youtu.be') {
            id = url.pathname.split('/')[1];
        } else if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'].includes(host)) {
            id = url.pathname === '/watch'
                ? url.searchParams.get('v')
                : (url.pathname.match(/^\/(shorts|embed|live)\/([^/]+)/) || [])[2];
        }
        return /^[A-Za-z0-9_-]{11}$/.test(id || '') ? id : null;
    } catch {
        return null;
    }
}

function updatePreview() {
    const id = videoId(urlInput.value);
    preview.classList.toggle('d-none', !id);
    if (id) thumb.src = `https://i.ytimg.com/vi/${id}/hqdefault.jpg`;
}

function setError(message) {
    urlError.textContent = message || '';
    urlInput.classList.toggle('is-invalid', !!message);
}

function setFieldErrors(errors) {
    for (const [name, field] of Object.entries(fields)) {
        const message = errors[name]?.[0] || '';
        field.error.textContent = message;
        field.select.classList.toggle('is-invalid', !!message);
    }
}

function setBusy(busy) {
    submitBtn.disabled = busy;
    urlInput.readOnly = busy;
    options.disabled = busy;
    spinner.classList.toggle('d-none', !busy);
    submitText.textContent = busy ? 'Обрабатываю…' : submitLabel();
}

function setStatus(text) {
    statusText.textContent = text;
}

function filenameFrom(disposition) {
    const utf = /filename\*=utf-8''([^;]+)/i.exec(disposition || '');
    if (utf) return decodeURIComponent(utf[1]);
    const plain = /filename="?([^";]+)"?/i.exec(disposition || '');
    return plain ? plain[1] : 'audio';
}

function startDownload(href, name) {
    const link = document.createElement('a');
    link.href = href;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
}

urlInput.addEventListener('input', () => { setError(''); updatePreview(); });

// ---------------------------------------------------------------------------
// Submit
// ---------------------------------------------------------------------------

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('');
    setFieldErrors({});
    setStatus('');

    if (!videoId(urlInput.value)) {
        setError('Это не похоже на ссылку на ролик YouTube.');
        return;
    }

    const isVideo = currentType() === 'video';
    // Collect fields before setBusy(): disabled controls are not included in FormData.
    const body = new FormData(form);
    setBusy(true);
    setStatus(isVideo
        ? 'Скачиваю видео на сервер — для длинных роликов в высоком качестве это может занять несколько минут.'
        : 'Скачиваю и конвертирую — обычно это занимает от нескольких секунд до пары минут.');
    const started = Date.now();

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body,
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            if (response.status === 422) {
                const errors = data.errors || {};
                setFieldErrors(errors);
                if (!errors.bitrate && !errors.quality || errors.url || errors.format || errors.type) {
                    setError(errors.url?.[0] || errors.format?.[0] || errors.type?.[0] || data.message);
                }
            } else if (response.status === 429) {
                setError('Слишком много запросов. Подождите минуту.');
            } else if (response.status === 419) {
                setError('Сессия устарела. Обновите страницу.');
            } else {
                setError('Ошибка сервера (HTTP ' + response.status + ').');
            }
            setStatus('');
            return;
        }

        const seconds = Math.round((Date.now() - started) / 1000);

        if (isVideo) {
            // The server keeps the file; the browser downloads it by a one-time link (no blob in memory).
            const data = await response.json();
            startDownload(data.url, data.name);
            setStatus(`Готово: «${data.name}» (${mb(data.size / 1048576)} МБ, ${seconds} с). Загрузка началась.`);
        } else {
            const name = filenameFrom(response.headers.get('Content-Disposition'));
            const blob = await response.blob();
            const href = URL.createObjectURL(blob);
            startDownload(href, name);
            setTimeout(() => URL.revokeObjectURL(href), 60_000);
            setStatus(`Готово: «${name}» (${mb(blob.size / 1048576)} МБ, ${seconds} с).`);
        }
    } catch (e) {
        setError('Не удалось связаться с сервером: ' + e.message);
        setStatus('');
    } finally {
        setBusy(false);
    }
});

updateType();
updateBitrateHint();
updateQualityHint();
updatePreview();
</script>
@endsection
