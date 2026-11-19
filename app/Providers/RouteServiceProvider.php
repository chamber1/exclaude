<?php

namespace App\Providers;

use App\Downloads\DownloadOwner;
use App\Http\Controllers\HealthController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->configureDownloadLimiters();

        $this->routes(function () {
            // Health check for load balancers and uptime monitors: no session, no cookies.
            Route::get('/up', HealthController::class)->name('health');

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Per-minute request limits of the download API. Users are limited per account, guests per IP
     * (HMAC). Daily quotas and concurrent task limits are enforced by DownloadService.
     */
    private function configureDownloadLimiters(): void
    {
        $tooMany = fn () => response()->json([
            'message' => 'Слишком много запросов. Подождите минуту.',
            'code' => 'rate_limited',
            'errors' => ['url' => ['Слишком много запросов. Подождите минуту.']],
        ], 429);

        RateLimiter::for('downloads', function (Request $request) use ($tooMany) {
            $owner = DownloadOwner::fromRequest($request);

            return Limit::perMinute($owner->limits()['per_minute'])->by('create:'.$owner->limiterKey())->response($tooMany);
        });

        RateLimiter::for('downloads-inspect', function (Request $request) use ($tooMany) {
            $owner = DownloadOwner::fromRequest($request);

            return Limit::perMinute($owner->isGuest() ? 10 : 30)->by('inspect:'.$owner->limiterKey())->response($tooMany);
        });

        // Status polling: the page asks every ~1.5 s.
        RateLimiter::for('downloads-status', function (Request $request) use ($tooMany) {
            return Limit::perMinute(120)->by('status:'.DownloadOwner::fromRequest($request)->limiterKey())->response($tooMany);
        });
    }
}
