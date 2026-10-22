<?php

namespace App\Http\Controllers\Admin\Audit;

use App\Http\Controllers\Controller;
use App\Security\Audit;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Audit page: the latest actions, newest first. */
class AuditController extends Controller
{
    public const LIMIT = 200;

    public function index()
    {
        $rows = DB::table('audit_log')
            ->leftJoin('admin_users', 'audit_log.admin_id', '=', 'admin_users.id')
            ->orderByDesc('audit_log.id')
            ->limit(self::LIMIT)
            ->get([
                'audit_log.id', 'audit_log.action', 'audit_log.entity_type', 'audit_log.metadata',
                'audit_log.ip', 'audit_log.created_at', 'admin_users.name as admin_name', 'admin_users.email as admin_email',
            ]);

        return Inertia::render('Admin/Audit/Index', [
            'limit' => self::LIMIT,
            'entries' => $rows->map(function ($r) {
                $metadata = $r->metadata ? json_decode($r->metadata, true) : null;

                return [
                    'id' => (int) $r->id,
                    'action' => $r->action,
                    'entityType' => $r->entity_type,
                    'metadata' => $metadata,
                    'ip' => $r->ip,
                    'createdAt' => (int) $r->created_at,
                    'adminName' => $r->admin_name,
                    'adminEmail' => $r->admin_email,
                    'label' => Audit::ACTIONS[$r->action] ?? $r->action,
                    // Readable, bounded detail (metadata never holds passwords).
                    'detail' => is_array($metadata)
                        ? implode(' · ', array_map(fn ($k, $v) => "{$k}: ".self::text($v), array_keys($metadata), $metadata))
                        : '',
                ];
            })->all(),
        ]);
    }

    /** Like JavaScript's String(v). */
    private static function text(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_array($v) => array_is_list($v) ? implode(',', array_map([self::class, 'text'], $v)) : '[object Object]',
            default => (string) $v,
        };
    }
}
