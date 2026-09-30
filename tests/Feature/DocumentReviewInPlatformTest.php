<?php

namespace Tests\Feature;

use App\Http\Controllers\WordReviewController;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\User;
use App\Services\DocumentVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reviewing documents on the platform instead of download -> mark up -> re-upload:
 * text-highlight comments + resolving on PDFs, and saving a Word document reviewed
 * in the browser (SuperDoc) back as a new version.
 */
class DocumentReviewInPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Notification::fake();
    }

    private function documentWithFile(User $owner, string $filename, string $contents = 'original'): Document
    {
        $document = Document::create([
            'title' => 'Doc',
            'reference_no' => 'REF-' . uniqid(),
            'owner_id' => $owner->id,
            'status' => 'draft',
        ]);

        app(DocumentVersionService::class)->storeNewVersion(
            $document,
            UploadedFile::fake()->createWithContent($filename, $contents),
            $owner,
        );

        return $document->fresh();
    }

    // ---- PDF: highlight comments + resolve ----

    public function test_a_reviewer_can_post_a_text_highlight_comment_tied_to_the_current_version(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $document = $this->documentWithFile($owner, 'brochure.pdf');
        $document->watchers()->attach($reviewer->id);

        $this->actingAs($reviewer)
            ->postJson(route('documents.comments.store', $document), [
                'body' => 'This claim needs a reference.',
                'page_number' => 2,
                'x_position' => 10.5,
                'y_position' => 40,
                'selected_text' => 'Clinically proven',
                'highlight_rects' => [['x' => 10.5, 'y' => 40, 'w' => 20, 'h' => 2.1]],
            ])
            ->assertOk();

        $comment = DocumentComment::firstOrFail();
        $this->assertSame('Clinically proven', $comment->selected_text);
        $this->assertEquals(20, $comment->highlight_rects[0]['w']);
        $this->assertSame($document->current_version_id, $comment->document_version_id);
    }

    public function test_highlight_rects_are_validated(): void
    {
        $owner = User::factory()->create();
        $document = $this->documentWithFile($owner, 'brochure.pdf');

        $this->actingAs($owner)
            ->postJson(route('documents.comments.store', $document), [
                'body' => 'x',
                'page_number' => 1,
                'x_position' => 1,
                'y_position' => 1,
                'selected_text' => 'words',
                'highlight_rects' => [['x' => 150, 'y' => 1, 'w' => 1, 'h' => 1]],
            ])
            ->assertUnprocessable();
    }

    public function test_only_the_author_owner_or_admin_can_resolve_a_comment(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $bystander = User::factory()->create();
        $document = $this->documentWithFile($owner, 'brochure.pdf');
        $document->watchers()->attach([$author->id, $bystander->id]);
        $comment = DocumentComment::create([
            'document_id' => $document->id,
            'user_id' => $author->id,
            'body' => 'Fix this',
            'page_number' => 1,
            'x_position' => 5,
            'y_position' => 5,
        ]);
        $url = route('documents.comments.resolve', [$document, $comment]);

        $this->actingAs($bystander)->patchJson($url, ['resolved' => true])->assertForbidden();

        $this->actingAs($owner)->patchJson($url, ['resolved' => true])
            ->assertOk()
            ->assertJson(['resolved' => true, 'resolved_by' => $owner->name]);
        $this->assertNotNull($comment->fresh()->resolved_at);

        $this->actingAs($author)->patchJson($url, ['resolved' => false])->assertOk();
        $this->assertNull($comment->fresh()->resolved_at);
    }

    // ---- Word: SuperDoc in-browser review ----

    public function test_editor_role_follows_upload_rights_and_legal_hold(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $document = $this->documentWithFile($owner, 'leaflet.docx');

        $this->assertSame('editor', WordReviewController::editorRole($document, $owner));
        $this->assertSame('suggester', WordReviewController::editorRole($document, $reviewer));

        $document->forceFill(['legal_hold' => true])->save();
        $this->assertSame('viewer', WordReviewController::editorRole($document->fresh(), $owner));
    }

    public function test_a_reviewer_saving_tracked_changes_creates_a_new_version_attributed_to_them(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create(['name' => 'Rita Reviewer']);
        $document = $this->documentWithFile($owner, 'leaflet.docx');
        $document->watchers()->attach($reviewer->id);

        $this->actingAs($reviewer)
            ->post(route('documents.word-review.store', $document), [
                'file' => UploadedFile::fake()->createWithContent('leaflet.docx', 'edited'),
                'base_version_id' => $document->current_version_id,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['version_no' => 2]);

        $document->refresh();
        $this->assertSame(2, $document->versions()->count());
        $this->assertSame($reviewer->id, $document->currentVersion->uploaded_by);
        $this->assertStringContainsString('suggested in the browser by Rita Reviewer', $document->currentVersion->change_notes);
    }

    public function test_saving_over_a_newer_version_is_refused(): void
    {
        $owner = User::factory()->create();
        $document = $this->documentWithFile($owner, 'leaflet.docx');
        $staleVersionId = $document->current_version_id;
        app(DocumentVersionService::class)->storeNewVersion($document, UploadedFile::fake()->createWithContent('leaflet.docx', 'v2'), $owner);

        $this->actingAs($owner)
            ->post(route('documents.word-review.store', $document), [
                'file' => UploadedFile::fake()->createWithContent('leaflet.docx', 'edited'),
                'base_version_id' => $staleVersionId,
            ], ['Accept' => 'application/json'])
            ->assertStatus(409);

        $this->assertSame(2, $document->versions()->count());
    }

    public function test_only_docx_files_are_accepted(): void
    {
        $owner = User::factory()->create();
        $document = $this->documentWithFile($owner, 'leaflet.docx');

        $this->actingAs($owner)
            ->post(route('documents.word-review.store', $document), [
                'file' => UploadedFile::fake()->createWithContent('evil.html', '<script>'),
                'base_version_id' => $document->current_version_id,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_nothing_can_be_saved_under_legal_hold(): void
    {
        $owner = User::factory()->create();
        $document = $this->documentWithFile($owner, 'leaflet.docx');
        $document->forceFill(['legal_hold' => true])->save();

        $response = $this->actingAs($owner)
            ->post(route('documents.word-review.store', $document), [
                'file' => UploadedFile::fake()->createWithContent('leaflet.docx', 'edited'),
                'base_version_id' => $document->current_version_id,
            ], ['Accept' => 'application/json']);

        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertSame(1, $document->versions()->count());
    }
}
