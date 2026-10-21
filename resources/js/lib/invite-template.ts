import { z } from 'zod';

// Plantilla de invitación con variables {{así}}. Compartida entre servidor e interfaz
// (la interfaz la usa para la vista previa).

export const INVITE_PLACEHOLDERS = {
	nombre: 'Nombre del participante',
	titulo: 'Título del assessment',
	descripcion: 'Descripción del assessment',
	enlace: 'Enlace de ingreso',
	contrasena: 'Contraseña de acceso',
	inicio: 'Fecha y hora de inicio',
	fin: 'Fecha y hora de finalización',
	zona_horaria: 'Zona horaria'
} as const;

export type InviteVars = Record<keyof typeof INVITE_PLACEHOLDERS, string>;

export const DEFAULT_INVITE_SUBJECT = 'Invitación: {{titulo}}';

export const DEFAULT_INVITE_BODY = `Hola {{nombre}},

Le invitamos a responder el assessment "{{titulo}}".

Enlace: {{enlace}}
Contraseña de acceso: {{contrasena}}

Disponible desde el {{inicio}} hasta el {{fin}} (hora de {{zona_horaria}}).

Para ingresar, abra el enlace, escriba la contraseña y su correo electrónico. Puede salir y volver a entrar: sus respuestas se guardan automáticamente hasta que las entregue.

Gracias por su participación.`;

const REQUIRED: (keyof InviteVars)[] = ['enlace', 'contrasena'];

export const inviteTemplateSchema = z
	.object({
		subject: z
			.string()
			.trim()
			.min(1, 'Escriba el asunto.')
			.max(200, 'El asunto no puede superar 200 caracteres.'),
		body: z
			.string()
			.trim()
			.min(1, 'Escriba el mensaje.')
			.max(5000, 'El mensaje no puede superar 5.000 caracteres.')
	})
	.superRefine((v, ctx) => {
		for (const key of REQUIRED) {
			if (!new RegExp(`\\{\\{\\s*${key}\\s*\\}\\}`).test(v.body)) {
				ctx.addIssue({
					code: 'custom',
					path: ['body'],
					message: `El mensaje debe incluir {{${key}}}.`
				});
			}
		}
	});

/** Reemplaza {{variable}}. Las variables desconocidas se dejan tal cual. */
export function renderTemplate(template: string, vars: Partial<InviteVars>): string {
	return template.replace(/\{\{\s*(\w+)\s*\}\}/g, (match, key: string) =>
		key in vars ? (vars as Record<string, string>)[key] : match
	);
}

/** Mensaje genérico (sin nombre), para copiar y enviar por otro medio. */
export function renderGeneric(template: string, vars: Omit<InviteVars, 'nombre'>): string {
	// "Hola {{nombre}}," → "Hola,"
	return renderTemplate(template, { ...vars, nombre: '' }).replace(/[ \t]+([,.;:])/g, '$1');
}

const escapeHtml = (s: string) =>
	s.replace(
		/[&<>"']/g,
		(c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!
	);

/**
 * Versión HTML del correo: todo el texto se escapa (incluido lo que escribió el admin
 * o el nombre del participante), los enlaces se vuelven clicables y los saltos de línea <br>.
 */
export function textToHtml(text: string): string {
	const body = escapeHtml(text)
		.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1">$1</a>')
		.replace(/\r?\n/g, '<br>');
	return `<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1e293b">${body}</div>`;
}
