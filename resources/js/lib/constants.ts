// Valores y etiquetas compartidos entre servidor e interfaz.

export const ACCESS_MODES = ['invitados', 'abierto'] as const;
export const ASSESSMENT_STATUSES = ['borrador', 'publicado', 'archivado'] as const;
export const QUESTION_TYPES = ['opcion_unica', 'abierta'] as const;

export type AccessMode = (typeof ACCESS_MODES)[number];
export type AssessmentStatus = (typeof ASSESSMENT_STATUSES)[number];
export type QuestionType = (typeof QUESTION_TYPES)[number];

export const ACCESS_MODE_LABELS: Record<AccessMode, string> = {
	invitados: 'Solo invitados',
	abierto: 'Abierto (cualquiera con la contraseña)'
};

export const STATUS_LABELS: Record<AssessmentStatus, string> = {
	borrador: 'Borrador',
	publicado: 'Publicado',
	archivado: 'Archivado'
};

export const QUESTION_TYPE_LABELS: Record<QuestionType, string> = {
	opcion_unica: 'Selección (única respuesta)',
	abierta: 'Respuesta abierta'
};

export const PARTICIPANT_STATUSES = ['pendiente', 'en_progreso', 'completado'] as const;
export type ParticipantStatus = (typeof PARTICIPANT_STATUSES)[number];

export const PARTICIPANT_STATUS_LABELS: Record<ParticipantStatus, string> = {
	pendiente: 'Pendiente',
	en_progreso: 'En progreso',
	completado: 'Completado'
};
