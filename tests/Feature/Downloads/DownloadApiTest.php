<?php

namespace Tests\Feature\Downloads;

use App\Downloads\DownloadOwner;
use App\Downloads\DownloadStatus;
use App\Jobs\DownloadJob;
use App\Models\DownloadTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DownloadApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://youtu.be/jNQXAC9IVRw';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeYtDlp();
    }

    private function store(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/downloads', $data + [
            'url' => self::URL, 'type' => 'audio', 'format' => 'mp3', 'bitrate' => '128', 'rights' => '1',
        ]);
    }

    // ---------------------------------------------------------------- inspect

    public function test_inspect_returns_metadata_and_guest_limits(): void
    {
        $this->postJson('/downloads/inspect', ['url' => self::URL])
            ->assertOk()
            ->assertJsonPath('id', 'jNQXAC9IVRw')
            ->assertJsonPath('title', 'Me at the zoo')
            ->assertJsonPath('max_available_height', 1080)
            ->assertJsonPath('creative_commons', false)
            ->assertJsonPath('limits.plan', 'guest')
            ->assertJsonPath('limits.max_height', 720)
            ->assertJsonFragment(['value' => '1080', 'available' => true, 'allowed' => false])
            ->assertJsonFragment(['value' => '1440', 'available' => false, 'allowed' => false]);
    }

    public function test_inspect_for_a_signed_in_user_allows_higher_quality(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/downloads/inspect', ['url' => self::URL])
            ->assertOk()
            ->assertJsonPath('limits.plan', 'free')
            ->assertJsonFragment(['value' => '1080', 'available' => true, 'allowed' => true]);
    }

    public function test_metadata_is_cached_per_video(): void
    {
        $this->postJson('/downloads/inspect', ['url' => self::URL])->assertOk();
        $this->postJson('/downloads/inspect', ['url' => 'https://www.youtube.com/watch?v=jNQXAC9IVRw'])->assertOk();

        Process::assertRanTimes(fn ($process) => in_array('--dump-single-json', (array) $process->command, true), 1);
    }

    public static function rejectedLinks(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/admin', 'invalid_url'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', 'invalid_url'],
            'docker service' => ['http://mysql:3306', 'invalid_url'],
            'decimal ip' => ['http://2130706433/', 'invalid_url'],
            'file' => ['file:///etc/passwd', 'invalid_url'],
            'look-alike' => ['https://youtube.com.evil.test/watch?v=jNQXAC9IVRw', 'unsupported_site'],
            'other site' => ['https://example.com/video.mp4', 'unsupported_site'],
        ];
    }

    #[DataProvider('rejectedLinks')]
    public function test_ssrf_and_foreign_links_never_reach_yt_dlp(string $url, string $code): void
    {
        $this->postJson('/downloads/inspect', ['url' => $url])
            ->assertStatus(422)
            ->assertJsonPath('code', $code)
            ->assertJsonValidationErrors('url');

        $this->store(['url' => $url])->assertStatus(422)->assertJsonPath('code', $code);

        Process::assertNothingRan();
        $this->assertDatabaseCount('download_tasks', 0);
    }

    public function test_host_resolving_to_a_private_address_is_rejected(): void
    {
        $this->fakeDns(['10.0.0.5']);

        $this->postJson('/downloads/inspect', ['url' => self::URL])
            ->assertStatus(422)
            ->assertJsonPath('code', 'private_address');
        Process::assertNothingRan();
    }

    public function test_too_long_and_live_videos_are_rejected(): void
    {
        $this->fakeYtDlp($this->videoJson(['duration' => 3601]));
        $this->postJson('/downloads/inspect', ['url' => self::URL])->assertStatus(422)->assertJsonPath('code', 'too_long');

        $this->fakeYtDlp($this->videoJson(['id' => 'aaaaaaaaaaa', 'is_live' => true]));
        $this->postJson('/downloads/inspect', ['url' => 'https://youtu.be/aaaaaaaaaaa'])->assertStatus(422)->assertJsonPath('code', 'live');
    }

    public function test_creative_commons_policy(): void
    {
        config(['downloads.license_policy' => 'creative_commons']);

        $this->postJson('/downloads/inspect', ['url' => self::URL])->assertStatus(422)->assertJsonPath('code', 'license');

        $this->fakeYtDlp($this->videoJson(['id' => 'bbbbbbbbbbb', 'license' => 'Creative Commons Attribution license (reuse allowed)']));
        $this->postJson('/downloads/inspect', ['url' => 'https://youtu.be/bbbbbbbbbbb'])
            ->assertOk()
            ->assertJsonPath('creative_commons', true);
    }

    public function test_yt_dlp_failure_is_shown_without_internal_details(): void
    {
        Process::fake(fn () => Process::result('', "ERROR: [youtube] jNQXAC9IVRw: Video unavailable\n/usr/local/bin/yt-dlp line 1", 1));

        $response = $this->postJson('/downloads/inspect', ['url' => self::URL])->assertStatus(422)->assertJsonPath('code', 'unavailable');

        $this->assertStringNotContainsString('/usr/local', $response->getContent());
        $this->assertStringNotContainsString('ERROR', $response->getContent());
    }

    // ---------------------------------------------------------------- create

    public function test_validation_errors(): void
    {
        $this->postJson('/downloads', ['type' => 'audio', 'format' => 'flac', 'bitrate' => '999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['url', 'format', 'bitrate', 'rights']);

        $this->postJson('/downloads', ['url' => self::URL, 'type' => 'video', 'quality' => '4320', 'rights' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quality'])
            ->assertJsonMissingValidationErrors(['format', 'bitrate']);

        $this->assertDatabaseCount('download_tasks', 0);
    }

    public function test_rights_confirmation_is_required(): void
    {
        $this->store(['rights' => null])->assertStatus(422)->assertJsonValidationErrors('rights');
    }

    public function test_task_is_created_and_queued(): void
    {
        Queue::fake();

        $response = $this->store()->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('progress', 0)
            ->assertJsonPath('type', 'audio')
            ->assertJsonPath('format', 'mp3')
            ->assertJsonPath('bitrate', '128')
            ->assertJsonPath('file', null);

        $task = DownloadTask::sole();
        $this->assertSame($task->uuid, $response->json('id'));
        $this->assertSame('/downloads/'.$task->uuid, $response->json('status_url'));
        $this->assertNull($task->user_id);
        $this->assertNotNull($task->guest_id);
        // The IP is stored only as an HMAC.
        $this->assertSame(DownloadOwner::hash('ip', '127.0.0.1'), $task->client_hash);
        $this->assertStringNotContainsString('127.0.0.1', json_encode($task->getAttributes()));

        Queue::assertPushedOn('downloads', DownloadJob::class, fn (DownloadJob $job) => $job->taskId === $task->id);
    }

    public function test_video_task_stores_quality_and_caps_best_for_guests(): void
    {
        Queue::fake();

        $this->store(['type' => 'video', 'quality' => 'best', 'format' => null, 'bitrate' => null])
            ->assertStatus(202)
            ->assertJsonPath('format', 'mp4')
            ->assertJsonPath('quality', '720')
            ->assertJsonPath('bitrate', null);
    }

    public function test_guest_cannot_request_quality_above_the_limit(): void
    {
        Queue::fake();

        $this->store(['type' => 'video', 'quality' => '1080'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'quality_not_allowed');
        Queue::assertNothingPushed();
    }

    public function test_user_can_request_1080(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->store(['type' => 'video', 'quality' => '1080'])
            ->assertStatus(202)
            ->assertJsonPath('quality', '1080');
    }

    public function test_guest_active_limit(): void
    {
        Queue::fake();

        $this->store()->assertStatus(202);
        $this->store()->assertStatus(429)->assertJsonPath('code', 'too_many_active');
        $this->assertDatabaseCount('download_tasks', 1);
    }

    public function test_guest_active_limit_is_not_reset_by_a_new_session(): void
    {
        Queue::fake();

        $this->store()->assertStatus(202);
        $this->flushSession();
        $this->store()->assertStatus(429)->assertJsonPath('code', 'too_many_active');
    }

    public function test_guest_daily_limit(): void
    {
        Queue::fake();
        config(['downloads.plans.guest.per_day' => 2, 'downloads.plans.guest.active' => 10, 'downloads.plans.guest.per_minute' => 100]);

        $this->store()->assertStatus(202);
        $this->store()->assertStatus(202);
        $this->store()->assertStatus(429)->assertJsonPath('code', 'daily_limit');
    }

    public function test_per_minute_rate_limit(): void
    {
        Queue::fake();
        config(['downloads.plans.guest.per_minute' => 2, 'downloads.plans.guest.active' => 10]);

        $this->store()->assertStatus(202);
        $this->store()->assertStatus(202);
        $this->store()->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_global_queue_limit(): void
    {
        Queue::fake();
        config(['downloads.max_queued' => 1]);
        DownloadTask::factory()->create(['client_hash' => DownloadOwner::hash('ip', '198.51.100.1'), 'guest_id' => 'x']);

        $this->store()->assertStatus(503)->assertJsonPath('code', 'service_busy');
    }

    public function test_premium_plan_and_its_expiry(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['plan' => 'premium', 'plan_expires_at' => now()->addDay()])->save();

        $this->actingAs($user)->postJson('/downloads/inspect', ['url' => self::URL])->assertJsonPath('limits.plan', 'premium');

        $user->forceFill(['plan_expires_at' => now()->subMinute()])->save();
        $this->actingAs($user->fresh())->postJson('/downloads/inspect', ['url' => self::URL])->assertJsonPath('limits.plan', 'free');
    }

    // ---------------------------------------------------------------- full flow (sync queue)

    public function test_full_flow_create_process_status_and_single_use_download(): void
    {
        config(['downloads.x_accel_prefix' => '']);

        $created = $this->store()->assertStatus(202);
        $status = $this->getJson($created->json('status_url'))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('progress', 100)
            ->assertJsonPath('file.name', 'Me at the zoo (128 kbps).mp3')
            ->assertJsonPath('file.size', strlen('fake media'));

        $url = $status->json('file.url');
        $this->assertStringStartsWith('/downloads/'.$created->json('id').'/file?expires=', $url);

        $this->get($url)->assertOk()->assertDownload('Me at the zoo (128 kbps).mp3');
        $this->get($url)->assertStatus(410);

        $this->getJson($created->json('status_url'))
            ->assertJsonPath('status', 'expired')
            ->assertJsonPath('downloaded', true)
            ->assertJsonPath('file', null);
    }

    public function test_index_lists_only_own_tasks(): void
    {
        Queue::fake();
        $this->store()->assertStatus(202);
        DownloadTask::factory()->forUser(User::factory()->create())->create();

        $this->getJson('/downloads')->assertOk()->assertJsonCount(1);
    }

    // ---------------------------------------------------------------- ownership and links

    public function test_other_visitors_cannot_see_a_task(): void
    {
        Queue::fake();
        $statusUrl = $this->store()->json('status_url');

        $this->getJson($statusUrl)->assertOk();

        $this->flushSession();
        $this->getJson($statusUrl)->assertNotFound();

        $this->actingAs(User::factory()->create())->getJson($statusUrl)->assertNotFound();
    }

    public function test_user_task_is_hidden_from_guests_and_other_users(): void
    {
        $owner = User::factory()->create();
        $task = DownloadTask::factory()->forUser($owner)->completed()->create();

        $this->actingAs($owner)->getJson('/downloads/'.$task->uuid)->assertOk()->assertJsonPath('status', 'completed');
        $this->actingAs(User::factory()->create())->getJson('/downloads/'.$task->uuid)->assertNotFound();
    }

    public function test_file_link_requires_a_valid_unexpired_signature_and_the_owner(): void
    {
        config(['downloads.x_accel_prefix' => '']);
        $owner = User::factory()->create();
        $task = DownloadTask::factory()->forUser($owner)->completed()->create();
        $url = $this->actingAs($owner)->getJson('/downloads/'.$task->uuid)->json('file.url');

        $this->get('/downloads/'.$task->uuid.'/file')->assertForbidden();
        $this->get(substr($url, 0, -1).(str_ends_with($url, '0') ? '1' : '0'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();

        $this->travel(61)->minutes();
        $this->actingAs($owner)->get($url)->assertForbidden();
        $this->assertSame(DownloadStatus::Completed, $task->fresh()->status);
    }

    public function test_nginx_delivery_uses_x_accel_redirect(): void
    {
        config(['downloads.x_accel_prefix' => '/_protected/downloads']);
        $owner = User::factory()->create();
        $task = DownloadTask::factory()->forUser($owner)->completed()->create(['file_name' => 'Ролик "x".mp3']);
        $url = $this->actingAs($owner)->getJson('/downloads/'.$task->uuid)->json('file.url');

        $response = $this->get($url)->assertOk()->assertHeader('X-Accel-Redirect', '/_protected/downloads/'.$task->uuid.'/media.mp3');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
        $this->assertStringNotContainsString('"x"', $disposition);
        $this->assertSame('', $response->getContent());
        $this->assertSame(DownloadStatus::Expired, $task->fresh()->status);
        // nginx is still sending it: the cleanup keeps the file for a while.
        $this->assertFileExists($task->absoluteFilePath());
    }

    public function test_file_can_be_downloaded_repeatedly_when_single_use_is_off(): void
    {
        config(['downloads.x_accel_prefix' => '', 'downloads.delete_after_download' => false]);
        $owner = User::factory()->create();
        $task = DownloadTask::factory()->forUser($owner)->completed()->create();
        $url = $this->actingAs($owner)->getJson('/downloads/'.$task->uuid)->json('file.url');

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->assertNotNull($task->fresh()->downloaded_at);
    }
}
