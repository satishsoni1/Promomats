<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkflowInstance;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Support\Facades\Request;

/**
 * The single write path for audit_logs. Every caller (WorkflowEngine, controllers,
 * scheduled commands) should log through here rather than creating AuditLog rows
 * directly, so "who/when/from where" is captured consistently everywhere.
 */
class AuditLogger
{
    public function record(
        string $action,
        ?Document $document = null,
        ?DocumentVersion $version = null,
        ?DocumentWorkflowInstance $instance = null,
        ?WorkflowStage $stage = null,
        ?User $actor = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?string $description = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $actor?->id ?? auth()->id(),
            'document_id' => $document?->id,
            'document_version_id' => $version?->id,
            'document_workflow_instance_id' => $instance?->id,
            'workflow_stage_id' => $stage?->id,
            'action' => $action,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
