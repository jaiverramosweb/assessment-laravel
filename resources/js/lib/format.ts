// Formato de fechas para la interfaz (español, zona horaria explícita).

export const DEFAULT_TIMEZONE = 'America/Bogota';

/** "83%" / "61,1%" con formato colombiano; "—" cuando no hay dato. */
/** Diferencia entre dos proporciones, en puntos porcentuales: "+12 pp", "−5 pp", "0 pp" o "—". */
export function formatPoints(delta: number | null): string {
	if (delta === null) return '—';
	const pp = Math.round(delta * 100);
	if (pp === 0) return '0 pp';
	return `${pp > 0 ? '+' : '−'}${Math.abs(pp)} pp`;
}

export function formatPercent(rate: number | null, digits = 0): string {
	if (rate === null) return '—';
	return new Intl.NumberFormat('es-CO', {
		style: 'percent',
		maximumFractionDigits: digits,
		minimumFractionDigits: digits
	}).format(rate);
}

/** Formato largo para textos dirigidos a personas: "1 de noviembre de 2026, 8:00 a. m." */
export function formatLongDateTime(date: Date | number, timeZone = DEFAULT_TIMEZONE): string {
	return (
		new Intl.DateTimeFormat('es-CO', {
			dateStyle: 'long',
			timeStyle: 'short',
			timeZone
		})
			.format(date)
			// Espacios no separables para que "6:00 p. m." no se parta entre líneas.
			.replace(/ ([ap])\.\s?m\./g, ' $1. m.')
	);
}

/** Fecha corta: "18 oct 2026". */
export function formatShortDate(date: Date | number, timeZone = DEFAULT_TIMEZONE): string {
	return new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeZone }).format(date);
}

export function formatDateTime(date: Date | number, timeZone = DEFAULT_TIMEZONE): string {
	return new Intl.DateTimeFormat('es-CO', {
		dateStyle: 'medium',
		timeStyle: 'short',
		timeZone
	}).format(date);
}
