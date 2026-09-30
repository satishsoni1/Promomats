<?php

/*
| Business rules from the Himalaya UAT feedback round, kept in one place so they
| can be tuned per client without code changes. Role lists are role *slugs*.
*/
return [

    'roles' => [
        // MLR reviewers: review, select text and comment - but never upload files.
        'mlr' => ['medical', 'regulatory', 'legal', 'regulatory-level-1', 'regulatory-level-2', 'legal-level-1', 'legal-level-2'],

        // The internal Design Team: work is assigned to the team as a whole
        // ("umbrella"), then picked up by / assigned to an individual designer.
        // Everyone here also counts as a Content Creator in workflows.
        'design_team' => ['design-team', 'design-internal', 'content-creator', 'content-creator-intext'],

        // Brand Managers / task owners: start jobs, upload, assign stakeholders -
        // never approve or reject (unless they also hold a reviewer role above).
        'brand_manager' => ['brand-manager'],
    ],

    'approvals' => [
        // Re-entering the password when signing a decision. Off by default: users
        // are already signed in, and the signed name, time and IP are still
        // recorded on every decision.
        'require_password' => (bool) env('APPROVAL_REQUIRE_PASSWORD', false),
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
