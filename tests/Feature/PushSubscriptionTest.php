<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Web Push подписки: браузер сотрудника регистрируется на пуши чата. */
class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private const SUB = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'BPubKey', 'auth' => 'authKey'],
    ];

    public function test_user_can_subscribe_and_resubscribe_updates_owner(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a)->postJson(route('push.subscribe'), self::SUB)->assertOk();
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $a->id, 'endpoint' => self::SUB['endpoint']]);

        // Тот же браузер, другой пользователь (общий комп) — подписка переезжает к нему.
        $this->actingAs($b)->postJson(route('push.subscribe'), self::SUB)->assertOk();
        $this->assertSame(1, PushSubscription::count());
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $b->id, 'endpoint' => self::SUB['endpoint']]);
    }

    public function test_user_can_unsubscribe_only_own(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        PushSubscription::create(['user_id' => $a->id, 'endpoint' => self::SUB['endpoint'], 'p256dh' => 'k', 'auth' => 'k']);

        // Чужую подписку не удалить.
        $this->actingAs($b)->postJson(route('push.unsubscribe'), ['endpoint' => self::SUB['endpoint']])->assertOk();
        $this->assertSame(1, PushSubscription::count());

        $this->actingAs($a)->postJson(route('push.unsubscribe'), ['endpoint' => self::SUB['endpoint']])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_guest_cannot_subscribe(): void
    {
        // Гость не проходит auth-middleware (редирект на логин), подписка не создаётся.
        $this->post(route('push.subscribe'), self::SUB)->assertRedirect();
        $this->assertSame(0, PushSubscription::count());
    }
}
