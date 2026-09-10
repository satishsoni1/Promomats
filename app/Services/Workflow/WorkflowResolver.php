<?php

namespace App\Services\Workflow;

use App\Models\Brand;
use App\Models\DocumentType;
use App\Models\WorkflowRule;
use App\Models\WorkflowTemplate;

/**
 * The flagship "generic, not hardcoded" piece of the engine: which workflow a
 * document should use is looked up from workflow_rules (admin-configurable data),
 * never branched on in PHP. A rule matches when its brand_id/document_type_id/
 * department each either equal the document's value or are null (a wildcard).
 * Among matching active rules, lower `priority` wins first; a tie is broken by
 * whichever rule is more specific (pins down more of the three dimensions), so a
 * general fallback rule and a brand-specific override can coexist at the same
 * priority without the outcome depending on row order.
 */
class WorkflowResolver
{
    public function resolve(?Brand $brand, ?DocumentType $documentType, ?string $department): ?WorkflowTemplate
    {
        $rule = WorkflowRule::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $brand?->id))
            ->where(fn ($q) => $q->whereNull('document_type_id')->orWhere('document_type_id', $documentType?->id))
            ->where(fn ($q) => $q->whereNull('department')->orWhere('department', $department))
            ->with('template')
            ->get()
            // priority is the dominant key; specificity (0-3) only breaks a tie
            // within the same priority, so it's kept well below priority's scale.
            ->sortBy(fn (WorkflowRule $r) => $r->priority * 10 - $r->specificity())
            ->first();

        $template = $rule?->template;

        // is_private templates are per-document customised copies (owner customisation
        // at upload) - never auto-resolved for a new document.
        return ($template && $template->is_active && ! $template->is_private) ? $template : null;
    }
}
