<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Every DocumentActionNotification already writes a 'database' record (this has
     * powered email since early in the build), but there was no UI to read them until
     * now - the sidebar shell's notification bell. Opening one marks it read and jumps
     * straight to the document it's about.
     */
    public function open(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $documentId = $notification->data['document_id'] ?? null;

        return $documentId
            ? redirect()->route('documents.show', $documentId)
            : redirect()->route('dashboard');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
