<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-document "open editing to my department" toggle
 * (documents.allow_department_editing) and the DocumentPolicy branch it enables.
 */
class DocumentEditingAccessTest extends TestCase
{
    use RefreshDatabase;

    private function document(User $owner, bool $open = false): Document
    {
        return Document::create([
            'title' => 'Doc',
            'reference_no' => 'REF-' . uniqid(),
            'owner_id' => $owner->id,
            'status' => 'draft',
            'allow_department_editing' => $open,
        ]);
    }

    public function test_closed_by_default_only_owner_can_edit(): void
    {
        $owner = User::factory()->create(['department' => 'Legal']);
        $colleague = User::factory()->create(['department' => 'Legal']);
        $document = $this->document($owner);

        $this->assertTrue($owner->can('update', $document));
        $this->assertFalse($colleague->can('update', $document));
    }

    public function test_when_open_a_same_department_colleague_can_edit_but_an_outsider_cannot(): void
    {
        $owner = User::factory()->create(['department' => 'Legal']);
        $colleague = User::factory()->create(['department' => 'Legal']);
        $outsider = User::factory()->create(['department' => 'Marketing']);
        $document = $this->document($owner, open: true);

        $this->assertTrue($colleague->can('update', $document));
        $this->assertTrue($colleague->can('uploadVersion', $document));
        $this->assertFalse($outsider->can('update', $document));

        // Submitting into the workflow still stays with the owner.
        $this->assertFalse($colleague->can('submitForReview', $document));
    }

    public function test_only_owner_or_admin_can_flip_the_toggle(): void
    {
        $owner = User::factory()->create(['department' => 'Legal']);
        $colleague = User::factory()->create(['department' => 'Legal']);
        $document = $this->document($owner, open: true);

        // A granted department editor can edit the doc but not re-open/lock it.
        $this->actingAs($colleague)
            ->post(route('documents.editing-access.update', $document), ['allow_department_editing' => '0'])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('documents.editing-access.update', $document), ['allow_department_editing' => '0'])
            ->assertRedirect();

        $this->assertFalse($document->fresh()->allow_department_editing);
    }
}
