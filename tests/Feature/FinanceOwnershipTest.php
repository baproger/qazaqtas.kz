<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function manager(): User
    {
        $u = User::factory()->create();
        $u->assignRole('manager');
        return $u;
    }

    private function deal(User $owner): Deal
    {
        return Deal::create(['number' => 'D-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $owner->id]);
    }

    public function test_manager_cannot_pay_foreign_invoice(): void
    {
        $mgr = $this->manager();
        $other = $this->manager();
        $deal = $this->deal($other);
        $inv = Invoice::create(['number' => 'I-1', 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 1000, 'status' => 'sent']);

        $this->actingAs($mgr)->post(route('payments.store'), ['invoice_id' => $inv->id, 'amount' => 1000, 'payment_date' => now()->toDateString()])->assertForbidden();
    }

    public function test_manager_can_pay_own_invoice(): void
    {
        $mgr = $this->manager();
        $deal = $this->deal($mgr);
        $inv = Invoice::create(['number' => 'I-2', 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 1000, 'status' => 'sent']);

        $this->actingAs($mgr)->post(route('payments.store'), ['invoice_id' => $inv->id, 'amount' => 1000, 'payment_date' => now()->toDateString()])->assertRedirect();
    }

    public function test_manager_cannot_add_expense_to_foreign_deal(): void
    {
        $mgr = $this->manager();
        $other = $this->manager();
        $deal = $this->deal($other);
        Storage::fake('local');

        $this->actingAs($mgr)->post(route('expenses.store'), ['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 500, 'date' => now()->toDateString(), 'file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf')])->assertForbidden();
    }

    public function test_manager_cannot_create_invoice_on_foreign_deal(): void
    {
        $mgr = $this->manager();
        $other = $this->manager();
        $deal = $this->deal($other);

        $this->actingAs($mgr)->post(route('invoices.store'), ['invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 1000, 'status' => 'sent'])->assertForbidden();
    }

    public function test_manager_can_create_invoice_on_own_deal(): void
    {
        $mgr = $this->manager();
        $deal = $this->deal($mgr);

        $this->actingAs($mgr)->post(route('invoices.store'), ['invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 1000, 'status' => 'sent'])->assertRedirect();
    }
}
