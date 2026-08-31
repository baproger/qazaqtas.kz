<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Область доступа контрагентов: справочник общий для фирм холдинга
 * (осознанно), но со значением «Свои» менеджер видит только своих и ничейных.
 */
class ClientScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_only_own_and_unassigned_clients(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $other = User::factory()->create();

        $mine = Client::create(['name' => 'Мой клиент', 'type' => 'legal', 'responsible_user_id' => $manager->id]);
        $foreign = Client::create(['name' => 'Чужой клиент', 'type' => 'legal', 'responsible_user_id' => $other->id]);
        $free = Client::create(['name' => 'Ничей клиент', 'type' => 'legal']);

        $this->actingAs($manager)->get(route('clients.index'))->assertOk()->assertInertia(fn ($p) => $p
            ->has('clients.data', 2));

        // Чужая карточка закрыта и напрямую по id.
        $this->assertTrue($manager->can('view', $mine));
        $this->assertTrue($manager->can('view', $free));
        $this->assertFalse($manager->can('view', $foreign));
        $this->assertFalse($manager->can('update', $foreign));
    }

    public function test_leadership_sees_the_whole_directory(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $director = User::factory()->create();
        $director->assignRole('director');
        $other = User::factory()->create();

        Client::create(['name' => 'А', 'type' => 'legal', 'responsible_user_id' => $other->id]);
        Client::create(['name' => 'Б', 'type' => 'legal']);

        $this->actingAs($director)->get(route('clients.index'))->assertOk()->assertInertia(fn ($p) => $p
            ->has('clients.data', 2));
    }
}
