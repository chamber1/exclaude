<?php

namespace App\Downloads\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A request we refuse for a reason the user should see (bad link, limits, content not allowed).
 * Rendered as JSON in the shape of a validation error, so the page shows it under the URL field.
 * The message is always written by us, never taken from yt-dlp or the system.
 */
class DownloadRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function invalidUrl(): self
    {
        return new self('invalid_url', 'Это не похоже на ссылку на ролик YouTube.');
    }

    public static function unsupportedSite(): self
    {
        return new self('unsupported_site', 'Этот сайт не поддерживается. Сейчас можно скачивать только с YouTube.');
    }

    public static function unresolvableHost(): self
    {
        return new self('unresolvable_host', 'Не удалось проверить адрес ролика. Попробуйте позже.', 503);
    }

    public static function privateAddress(): self
    {
        return new self('private_address', 'Этот адрес недоступен для скачивания.');
    }

    public static function qualityNotAllowed(int $maxHeight, bool $guest): self
    {
        return new self('quality_not_allowed', $guest
            ? "Без входа доступно качество до {$maxHeight}p. Войдите, чтобы скачать в более высоком качестве."
            : "На вашем тарифе доступно качество до {$maxHeight}p.");
    }

    public static function tooManyActive(int $limit): self
    {
        return new self('too_many_active', $limit === 1
            ? 'Дождитесь окончания текущей загрузки.'
            : "Одновременно можно обрабатывать не больше {$limit} загрузок. Дождитесь окончания текущих.", 429);
    }

    public static function dailyLimit(int $limit, bool $guest): self
    {
        return new self('daily_limit', "Достигнут дневной лимит: {$limit} загрузок за сутки."
            .($guest ? ' Войдите или зарегистрируйтесь, чтобы получить больше.' : ''), 429);
    }

    public static function serviceBusy(): self
    {
        return new self('service_busy', 'Сервис сейчас перегружен. Попробуйте через несколько минут.', 503);
    }

    /** An expected outcome, not an application error: goes to the downloads log only. */
    public function report(): bool
    {
        Log::channel('downloads')->notice('rejected: '.$this->reason, ['status' => $this->status, 'message' => $this->getMessage()]);

        return true;
    }

    public function render(Request $request): JsonResponse|false
    {
        if (! $request->expectsJson()) {
            return false;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->reason,
            'errors' => ['url' => [$this->getMessage()]],
        ], $this->status);
    }
}
