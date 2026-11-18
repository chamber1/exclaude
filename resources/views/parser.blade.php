@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Звук из ролика YouTube</div>

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

                        <div class="d-flex align-items-center gap-3">
                            <button id="parser-submit" type="submit" class="btn btn-primary">
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
const submitBtn = document.getElementById('parser-submit');
const submitText = document.getElementById('parser-submit-text');
const spinner = document.getElementById('parser-spinner');
const statusText = document.getElementById('parser-status');
const preview = document.getElementById('parser-preview');
const thumb = document.getElementById('parser-thumb');
const bitrateSelect = document.getElementById('parser-bitrate');
const bitrateError = document.getElementById('parser-bitrate-error');
const bitrateHint = document.getElementById('parser-bitrate-hint');

// Approximate size per minute of audio, for the hint.
function sizePerMinute(kbps) {
    return (kbps * 60 / 8 / 1024).toFixed(1).replace('.', ',') + ' МБ/мин';
}

function updateBitrateHint() {
    const format = form.querySelector('input[name="format"]:checked')?.value;
    const value = bitrateSelect.value;
    let hint;

    if (value === 'best') {
        hint = format === 'mp3'
            ? 'MP3 VBR наивысшего качества: битрейт подстраивается под исходник, обычно 130–245 кбит/с (≈ ' + sizePerMinute(130) + ' – ' + sizePerMinute(245) + ').'
            : 'Исходная дорожка YouTube без перекодирования (обычно ~130 кбит/с, ≈ ' + sizePerMinute(130) + ').';
    } else {
        const kbps = Number(value);
        hint = 'Постоянный битрейт, ≈ ' + sizePerMinute(kbps) + '.';
        if (kbps > 160) hint += ' Звук на YouTube обычно ~130–160 кбит/с, так что выше этого качество не вырастет, увеличится только размер файла.';
    }

    bitrateHint.textContent = hint;
}

bitrateSelect.addEventListener('change', () => { setBitrateError(''); updateBitrateHint(); });
form.querySelectorAll('input[name="format"]').forEach((radio) => radio.addEventListener('change', updateBitrateHint));
updateBitrateHint();

function setBitrateError(message) {
    bitrateError.textContent = message || '';
    bitrateSelect.classList.toggle('is-invalid', !!message);
}

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

function setBusy(busy) {
    submitBtn.disabled = busy;
    urlInput.readOnly = busy;
    bitrateSelect.disabled = busy;
    spinner.classList.toggle('d-none', !busy);
    submitText.textContent = busy ? 'Обрабатываю…' : 'Скачать звук';
}

function filenameFrom(disposition) {
    const utf = /filename\*=utf-8''([^;]+)/i.exec(disposition || '');
    if (utf) return decodeURIComponent(utf[1]);
    const plain = /filename="?([^";]+)"?/i.exec(disposition || '');
    return plain ? plain[1] : 'audio';
}

urlInput.addEventListener('input', () => { setError(''); updatePreview(); });
updatePreview();

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('');
    setBitrateError('');
    statusText.textContent = '';

    if (!videoId(urlInput.value)) {
        setError('Это не похоже на ссылку на ролик YouTube.');
        return;
    }

    // Collect fields before setBusy(): disabled controls are not included in FormData.
    const body = new FormData(form);
    setBusy(true);
    statusText.textContent = 'Скачиваю и конвертирую — обычно это занимает от нескольких секунд до пары минут.';
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
                if (data.errors?.bitrate) setBitrateError(data.errors.bitrate[0]);
                if (data.errors?.url || data.errors?.format || !data.errors?.bitrate) {
                    setError(data.errors?.url?.[0] || data.errors?.format?.[0] || data.message);
                }
            } else if (response.status === 429) {
                setError('Слишком много запросов. Подождите минуту.');
            } else if (response.status === 419) {
                setError('Сессия устарела. Обновите страницу.');
            } else {
                setError('Ошибка сервера (HTTP ' + response.status + ').');
            }
            statusText.textContent = '';
            return;
        }

        const name = filenameFrom(response.headers.get('Content-Disposition'));
        const blob = await response.blob();
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 60_000);

        const seconds = Math.round((Date.now() - started) / 1000);
        statusText.textContent = `Готово: «${name}» (${(blob.size / 1048576).toFixed(1)} МБ, ${seconds} с).`;
    } catch (e) {
        setError('Не удалось связаться с сервером: ' + e.message);
        statusText.textContent = '';
    } finally {
        setBusy(false);
    }
});
</script>
@endsection
