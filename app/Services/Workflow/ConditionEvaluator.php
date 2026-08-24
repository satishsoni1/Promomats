<?php

namespace App\Services\Workflow;

use App\Models\Document;

/**
 * Evaluates a workflow_stages.condition_json value against a Document (spec
 * REQ-53) to decide whether that stage actually runs for this document.
 *
 * Shape - either a single condition:
 *   {"field": "document_type_code", "operator": "equals", "value": "PDF"}
 * or a group of them, matched recursively:
 *   {"any": [{...}, {...}]}   - at least one must match
 *   {"all": [{...}, {...}]}   - every one must match
 *
 * Operators: equals, not_equals, in, not_in. Unknown/malformed conditions fail
 * closed (evaluate false) rather than silently running a stage that was meant
 * to be gated - see the docblock on evaluate().
 */
class ConditionEvaluator
{
    /**
     * Null/empty condition = unconditional = always runs. This is the only
     * case that defaults to true; every other path defaults to false so a
     * misconfigured condition skips a stage (loudly missing, in the workflow
     * builder's stage list) rather than silently admitting everyone.
     */
    public function evaluate(?array $condition, Document $document): bool
    {
        if (empty($condition)) {
            return true;
        }

        if (isset($condition['all']) && is_array($condition['all'])) {
            foreach ($condition['all'] as $sub) {
                if (! $this->evaluate($sub, $document)) {
                    return false;
                }
            }
            return true;
        }

        if (isset($condition['any']) && is_array($condition['any'])) {
            foreach ($condition['any'] as $sub) {
                if ($this->evaluate($sub, $document)) {
                    return true;
                }
            }
            return false;
        }

        if (! isset($condition['field'], $condition['operator'])) {
            return false;
        }

        $actual = $this->resolveField($condition['field'], $document);
        $expected = $condition['value'] ?? null;

        return match ($condition['operator']) {
            'equals' => (string) $actual === (string) $expected,
            'not_equals' => (string) $actual !== (string) $expected,
            'in' => is_array($expected) && in_array((string) $actual, array_map('strval', $expected), true),
            'not_in' => is_array($expected) && ! in_array((string) $actual, array_map('strval', $expected), true),
            default => false,
        };
    }

    protected function resolveField(string $field, Document $document): mixed
    {
        return match ($field) {
            'brand_id' => $document->brand_id,
            'brand_code' => $document->brand?->code,
            'document_type_id' => $document->document_type_id,
            'document_type_code' => $document->documentType?->code,
            'category' => $document->category,
            'target_audience' => $document->target_audience,
            'department' => $document->owner?->department,
            default => null,
        };
    }
}
