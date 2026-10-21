import { TZDate } from '@date-fns/tz';

// Conversión entre el valor de un <input type="datetime-local"> ("2026-10-20T08:30"),
// que no tiene zona horaria, y un instante UTC, usando la zona del assessment.

const LOCAL_RE = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/;

/** Interpreta "YYYY-MM-DDTHH:mm" como hora local de `timeZone`. Devuelve `null` si es inválido. */
export function parseLocalDateTime(value: string, timeZone: string): Date | null {
	const m = LOCAL_RE.exec(value.trim());
	if (!m) return null;
	const [y, mo, d, h, mi] = m.slice(1).map(Number);
	if (mo < 1 || mo > 12 || d < 1 || d > 31 || h > 23 || mi > 59) return null;

	const date = new TZDate(y, mo - 1, d, h, mi, timeZone);
	// Rechaza fechas que JavaScript "corrige" (ej. 31 de febrero → 3 de marzo).
	if (date.getFullYear() !== y || date.getMonth() !== mo - 1 || date.getDate() !== d) return null;
	return new Date(date.getTime());
}

/** Formatea un instante como "YYYY-MM-DDTHH:mm" en la zona indicada (para el input). */
export function toLocalDateTimeInput(date: Date | number, timeZone: string): string {
	const z = new TZDate(typeof date === 'number' ? date : date.getTime(), timeZone);
	const p = (n: number) => String(n).padStart(2, '0');
	return `${z.getFullYear()}-${p(z.getMonth() + 1)}-${p(z.getDate())}T${p(z.getHours())}:${p(z.getMinutes())}`;
}

/** Zonas horarias sugeridas primero en el selector. */
export const COMMON_TIMEZONES = [
	'America/Bogota',
	'America/Mexico_City',
	'America/Lima',
	'America/Guayaquil',
	'America/Panama',
	'America/Caracas',
	'America/Santiago',
	'America/Argentina/Buenos_Aires',
	'America/Sao_Paulo',
	'America/New_York',
	'Europe/Madrid',
	'UTC'
];

export function isValidTimeZone(timeZone: string): boolean {
	try {
		new Intl.DateTimeFormat('es', { timeZone });
		return true;
	} catch {
		return false;
	}
}

export function allTimeZones(): string[] {
	const all = Intl.supportedValuesOf('timeZone');
	const rest = all.filter((z) => !COMMON_TIMEZONES.includes(z));
	return [...COMMON_TIMEZONES, ...rest];
}
