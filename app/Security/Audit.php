<?php

namespace App\Security;

use App\Models\AuditLog;
use InvalidArgumentException;

/** Audit trail. The action list is closed: it prevents typos and is translated on the audit page. */
final class Audit
{
    public const ACTIONS = [
        'admin.login' => 'Inicio de sesión',
        'admin.login_fallido' => 'Intento de inicio de sesión fallido',
        'admin.logout' => 'Cierre de sesión',
        'admin.creado' => 'Administrador creado',
        'admin.password_cambiada' => 'Contraseña de administrador cambiada',
        'admin.desactivado' => 'Administrador desactivado',
        'admin.reactivado' => 'Administrador reactivado',
        'assessment.creado' => 'Assessment creado',
        'assessment.editado' => 'Assessment editado',
        'assessment.duplicado' => 'Assessment duplicado',
        'assessment.archivado' => 'Assessment archivado',
        'assessment.eliminado' => 'Assessment eliminado',
        'assessment.medicion_creada' => 'Medición creada',
        'assessment.password_cambiada' => 'Contraseña de acceso cambiada',
        'assessment.password_vista' => 'Contraseña de acceso consultada',
        'cliente.creado' => 'Cliente creado',
        'cliente.editado' => 'Cliente editado',
        'cliente.eliminado' => 'Cliente eliminado',
        'preguntas.editadas' => 'Preguntas editadas',
        'preguntas.importadas' => 'Preguntas importadas',
        'participantes.cargados' => 'Participantes cargados',
        'participantes.editado' => 'Participante editado',
        'participantes.eliminado' => 'Participante eliminado',
        'invitaciones.enviadas' => 'Invitaciones enviadas',
        'reporte.exportado' => 'Reporte exportado',
        'ia.analisis' => 'Análisis con IA ejecutado',
    ];

    public static function log(
        ?string $adminId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $metadata = null,
        ?string $ip = null,
    ): void {
        if (! array_key_exists($action, self::ACTIONS)) {
            throw new InvalidArgumentException("Acción de auditoría desconocida: {$action}");
        }
        AuditLog::create([
            'admin_id' => $adminId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata,
            'ip' => $ip,
        ]);
    }
}
