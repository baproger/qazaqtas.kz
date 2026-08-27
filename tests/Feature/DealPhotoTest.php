<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Document;
use App\Models\User;
use App\Services\ImageCompressor;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Фото внутри сделки.
 *
 * Снимок объекта и отливки делают на телефон — 4–8 МБ. Храним сжатым:
 * длинная сторона 1600 px, качество 78. Показываем картинкой (inline), а не
 * скачиваем файлом — иначе в цехе фото не посмотреть.
 */
class DealPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $company = Company::where('code', 'QT')->value('id');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach($company);

        $this->deal = Deal::create([
            'company_id' => $company,
            'number' => 'QT-777',
            'name' => 'Двор ЖК',
            'company_name' => 'ТОО «Клиент»',
            'address' => 'г. Шымкент',
            'budget' => 1000000,
            'status' => 'active',
            'responsible_user_id' => $this->manager->id,
            'deal_stage_id' => DealStage::query()->orderBy('order')->value('id'),
        ]);
    }

    /** Крупный снимок ужимается до 1600 px по длинной стороне. */
    public function test_a_large_photo_is_downscaled(): void
    {
        $photo = UploadedFile::fake()->image('otlivka.jpg', 4000, 3000);
        $original = $photo->getSize();

        $this->actingAs($this->manager)
            ->post(route('documents.store'), [
                'documentable_type' => 'deal',
                'documentable_id' => $this->deal->id,
                'file' => $photo,
            ])->assertSessionHasNoErrors();

        $document = Document::firstWhere('documentable_id', $this->deal->id);
        $this->assertNotNull($document);

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($document->file_path));
        $this->assertSame(ImageCompressor::MAX_SIDE, $width);
        $this->assertSame(1200, $height, 'Пропорции снимка не должны меняться');
        $this->assertLessThan($original, $document->size, 'Записанный размер — уже сжатого файла');
    }

    /** Документ картинкой не является — его сохраняем байт в байт. */
    public function test_a_pdf_is_stored_untouched(): void
    {
        $bytes = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

        $this->actingAs($this->manager)->post(route('documents.store'), [
            'documentable_type' => 'deal',
            'documentable_id' => $this->deal->id,
            'file' => UploadedFile::fake()->createWithContent('dogovor.pdf', $bytes),
        ]);

        $document = Document::firstWhere('documentable_id', $this->deal->id);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame($bytes, Storage::disk('local')->get($document->file_path));
        $this->assertSame(strlen($bytes), $document->size);
    }

    /** Фото показывается в браузере, а не скачивается. */
    public function test_a_photo_is_shown_inline(): void
    {
        $this->actingAs($this->manager)->post(route('documents.store'), [
            'documentable_type' => 'deal',
            'documentable_id' => $this->deal->id,
            'file' => UploadedFile::fake()->image('obekt.jpg', 800, 600),
        ]);

        $document = Document::firstWhere('documentable_id', $this->deal->id);

        $response = $this->actingAs($this->manager)->get(route('documents.preview', $document->id));
        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * Inline отдаём только картинкам.
     *
     * Отдать inline произвольный файл — значит отдать браузеру то, что он
     * может исполнить с нашего домена.
     */
    public function test_a_document_is_never_shown_inline(): void
    {
        $this->actingAs($this->manager)->post(route('documents.store'), [
            'documentable_type' => 'deal',
            'documentable_id' => $this->deal->id,
            'file' => UploadedFile::fake()->create('dogovor.pdf', 10, 'application/pdf'),
        ]);

        $document = Document::firstWhere('documentable_id', $this->deal->id);

        $this->actingAs($this->manager)->get(route('documents.preview', $document->id))->assertNotFound();
    }

    /** Чужую сделку не открыть — значит, и её фото тоже. */
    public function test_a_stranger_cannot_see_the_photo(): void
    {
        $this->actingAs($this->manager)->post(route('documents.store'), [
            'documentable_type' => 'deal',
            'documentable_id' => $this->deal->id,
            'file' => UploadedFile::fake()->image('obekt.jpg', 800, 600),
        ]);

        $document = Document::firstWhere('documentable_id', $this->deal->id);

        $stranger = User::factory()->create();
        $stranger->assignRole('manager');

        $this->actingAs($stranger)->get(route('documents.preview', $document->id))->assertForbidden();
    }
}
