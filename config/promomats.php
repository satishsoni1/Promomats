<?php

/*
| Business rules from the Himalaya UAT feedback round, kept in one place so they
| can be tuned per client without code changes. Role lists are role *slugs*.
*/
return [

    'roles' => [
        // MLR reviewers: review, select text and comment - but never upload files.
        // R&D ('rd') is the Medical track of MLR - one stakeholder among the three.
        'mlr' => ['medical', 'rd', 'regulatory', 'legal', 'regulatory-level-1', 'regulatory-level-2', 'legal-level-1', 'legal-level-2'],

        // The internal Design Team: work is assigned to the team as a whole
        // ("umbrella"), then picked up by / assigned to an individual designer.
        // Everyone here also counts as a Content Creator in workflows.
        'design_team' => ['design-team', 'design-internal', 'content-creator', 'content-creator-intext'],

        // Brand Managers (= job owners; the old "Document Owner" role is the same
        // thing): start jobs, upload, assign stakeholders - never approve or reject
        // ("makers can't be checkers"), unless they also hold a reviewer role.
        'brand_manager' => ['brand-manager', 'document-owner'],

        // External Content Agency: produces the creative / revised file when the
        // Brand Manager assigns it. Their upload always comes back to the Brand
        // Manager to confirm before it goes (back) into review.
        'agency' => ['content-agency', 'agency'],
    ],

    'approvals' => [
        // Re-entering the password when signing a decision. Off by default: users
        // are already signed in, and the signed name, time and IP are still
        // recorded on every decision.
        'require_password' => (bool) env('APPROVAL_REQUIRE_PASSWORD', false),
    ],

    /*
    | Teams the workflows and jobs are divided into. Each team has its own
    | workflows and its own upload fields - add a team's extra fields here (and in
    | the upload form's team section) as they are agreed. 'departments' pre-selects
    | the team on the upload form for users in those departments.
    */
    'teams' => [
        'pharma' => [
            'label' => 'Pharma',
            'description' => 'Promotional / non-promotional material - brand, SKU, country and collateral.',
            'departments' => [],
            // Fields the upload form shows (and stores) for this team.
            'fields' => ['category', 'brand', 'sku', 'country', 'channel', 'document_type', 'target_audience'],
        ],
        'publication' => [
            'label' => 'Scientific Publications',
            'description' => 'Publications and proofs - the Scientific Publications team\'s own workflows.',
            'departments' => ['Scientific Publications'],
            // Publication-specific fields will be added here once agreed.
            'fields' => ['project'],
        ],
    ],

    'workflow' => [
        // A rejection at any stage after these (TM/AGM) sends the revision back
        // through TM and every later stage again (MOM 24 Nov).
        'rereview_from_stage_codes' => ['TM_AGM'],
        // Stage codes treated as the Chairperson sign-off (offline stages always are).
        // After it, the Brand Manager gives the final approval and closes the job.
        'chairperson_stage_codes' => ['CHAIRPERSON'],
        // Auto-selected as the Chairperson stage's approver (MOM 24 Nov: "Jalba").
        'chairperson_approver_email' => env('CHAIRPERSON_APPROVER_EMAIL', 'jalba.rc@himalayawellness.com'),

        // Stakeholder groups a job's workflow must reach before it can be saved, per
        // team: at least one person from each group (counted by their roles).
        'required_stakeholders' => [
            'pharma' => [
                'R&D' => ['rd', 'medical'],
                'Legal' => ['legal', 'legal-level-1', 'legal-level-2'],
                'Regulatory Affairs' => ['regulatory', 'regulatory-level-1', 'regulatory-level-2'],
            ],
        ],
    ],

    /*
    | Brand divisions. Each brand belongs to one; the Chairperson stage is
    | auto-assigned to that division's Content Manager. Brands with no division use
    | workflow.chairperson_approver_email.
    */
    'divisions' => [
        'zeal' => [
            'label' => 'Zeal',
            'chairperson_email' => env('ZEAL_CHAIRPERSON_EMAIL', 'rathna.b@himalayawellness.com'),
        ],
        'zandra' => [
            'label' => 'Zandra',
            'chairperson_email' => env('ZANDRA_CHAIRPERSON_EMAIL', 'jalba.rc@himalayawellness.com'),
        ],
    ],

    'due_dates' => [
        // Default time each stage gets, unless the task owner changes it at upload.
        'default_stage_hours' => (int) env('DEFAULT_STAGE_DUE_HOURS', 48),
        // "Due soon" reminder is sent this many hours before a task is due.
        'reminder_hours_before' => (int) env('DUE_SOON_REMINDER_HOURS', 12),
    ],

    'helpdesk' => [
        'email' => env('HELPDESK_EMAIL', 'support@globalspace.in'),
        // First-response target per priority, in business hours (to be agreed with
        // the client as part of the support SLA).
        'first_response_hours' => ['urgent' => 2, 'high' => 4, 'normal' => 8, 'low' => 24],
    ],

    'mobile' => [
        'token_days' => (int) env('MOBILE_TOKEN_DAYS', 30),
    ],
];
