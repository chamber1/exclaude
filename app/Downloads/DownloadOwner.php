<?php

namespace App\Downloads;

use App\Models\DownloadTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who a task belongs to and which plan limits apply.
 *
 * - A signed-in user owns tasks by user_id; the plan comes from users.plan.
 * - A guest owns tasks by guest_id: an HMAC of a random token kept in the guest's session
 *   (so other visitors behind the same NAT cannot see them).
 * - client_hash is an HMAC of the IP address, used only for guest limits, so clearing cookies
 *   does not reset them. Raw IP addresses are not stored anywhere.
 */
final class DownloadOwner
{
    private const SESSION_KEY = 'downloads.guest_token';

    public function __construct(
        public readonly ?User $user,
        public readonly ?string $guestId,
        public readonly string $clientHash,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $user = $request->user();
        $guestId = null;

        if ($user === null && $request->hasSession()) {
            $token = $request->session()->get(self::SESSION_KEY);
            if (! is_string($token)) {
                $token = Str::random(40);
                $request->session()->put(self::SESSION_KEY, $token);
            }
            $guestId = self::hash('guest', $token);
        }

        return new self($user, $guestId, self::hash('ip', (string) $request->ip()));
    }

    public static function hash(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose.'|'.$value, (string) config('app.key'));
    }

    public function isGuest(): bool
    {
        return $this->user === null;
    }

    public function plan(): string
    {
        if ($this->user === null) {
            return 'guest';
        }

        $plan = (string) $this->user->plan;
        $expired = $this->user->plan_expires_at !== null && $this->user->plan_expires_at->isPast();

        return ! $expired && $plan !== 'guest' && array_key_exists($plan, config('downloads.plans')) ? $plan : 'free';
    }

    /** @return array{active: int, per_minute: int, per_day: int, max_height: int, max_filesize: string} */
    public function limits(): array
    {
        return config('downloads.plans.'.$this->plan());
    }

    /** Limits of the plan the task's owner has now (used by the queue job, where there is no request). */
    public static function limitsForTask(DownloadTask $task): array
    {
        return (new self($task->user, $task->guest_id, $task->client_hash))->limits();
    }

    /** Key for rate limits: per user, or per IP for guests. */
    public function limiterKey(): string
    {
        return $this->user !== null ? 'user:'.$this->user->id : 'ip:'.$this->clientHash;
    }

    public function owns(DownloadTask $task): bool
    {
        return $this->user !== null
            ? $task->user_id === $this->user->id
            : $task->user_id === null && $this->guestId !== null && hash_equals($task->guest_id ?? '', $this->guestId);
    }

    /** Tasks of this owner; for guests also counts tasks from the same IP (limits must not be reset by a new session). */
    public function scopeTasks(Builder $query, bool $forLimits = false): Builder
    {
        if ($this->user !== null) {
            return $query->where('user_id', $this->user->id);
        }

        return $query->whereNull('user_id')->where(function (Builder $q) use ($forLimits) {
            $q->where('guest_id', $this->guestId);
            if ($forLimits) {
                $q->orWhere('client_hash', $this->clientHash);
            }
        });
    }

    /** Fields stored on a new task. */
    public function attributes(): array
    {
        return [
            'user_id' => $this->user?->id,
            'guest_id' => $this->user === null ? $this->guestId : null,
            'client_hash' => $this->clientHash,
        ];
    }
}
