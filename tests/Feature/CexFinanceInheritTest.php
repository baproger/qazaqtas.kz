<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CexFinanceInheritTest extends TestCase
{
    use RefreshDatabase;

    public function test_workshop_card_shows_deal_finance_after_transfer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $mgr = User::factory()->create();
        $mgr->assignRole('manager');

        $deal = Deal::create(['number' => 'D-1', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 500000, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id]);
        $inv = Invoice::create(['number' => 'INV-1', 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 300000, 'status' => 'sent']);
        Payment::create(['invoice_id' => $inv->id, 'amount' => 300000, 'payment_date' => now()->toDateString()]);
        Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 120000, 'date' => now()->toDateString(), 'status' => 'confirmed']);

        // transfer to Цех
        $this->actingAs($mgr)->post(route('deals.toWorkshop', $deal))->assertRedirect();
        $project = Project::where('deal_id', $deal->id)->firstOrFail();

        $this->actingAs($mgr)->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $p) => $p
                ->where('financeEntityType', 'deal')
                ->where('financeEntityId', $deal->id)
                ->where('finance.income', 300000)
                ->where('finance.expense', 120000)
                ->has('financeInvoices', 1)
                ->has('financeExpenses', 1));
    }
}
