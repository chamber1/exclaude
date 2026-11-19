<?php

namespace App\Downloads;

use App\Downloads\Exceptions\DownloadFailed;

/**
 * Maps yt-dlp output to a message for the user. Raw yt-dlp/system output never reaches the
 * user; it is only written to the downloads log.
 */
final class YtDlpErrors
{
    /** needle in yt-dlp output => [reason code, message] */
    private const KNOWN = [
        'does not pass filter' => ['filtered', 'Ролик не подходит под ограничения сервиса: слишком длинный, идёт трансляция или лицензия не разрешает скачивание.'],
        'larger than max-filesize' => ['too_large', 'Файл в этом качестве больше допустимого размера. Выберите качество пониже или звук.'],
        'File is larger than' => ['too_large', 'Файл в этом качестве больше допустимого размера. Выберите качество пониже или звук.'],
        'Private video' => ['private', 'Это приватное видео.'],
        'confirm your age' => ['age_restricted', 'У видео возрастное ограничение, без входа в аккаунт его скачать нельзя.'],
        'not a bot' => ['blocked', 'Платформа временно ограничивает запросы с сервера. Попробуйте позже.'],
        'HTTP Error 429' => ['blocked', 'Платформа временно ограничивает запросы с сервера. Попробуйте позже.'],
        'Video unavailable' => ['unavailable', 'Видео недоступно или удалено.'],
        'video is unavailable' => ['unavailable', 'Видео недоступно или удалено.'],
        'live event will begin' => ['live', 'Трансляция ещё не началась.'],
        'members-only' => ['members_only', 'Видео доступно только спонсорам канала.'],
        'requires payment' => ['paid', 'Видео платное, скачать его нельзя.'],
        'DRM protected' => ['drm', 'Видео защищено DRM, скачать его нельзя.'],
        'ffmpeg not found' => ['server_error', 'Сервис временно не может обработать файл. Мы уже знаем о проблеме.'],
        'No space left' => ['server_error', 'Сервис временно не может обработать файл. Мы уже знаем о проблеме.'],
    ];

    public static function failure(string $output, ?int $exitCode, string $what): DownloadFailed
    {
        foreach (self::KNOWN as $needle => [$reason, $message]) {
            if (stripos($output, $needle) !== false) {
                return new DownloadFailed($reason, $message);
            }
        }

        if ($exitCode === 126 || $exitCode === 127) {
            return new DownloadFailed('server_error', 'Сервис временно не может обработать файл. Мы уже знаем о проблеме.');
        }

        return new DownloadFailed('failed', "Не удалось скачать {$what} из этого ролика.");
    }

    public static function timeout(): DownloadFailed
    {
        return new DownloadFailed('timeout', 'Обработка заняла слишком много времени. Попробуйте ролик покороче или качество пониже.');
    }
}
