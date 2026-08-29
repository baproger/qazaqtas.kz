<?php

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Журнал ошибок: от 404 до исключения, только админу, повторы схлопываются. */
class ErrorLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withoutExceptionHandling([]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    public function test_404_is_recorded_as_info_and_repeats_are_counted(): void
    {
        $this->withExceptionHandling();
        $this->get('/no-such-page')->assertNotFound();
        $this->get('/no-such-page')->assertNotFound();

        $log = ErrorLog::firstOrFail();
        $this->assertSame('info', $log->level);
        $this->assertSame(404, $log->status);
        $this->assertSame(2, $log->count);
        $this->assertSame(1, ErrorLog::count());
    }

    public function test_exception_is_recorded_as_error_with_trace(): void
    {
        $this->withExceptionHandling();
        Route::get('/boom', fn () => throw new \RuntimeException('Сломалось'))->middleware('web');

        $this->actingAs($this->user('manager'))->get('/boom')->assertStatus(500);

        $log = ErrorLog::firstOrFail();
        $this->assertSame('error', $log->level);
        $this->assertSame('RuntimeException', $log->kind);
        $this->assertSame('Сломалось', $log->message);
        $this->assertNotEmpty($log->trace);
        $this->assertNotNull($log->user_id);
    }

    public function test_browser_errors_are_accepted_without_login(): void
    {
        $this->postJson(route('errors.browser'), ['message' => 'x is not a function', 'kind' => 'TypeError', 'url' => 'http://localhost/catalog'])
            ->assertOk();

        $log = ErrorLog::firstOrFail();
        $this->assertSame('browser', $log->source);
        $this->assertSame('warning', $log->level);
    }

    public function test_journal_is_admin_only_and_resolving_works(): void
    {
        $this->withExceptionHandling();
        $log = ErrorLog::create(['level' => 'error', 'source' => 'server', 'kind' => 'X', 'fingerprint' => 'f', 'message' => 'm',
            'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->actingAs($this->user('director'))->get(route('errors.index'))->assertForbidden();
        // Сам отказ в доступе — тоже запись журнала (предупреждение).
        $this->assertSame('warning', ErrorLog::where('status', 403)->firstOrFail()->level);

        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('errors.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Errors/Index')->where('openTotal', 2));

        $this->actingAs($admin)->patch(route('errors.resolve', $log))->assertRedirect();
        $this->assertNotNull($log->fresh()->resolved_at);
        $this->assertSame(1, ErrorLog::open()->count());
        // Разобранная ошибка при повторе заводит НОВУЮ строку — значит, не починили.
        $this->actingAs($admin)->patch(route('errors.resolve', $log))->assertRedirect(); // открыть снова
        $this->assertNull($log->fresh()->resolved_at);
    }
}
