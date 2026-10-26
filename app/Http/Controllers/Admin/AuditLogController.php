<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Journalisation et audit des actions administratives (§5.4).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = AuditLog::query()
            ->with('auteur:id,nom,prenom,email,role')
            ->when($request->filled('q'), fn ($r) => $r->where('action', 'like', '%'.$request->string('q').'%'))
            ->latest()
            ->paginate(30);

        return AuditLogResource::collection($logs);
    }
}
