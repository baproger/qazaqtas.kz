<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentCommentTest extends TestCase
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
            'number' => 'BAIA-D-1', 'name' => 'D', 'budget' => 1, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
    }

    public function test_document_upload_and_versioning(): void
    {
        Storage::fake('local');
        $u = $this->admin();
        $deal = $this->deal();

        $this->actingAs($u)->post(route('documents.store'), [
            'documentable_type' => 'deal', 'documentable_id' => $deal->id,
            'name' => 'Договор', 'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        // Second upload with same name → version 2, previous deactivated.
        $this->actingAs($u)->post(route('documents.store'), [
            'documentable_type' => 'deal', 'documentable_id' => $deal->id,
            'name' => 'Договор', 'file' => UploadedFile::fake()->create('contract2.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->assertEquals(2, Document::count());
        $this->assertEquals(1, Document::where('name', 'Договор')->where('is_active', true)->count());
        $this->assertEquals(2, Document::where('name', 'Договор')->where('is_active', true)->value('version'));
    }

    public function test_comment_crud(): void
    {
        $u = $this->admin();
        $deal = $this->deal();

        $this->actingAs($u)->post(route('comments.store'), [
            'commentable_type' => 'deal', 'commentable_id' => $deal->id, 'body' => 'Первый коммент',
        ])->assertRedirect();

        $comment = Comment::first();
        $this->assertEquals($u->id, $comment->user_id);

        $this->actingAs($u)->put(route('comments.update', $comment), ['body' => 'Изменённый'])->assertRedirect();
        $comment->refresh();
        $this->assertEquals('Изменённый', $comment->body);
        $this->assertNotNull($comment->edited_at);

        $this->actingAs($u)->delete(route('comments.destroy', $comment))->assertRedirect();
        $this->assertEquals(0, Comment::count());
    }
}
