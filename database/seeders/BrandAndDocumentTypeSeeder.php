<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\WorkflowRule;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Seeder;

/**
 * Brand and Document Type masters (spec REQ-10/11), plus the workflow_rules rows
 * that connect them to the two reference workflows HimalayaPromoMatsPdfWorkflowSeeder
 * / HimalayaPromoMatsDocWorkflowSeeder already seed (Workflow A: PDF/JPG/GIF,
 * Workflow B: Word/Video/PPT) - this is what makes WorkflowResolver actually resolve
 * something out of the box instead of every document needing a manual pick.
 */
class BrandAndDocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@globalspace.in')->first() ?? User::first();

        $brands = [
            'Liv.52', 'Pilex', 'Hadjod', 'Liv.52 Sugar Free', 'Vasaka', 'Probe Journal', 'Public Awareness Video',
        ];

        foreach ($brands as $name) {
            Brand::firstOrCreate(
                ['code' => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::slug($name, '_'))],
                ['name' => $name, 'status' => 'active', 'created_by' => $admin?->id]
            );
        }

        $documentTypes = [
            ['name' => 'PDF', 'code' => 'PDF', 'extensions' => ['pdf'], 'max_kb' => 51200],
            ['name' => 'JPG', 'code' => 'JPG', 'extensions' => ['jpg', 'jpeg'], 'max_kb' => 20480],
            ['name' => 'GIF', 'code' => 'GIF', 'extensions' => ['gif'], 'max_kb' => 20480],
            ['name' => 'WORD', 'code' => 'WORD', 'extensions' => ['doc', 'docx'], 'max_kb' => 51200],
            ['name' => 'PPT', 'code' => 'PPT', 'extensions' => ['ppt', 'pptx'], 'max_kb' => 102400],
            ['name' => 'VIDEO', 'code' => 'VIDEO', 'extensions' => ['mp4', 'mov', 'webm', 'm4v'], 'max_kb' => 512000],
        ];

        foreach ($documentTypes as $type) {
            DocumentType::firstOrCreate(
                ['code' => $type['code']],
                [
                    'name' => $type['name'],
                    'allowed_extensions' => $type['extensions'],
                    'max_file_size_kb' => $type['max_kb'],
                    'status' => 'active',
                ]
            );
        }

        // Latest active version of each reference workflow family.
        $pdfWorkflow = WorkflowTemplate::where('family_code', 'HW_PROMOMATS_PDF')->where('is_active', true)->first();
        $docWorkflow = WorkflowTemplate::where('family_code', 'HW_PROMOMATS_DOC')->where('is_active', true)->first();

        if ($pdfWorkflow) {
            foreach (['PDF', 'JPG', 'GIF'] as $code) {
                $this->rule($pdfWorkflow, $code, $admin);
            }
        }

        if ($docWorkflow) {
            foreach (['WORD', 'PPT', 'VIDEO'] as $code) {
                $this->rule($docWorkflow, $code, $admin);
            }
        }

        // Scientific Publications uploads a Word source (Content Generation) or a
        // PDF proof (Proof Generation) and must never fall through to the pharma
        // PromoMats workflow above - that's exactly what happened to Shruthi Kumar's
        // Evecare trial upload (RE: VODO approval system, 2026-09-18, item 5): no
        // department-scoped rule existed, so the WORD-document-type wildcard rule
        // (department = null, same priority) won and routed her into "Content
        // Manager Brief Review" instead of "Content Review (Resource Selection)".
        // These rules share priority 100 with the wildcard ones above; WorkflowRule::
        // specificity() breaks the tie in favor of the more specific (department-set)
        // rule, so they take over only for uploads made by a Scientific Publications
        // department user - everyone else's WORD/PDF uploads are unaffected.
        $sciPubWord = WorkflowTemplate::where('code', 'SCIPUB_WF1')->where('is_active', true)->first();
        $sciPubProof = WorkflowTemplate::where('code', 'SCIPUB_WF2')->where('is_active', true)->first();

        if ($sciPubWord) {
            $this->rule($sciPubWord, 'WORD', $admin, department: 'Scientific Publications');
        }

        if ($sciPubProof) {
            $this->rule($sciPubProof, 'PDF', $admin, department: 'Scientific Publications');
        }
    }

    protected function rule(WorkflowTemplate $template, string $documentTypeCode, ?User $admin, ?string $department = null): void
    {
        $documentType = DocumentType::where('code', $documentTypeCode)->first();
        if (! $documentType) {
            return;
        }

        WorkflowRule::firstOrCreate(
            [
                'workflow_template_id' => $template->id,
                'brand_id' => null, // applies to every brand
                'document_type_id' => $documentType->id,
                'department' => $department,
            ],
            ['priority' => 100, 'status' => 'active', 'created_by' => $admin?->id]
        );
    }
}
