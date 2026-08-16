<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Чек с телефона весит 3–8 МБ, а борд бухгалтера показывает чеки открытыми
 * десятками. Фото ужимается при загрузке; PDF остаётся нетронутым.
 */
class ReceiptCompressionTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->companies()->attach(Company::where('code', 'QT')->value('id'));

        return $user;
    }

    private function submit(UploadedFile $file): Expense
    {
        $this->actingAs($this->staff('employee'))->post(route('expenses.store'), [
            'category_id' => $this->category->id,
            'amount' => 3000,
            'date' => now()->toDateString(),
            'description' => 'чек',
            'file' => $file,
        ])->assertSessionHasNoErrors();

        return Expense::firstOrFail();
    }

    public function test_photo_receipt_is_shrunk_to_web_size(): void
    {
        $expense = $this->submit(UploadedFile::fake()->image('чек.jpg', 3200, 2400));

        Storage::disk('local')->assertExists($expense->file_path);

        [$width] = getimagesizefromstring(Storage::disk('local')->get($expense->file_path));
        $this->assertSame(1600, $width, 'Фото чека должно ужиматься до веб-размера.');
    }

    /** Маленький чек не растягивается: сжатие только уменьшает. */
    public function test_small_photo_keeps_its_size(): void
    {
        $expense = $this->submit(UploadedFile::fake()->image('чек.jpg', 800, 600));

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($expense->file_path));
        $this->assertSame([800, 600], [$width, $height]);
    }

    /** PDF-счёт — документ, его не пересобирают в картинку. */
    public function test_pdf_receipt_is_stored_as_is(): void
    {
        $pdf = UploadedFile::fake()->create('счёт.pdf', 120, 'application/pdf');
        $expense = $this->submit($pdf);

        Storage::disk('local')->assertExists($expense->file_path);
        $this->assertStringEndsWith('.pdf', $expense->file_path);
        // Картинкой его не пересобрали — файл остался документом.
        $this->assertFalse(@getimagesizefromstring(Storage::disk('local')->get($expense->file_path)));
    }

    /** Чек, приложенный бухгалтером при подтверждении, ужимается так же. */
    public function test_receipt_added_on_confirmation_is_shrunk(): void
    {
        $expense = $this->submit(UploadedFile::fake()->image('чек.jpg', 400, 300));
        $accountant = $this->staff('financist');

        $this->actingAs($accountant)->patch(route('expenses.confirm', $expense->id), [
            'payment_method' => 'cash',
            'file' => UploadedFile::fake()->image('свой-чек.jpg', 2400, 1800),
        ])->assertSessionHasNoErrors();

        [$width] = getimagesizefromstring(Storage::disk('local')->get($expense->fresh()->file_path));
        $this->assertSame(1600, $width);
    }
}
