<?php

namespace Tests\Feature;

use App\Events\DealMovedToStage;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealItem;
use App\Models\DealStage;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Списание со склада при выигрыше сделки (закрытый долг §4 «Склад»).
 *
 * Сделка дошла до выигрышного этапа — позиции уходят со склада фирмы
 * движением deal_out (не глубже остатка: сделанное под заказ на складе не
 * лежало). Увели с won назад — списанное возвращается. Витринный флажок
 * «в наличии» следует за остатком.
 */
class StockDealFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private Product $tile;

    private int $company;

    private DealStage $won;

    private DealStage $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->company = Company::where('code', 'QT')->value('id');
        $this->director = User::factory()->create();
        $this->director->assignRole('director');

        $this->tile = Product::create([
            'name' => 'Плитка «Волна»', 'unit' => 'м²',
            'price' => 9000, 'is_active' => true, 'is_service' => false, 'in_stock' => false,
        ]);

        $this->won = DealStage::where('is_won', true)->orderBy('order')->firstOrFail();
        $this->work = DealStage::where('is_won', false)->orderBy('order')->firstOrFail();
    }

    private function deal(float $qty): Deal
    {
        $deal = Deal::create([
            'company_id' => $this->company, 'number' => 'QT-S-'.rand(1000, 9999), 'name' => 'Поставка',
            'budget' => 100, 'status' => 'active', 'deal_stage_id' => $this->work->id,
        ]);
        DealItem::create([
            'deal_id' => $deal->id, 'product_id' => $this->tile->id, 'name' => $this->tile->name,
            'unit' => 'м²', 'quantity' => $qty, 'price' => 9000, 'amount' => $qty * 9000,
        ]);

        return $deal;
    }

    private function seedStock(float $qty): void
    {
        app(StockService::class)->move($this->tile->id, $this->company, $qty, StockMovement::MANUAL_ADJUST, note: 'старт');
    }

    private function stock(): float
    {
        return app(StockService::class)->qty($this->tile->id, $this->company);
    }

    public function test_won_deal_writes_off_stock_and_updates_in_stock_flag(): void
    {
        $this->seedStock(500);
        $this->assertTrue($this->tile->refresh()->in_stock, 'приход должен включить «в наличии»');

        $deal = $this->deal(200);
        event(new DealMovedToStage($deal, $this->work, $this->won, $this->director));

        $this->assertSame(300.0, $this->stock());
        $this->assertDatabaseHas('stock_movements', [
            'type' => StockMovement::DEAL_OUT, 'product_id' => $this->tile->id, 'qty' => -200,
        ]);

        // Повторный перевод на won второй раз не списывает (идемпотентно).
        event(new DealMovedToStage($deal, $this->work, $this->won, $this->director));
        $this->assertSame(300.0, $this->stock());
    }

    public function test_write_off_never_goes_below_zero(): void
    {
        $this->seedStock(50);
        $deal = $this->deal(200); // 150 из позиции делались под заказ

        event(new DealMovedToStage($deal, $this->work, $this->won, $this->director));

        $this->assertSame(0.0, $this->stock());
        // Остаток кончился — витрина показывает «под заказ».
        $this->assertFalse($this->tile->refresh()->in_stock);
    }

    public function test_leaving_won_stage_returns_exactly_what_was_written_off(): void
    {
        $this->seedStock(50);
        $deal = $this->deal(200);

        event(new DealMovedToStage($deal, $this->work, $this->won, $this->director));
        $this->assertSame(0.0, $this->stock());

        // Откат с won: возвращаются списанные 50, а не 200 из позиции.
        event(new DealMovedToStage($deal, $this->won, $this->work, $this->director));
        $this->assertSame(50.0, $this->stock());
        $this->assertTrue($this->tile->refresh()->in_stock);
    }

    public function test_deal_without_stock_records_changes_nothing(): void
    {
        // Товар никогда не стоял на складском учёте: ручной флажок не трогаем.
        $this->tile->update(['in_stock' => true]);
        $deal = $this->deal(10);

        event(new DealMovedToStage($deal, $this->work, $this->won, $this->director));

        $this->assertDatabaseMissing('stock_movements', ['type' => StockMovement::DEAL_OUT]);
        $this->assertTrue($this->tile->refresh()->in_stock, 'ручной флажок должен остаться');
    }
}
