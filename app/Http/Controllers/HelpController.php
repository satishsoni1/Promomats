<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Models\WorkflowTemplate;

class HelpController extends Controller
{
    public function index()
    {
        return view('help.index');
    }

    /**
     * A client-facing walkthrough script: real credentials, real seeded demo
     * documents/workflows, and a suggested presentation order - always live in the
     * app rather than a separate file that goes stale.
     */
    public function demoScript()
    {
        $workflows = WorkflowTemplate::withCount('stages')->orderBy('id')->get();

        $demoUsers = User::where('email', 'like', '%promomats.test')
            ->with('roles')
            ->orderBy('email')
            ->get();

        $demoDocuments = Document::with(['owner', 'workflowTemplate', 'activeWorkflowInstance.currentStage'])
            ->orderBy('id')
            ->limit(10)
            ->get();

        return view('help.demo-script', compact('workflows', 'demoUsers', 'demoDocuments'));
    }
}
