<?php

namespace App\Support;

/**
 * Shared target-audience vocabulary for Documents and Projects. Centralised here rather
 * than duplicated as constants on both models, since a Project's audience is really just
 * a default that a Document created under it inherits (and can override) - same list,
 * same guidance either way.
 */
class TargetAudience
{
    public const LABELS = [
        'patient' => 'Patient',
        'hcp' => 'Healthcare Professional (HCP)',
        'payer' => 'Payer',
        'sales_rep' => 'Sales Rep',
        'internal' => 'Internal / Employee',
        'general_public' => 'General Public',
    ];

    /**
     * Inline guidance shown when a user picks an audience on the document/project create
     * form. This is advisory, not enforced - it exists so the right depth of review gets
     * chosen deliberately (and a matching workflow template gets suggested) rather than by
     * habit. `depth` drives which color the hint renders in.
     */
    public const GUIDANCE = [
        'patient' => [
            'depth' => 'high',
            'message' => 'Patient-facing content needs the deepest review — expect Regulatory, Legal, and R&D sign-off in addition to marketing approval before this can reach a patient.',
        ],
        'hcp' => [
            'depth' => 'high',
            'message' => 'Content aimed at healthcare professionals is still regulated promotional material — expect full Regulatory and Legal review before distribution.',
        ],
        'payer' => [
            'depth' => 'high',
            'message' => 'Payer-facing material (formulary, pricing, health-economics content) needs Regulatory and Legal review, and often Health Economics sign-off.',
        ],
        'general_public' => [
            'depth' => 'high',
            'message' => 'Public-facing content carries the same scrutiny as patient-facing material — route it through a full Regulatory/Legal review workflow.',
        ],
        'sales_rep' => [
            'depth' => 'medium',
            'message' => 'Rep-facing material is still promotional and needs Marketing and Regulatory review, though it can often skip stages aimed at public claims substantiation.',
        ],
        'internal' => [
            'depth' => 'low',
            'message' => 'Internal/employee-only content usually needs a lighter review — a single Marketing or department sign-off is often enough, unless it references patient claims.',
        ],
    ];

    public static function label(?string $key): ?string
    {
        return $key ? (self::LABELS[$key] ?? $key) : null;
    }

    public static function guidance(?string $key): ?array
    {
        return $key ? (self::GUIDANCE[$key] ?? null) : null;
    }
}
