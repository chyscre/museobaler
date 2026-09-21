<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\JsonResponse;

/** GET /api/v1/notifications - the app's notice board, newest first. */
class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $notices = Notice::active()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Notice $n) => [
                'id'         => (int) $n->notif_id,
                'title'      => $n->title,
                'body'       => $n->body,
                'type'       => $n->type,
                'created_at' => $n->created_at?->format('Y-m-d H:i:s'),
            ]);

        return response()->json($notices);
    }
}
