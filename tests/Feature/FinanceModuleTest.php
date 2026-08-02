<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Invoice;
use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceModuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');
        return $u;
    }

    private function deal(): Deal
    {
        return Deal::create([
            'number' => 'BAIA-F-1', 'name' => 'D', 'budget' => 1000000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
    }

    public function test_finance_index_renders(): void
    {
        $u = $this->admin();
        $this->actingAs($u)->get(route('finance.index'))->assertOk();
    }

    public function test_payment_updates_invoice_status(): void
    {
        $u = $this->admin();
        $deal = $this->deal();

        $this->actingAs($u)->post(route('invoices.store'), [
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'amount' => 100000, 'status' => 'sent',
        ])->assertRedirect();

        $invoice = Invoice::first();
        $this->assertEquals('sent', $invoice->status);

        // Partial payment
        $this->actingAs($u)->post(route('payments.store'), [
            'invoice_id' => $invoice->id, 'amount' => 40000, 'payment_date' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertEquals('partially_paid', $invoice->fresh()->status);

        // Full payment
        $this->actingAs($u)->post(route('payments.store'), [
            'invoice_id' => $invoice->id, 'amount' => 60000, 'payment_date' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertEquals('paid', $invoice->fresh()->status);
    }

    public function test_margin_calculation(): void
    {
        $u = $this->admin();
        $deal = $this->deal();

        $this->actingAs($u)->post(route('invoices.store'), ['invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 100000, 'status' => 'sent']);
        $invoice = Invoice::first();
        $this->actingAs($u)->post(route('payments.store'), ['invoice_id' => $invoice->id, 'amount' => 100000, 'payment_date' => now()->toDateString()]);
        Storage::fake('local');
        $this->actingAs($u)->post(route('expenses.store'), ['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 40000, 'date' => now()->toDateString(), 'status' => 'confirmed', 'file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf')]);

        $summary = app(FinanceService::class)->summaryFor($deal->fresh());
        $this->assertEquals(100000.0, $summary['income']);
        $this->assertEquals(40000.0, $summary['expense']);
        $this->assertEquals(60000.0, $summary['profit']);
        $this->assertEquals(60.0, $summary['margin']);
    }
}
