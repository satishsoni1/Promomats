<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ClaimController;
use App\Http\Controllers\Admin\ContentModuleController;
use App\Http\Controllers\Admin\AiSettingController;
use App\Http\Controllers\Admin\ArchivingSettingController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\ClaimCandidateController;
use App\Http\Controllers\Admin\DocumentTypeController;
use App\Http\Controllers\Admin\WorkflowRuleController;
use App\Http\Controllers\DistributionController;
use App\Http\Controllers\Admin\ColdStorageSettingController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\RetrievalRequestController as AdminRetrievalRequestController;
use App\Http\Controllers\Admin\MailSettingController;
use App\Http\Controllers\Admin\WorkflowTemplateController;
use App\Http\Controllers\DocumentApprovalController;
use App\Http\Controllers\DocumentAiController;
use App\Http\Controllers\DocumentClaimController;
use App\Http\Controllers\DocumentCommentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentObserverController;
use App\Http\Controllers\DownloadBasketController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\LibraryFileController;
use App\Http\Controllers\LibraryFolderController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PdfEditController;
use App\Http\Controllers\ClaimReferenceMappingController;
use App\Http\Controllers\PdfLinkController;
use App\Http\Controllers\ReferenceAttachmentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectCycleController;
use App\Http\Controllers\ReferenceLibraryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RetrievalRequestController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::middleware(['auth'])->group(function () {

    Route::get('/search', [SearchController::class, 'index'])->name('search');
    Route::get('/search/ai', [SearchController::class, 'ai'])->name('search.ai')->middleware('throttle:20,1');

    Route::get('/library', [LibraryFolderController::class, 'index'])->name('library.folders.index');
    Route::post('/library/folders', [LibraryFolderController::class, 'store'])->name('library.folders.store');
    Route::get('/library/folders/{folder}', [LibraryFolderController::class, 'show'])->name('library.folders.show');
    Route::delete('/library/folders/{folder}', [LibraryFolderController::class, 'destroy'])->name('library.folders.destroy');
    Route::post('/library/files', [LibraryFileController::class, 'store'])->name('library.files.store');
    Route::delete('/library/files/{reference}', [LibraryFileController::class, 'destroy'])->name('library.files.destroy');

    Route::get('/library/references', [ReferenceLibraryController::class, 'index'])->name('library.references.index');
    Route::post('/library/references/{reference}/attach', [ReferenceLibraryController::class, 'attach'])->name('library.references.attach');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('/projects/{project}/references', [ReferenceAttachmentController::class, 'storeForProject'])->name('projects.references.store');
    Route::delete('/projects/{project}/references/{reference}', [ReferenceAttachmentController::class, 'destroyForProject'])->name('projects.references.destroy');

    Route::post('/projects/{project}/cycles', [ProjectCycleController::class, 'store'])->name('projects.cycles.store');
    Route::get('/projects/{project}/cycles/{cycle}', [ProjectCycleController::class, 'show'])->name('projects.cycles.show');
    Route::put('/projects/{project}/cycles/{cycle}', [ProjectCycleController::class, 'update'])->name('projects.cycles.update');
    Route::delete('/projects/{project}/cycles/{cycle}', [ProjectCycleController::class, 'destroy'])->name('projects.cycles.destroy');
    Route::post('/projects/{project}/cycles/{cycle}/documents', [ProjectCycleController::class, 'assignDocument'])->name('projects.cycles.documents.store');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');

    Route::get('/help', [HelpController::class, 'index'])->name('help.index');
    Route::get('/help/demo-script', [HelpController::class, 'demoScript'])->name('help.demo-script');

    Route::post('/basket/{document}', [DownloadBasketController::class, 'add'])->name('basket.add');
    Route::delete('/basket/{document}', [DownloadBasketController::class, 'remove'])->name('basket.remove');
    Route::get('/basket', [DownloadBasketController::class, 'show'])->name('basket.show');
    Route::get('/basket/download', [DownloadBasketController::class, 'download'])->name('basket.download');
    Route::delete('/basket', [DownloadBasketController::class, 'clear'])->name('basket.clear');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    // Profile (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Documents
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/create', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('/documents/{document}/submit', [DocumentController::class, 'submit'])->name('documents.submit');
    Route::post('/documents/{document}/versions', [DocumentController::class, 'uploadNewVersion'])->name('documents.versions.store');
    Route::get('/documents/{document}/pdf-editor', [PdfEditController::class, 'edit'])->name('documents.pdf-editor.edit');
    Route::post('/documents/{document}/pdf-edits', [PdfEditController::class, 'store'])->name('documents.pdf-edits.store');
    Route::post('/documents/{document}/status', [DocumentController::class, 'updateStatus'])->name('documents.status.update');
    Route::post('/documents/{document}/project', [DocumentController::class, 'updateProject'])->name('documents.project.update');
    Route::post('/documents/{document}/cycle', [DocumentController::class, 'updateCycle'])->name('documents.cycle.update');
    Route::get('/documents/{document}/history-report', [DocumentController::class, 'historyReport'])->name('documents.history-report');
    Route::post('/documents/{document}/legal-hold', [DocumentController::class, 'placeLegalHold'])->name('documents.legal-hold.place');
    Route::delete('/documents/{document}/legal-hold', [DocumentController::class, 'releaseLegalHold'])->name('documents.legal-hold.release');
    Route::post('/documents/{document}/observers', [DocumentObserverController::class, 'store'])->name('documents.observers.store');
    Route::delete('/documents/{document}/observers/{user}', [DocumentObserverController::class, 'destroy'])->name('documents.observers.destroy');
    Route::get('/document-versions/{version}/download', [DocumentController::class, 'downloadVersion'])->name('documents.versions.download');
    Route::get('/document-versions/{version}/view', [DocumentController::class, 'viewVersion'])->name('documents.versions.view');
    Route::post('/documents/{document}/comments', [DocumentCommentController::class, 'store'])->name('documents.comments.store');
    Route::post('/documents/{document}/claims', [DocumentClaimController::class, 'store'])->name('documents.claims.store');
    Route::delete('/documents/{document}/claims/{claim}', [DocumentClaimController::class, 'destroy'])->name('documents.claims.destroy');

    Route::post('/documents/{document}/references', [ReferenceAttachmentController::class, 'storeForDocument'])->name('documents.references.store');
    Route::delete('/documents/{document}/references/{reference}', [ReferenceAttachmentController::class, 'destroyForDocument'])->name('documents.references.destroy');
    Route::post('/documents/{document}/references/attach-existing', [ReferenceAttachmentController::class, 'attachExisting'])->name('documents.references.attach-existing');

    Route::post('/documents/{document}/retrieval-requests', [RetrievalRequestController::class, 'store'])->name('documents.retrieval.store');

    Route::post('/documents/{document}/distribution/confirm', [DistributionController::class, 'confirm'])->name('documents.distribution.confirm');

    Route::post('/documents/{document}/pdf-links/extract', [PdfLinkController::class, 'extract'])->name('documents.pdf-links.extract');
    Route::post('/documents/{document}/pdf-links/{pdfLink}/link', [PdfLinkController::class, 'link'])->name('documents.pdf-links.link');
    Route::post('/documents/{document}/pdf-links/{pdfLink}/unlink', [PdfLinkController::class, 'unlink'])->name('documents.pdf-links.unlink');
    Route::post('/documents/{document}/pdf-links/{pdfLink}/ignore', [PdfLinkController::class, 'ignore'])->name('documents.pdf-links.ignore');

    Route::post('/documents/{document}/claim-reference-mappings', [ClaimReferenceMappingController::class, 'store'])->name('documents.claim-reference-mappings.store');
    Route::delete('/documents/{document}/claim-reference-mappings/{mapping}', [ClaimReferenceMappingController::class, 'destroy'])->name('documents.claim-reference-mappings.destroy');
    Route::get('/reference-attachments/{reference}/download', [ReferenceAttachmentController::class, 'download'])->name('reference-attachments.download');

    // Rate limited (20/min/user) - these hit a paid external AI provider (Groq/Anthropic),
    // so an unbounded loop or malicious script here is a real cost/abuse risk, not just a
    // performance one.
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/documents/{document}/ai/compliance-check', [DocumentAiController::class, 'complianceCheck'])->name('documents.ai.compliance-check');
        Route::post('/documents/{document}/ai/suggest-claims', [DocumentAiController::class, 'suggestClaims'])->name('documents.ai.suggest-claims');
        Route::post('/documents/{document}/ai/extract-claims', [DocumentAiController::class, 'extractClaims'])->name('documents.ai.extract-claims');
        Route::post('/documents/{document}/ai/suggest-references', [DocumentAiController::class, 'suggestReferences'])->name('documents.ai.suggest-references');
        Route::post('/documents/{document}/ai/actions/{action}/suggest-revision', [DocumentAiController::class, 'suggestRevision'])->name('documents.ai.suggest-revision');
    });

    // Approvals
    Route::get('/approvals/inbox', [DocumentApprovalController::class, 'inbox'])->name('approvals.inbox');
    Route::post('/workflow-instances/{instance}/act', [DocumentApprovalController::class, 'act'])->name('approvals.act');

    // Notifications (mark read) - simple inline example
    Route::post('/notifications/{notification}/read', function (\Illuminate\Notifications\DatabaseNotification $notification) {
        $notification->markAsRead();
        return back();
    })->name('notifications.read');

    // --- Admin area: gated by the 'access-admin' gate defined in AuthServiceProvider ---
    Route::middleware(['can:access-admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboards', [AdminDashboardController::class, 'index'])->name('dashboards.index');

        Route::get('/mail-settings', [MailSettingController::class, 'index'])->name('mail-settings.index');
        Route::put('/mail-settings', [MailSettingController::class, 'update'])->name('mail-settings.update');
        Route::post('/mail-settings/test', [MailSettingController::class, 'sendTest'])->name('mail-settings.test');

        Route::get('/ai-settings', [AiSettingController::class, 'index'])->name('ai-settings.index');
        Route::put('/ai-settings', [AiSettingController::class, 'update'])->name('ai-settings.update');
        Route::post('/ai-settings/test', [AiSettingController::class, 'testConnection'])->name('ai-settings.test');

        Route::get('/archiving-settings', [ArchivingSettingController::class, 'index'])->name('archiving-settings.index');
        Route::put('/archiving-settings', [ArchivingSettingController::class, 'update'])->name('archiving-settings.update');

        Route::get('/cold-storage-settings', [ColdStorageSettingController::class, 'index'])->name('cold-storage-settings.index');
        Route::put('/cold-storage-settings', [ColdStorageSettingController::class, 'update'])->name('cold-storage-settings.update');
        Route::post('/cold-storage-settings/test', [ColdStorageSettingController::class, 'test'])->name('cold-storage-settings.test');

        Route::get('/retrieval-requests', [AdminRetrievalRequestController::class, 'index'])->name('retrieval-requests.index');
        Route::post('/retrieval-requests/{retrievalRequest}/process', [AdminRetrievalRequestController::class, 'process'])->name('retrieval-requests.process');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        Route::get('/claims', [ClaimController::class, 'index'])->name('claims.index');
        Route::get('/claims/create', [ClaimController::class, 'create'])->name('claims.create');
        Route::post('/claims', [ClaimController::class, 'store'])->name('claims.store');
        Route::get('/claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');
        Route::get('/claims/{claim}/edit', [ClaimController::class, 'edit'])->name('claims.edit');
        Route::put('/claims/{claim}', [ClaimController::class, 'update'])->name('claims.update');
        Route::delete('/claims/{claim}', [ClaimController::class, 'destroy'])->name('claims.destroy');
        Route::post('/claims/{claim}/references', [ReferenceAttachmentController::class, 'storeForClaim'])->name('claims.references.store');
        Route::delete('/claims/{claim}/references/{reference}', [ReferenceAttachmentController::class, 'destroyForClaim'])->name('claims.references.destroy');

        Route::get('/claim-candidates', [ClaimCandidateController::class, 'index'])->name('claim-candidates.index');
        Route::post('/claim-candidates/{candidate}/accept', [ClaimCandidateController::class, 'accept'])->name('claim-candidates.accept');
        Route::post('/claim-candidates/{candidate}/reject', [ClaimCandidateController::class, 'reject'])->name('claim-candidates.reject');

        Route::get('/content-modules', [ContentModuleController::class, 'index'])->name('content-modules.index');
        Route::get('/content-modules/create', [ContentModuleController::class, 'create'])->name('content-modules.create');
        Route::post('/content-modules', [ContentModuleController::class, 'store'])->name('content-modules.store');
        Route::get('/content-modules/{contentModule}/edit', [ContentModuleController::class, 'edit'])->name('content-modules.edit');
        Route::put('/content-modules/{contentModule}', [ContentModuleController::class, 'update'])->name('content-modules.update');
        Route::delete('/content-modules/{contentModule}', [ContentModuleController::class, 'destroy'])->name('content-modules.destroy');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/roles', [UserController::class, 'updateRoles'])->name('users.roles.update');
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

        Route::get('/workflows', [WorkflowTemplateController::class, 'index'])->name('workflows.index');
        Route::get('/workflows/create', [WorkflowTemplateController::class, 'create'])->name('workflows.create');
        Route::post('/workflows', [WorkflowTemplateController::class, 'store'])->name('workflows.store');
        Route::get('/workflows/{workflow}/edit', [WorkflowTemplateController::class, 'edit'])->name('workflows.edit');
        Route::post('/workflows/{workflow}/stages', [WorkflowTemplateController::class, 'addStage'])->name('workflows.stages.add');
        Route::post('/workflows/{workflow}/stages/reorder', [WorkflowTemplateController::class, 'reorderStages'])->name('workflows.stages.reorder');
        Route::post('/workflow-stages/{stage}/transitions', [WorkflowTemplateController::class, 'setTransition'])->name('workflows.stages.transitions.set');
        Route::post('/workflows/{workflow}/new-version', [WorkflowTemplateController::class, 'newVersion'])->name('workflows.new-version');

        Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
        Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
        Route::post('/brands/{brand}/toggle-status', [BrandController::class, 'toggleStatus'])->name('brands.toggle-status');

        Route::get('/document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
        Route::post('/document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
        Route::post('/document-types/{documentType}/toggle-status', [DocumentTypeController::class, 'toggleStatus'])->name('document-types.toggle-status');

        Route::get('/workflow-rules', [WorkflowRuleController::class, 'index'])->name('workflow-rules.index');
        Route::post('/workflow-rules', [WorkflowRuleController::class, 'store'])->name('workflow-rules.store');
        Route::delete('/workflow-rules/{workflowRule}', [WorkflowRuleController::class, 'destroy'])->name('workflow-rules.destroy');
    });
});

require __DIR__.'/auth.php';
