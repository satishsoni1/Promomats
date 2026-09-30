<?php

use Illuminate\Support\Facades\Schedule;

// Runs every day at 6am server time to flag aging/expired documents and notify owners.
Schedule::command('documents:flag-lifecycle')->dailyAt('06:00');

// Hourly: a "due soon" reminder shortly before each task is due, and one overdue
// alert once it passes - each sent once per task, so no spam.
Schedule::command('documents:flag-overdue-approvals')->hourly();

// REQ-4.1: daily archiving sweep - disabled until an admin turns it on at Admin > Archiving Settings.
Schedule::command('documents:apply-archiving-policy')->dailyAt('06:30');

// REQ-4.2: daily cold storage migration, run after the archiving sweep above so a
// document archived today is immediately eligible for the *next* day's run rather
// than racing it. Disabled until an admin configures + enables it at Admin > Cold
// Storage Settings.
Schedule::command('documents:migrate-to-cold-storage')->dailyAt('07:00');

// REQ-4.3: works through pending cold-storage retrieval requests frequently (a
// restore is a quick file copy) so the 24-hour SLA is comfortably met without
// needing the admin's manual "Process Now" override.
Schedule::command('documents:process-retrieval-requests')->everyTenMinutes();
