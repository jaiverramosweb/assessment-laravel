// Colores del cliente por assessment.
// - primary: reemplaza el azul noche (franja superior e instrucciones del participante).
// - accent: reemplaza el verde azulado (botones, progreso, opción seleccionada).
// Sobre ambos va texto blanco, así que se recomiendan tonos oscuros (contraste ≥ 4,5:1).
// Un tono más claro se permite: el admin ve la advertencia y decide.
// A partir de cada color se derivan los tonos que usan las pantallas (más claros y más oscuros)
// y se aplican sobrescribiendo las variables de Tailwind (--color-…) en el contenedor.

// Colores de la plataforma (SISTEL): negro azul petróleo y azul tech.
export const DEFAULT_PRIMARY = '#1a1c23';
export const DEFAULT_ACCENT = '#374ef7';
export const MIN_CONTRAST = 4.5;

const HEX_RE = /^#[0-9a-f]{6}$/i;

export function isHexColor(value: string): boolean {
	return HEX_RE.test(value);
}

type RGB = [number, number, number];

function toRgb(hex: string): RGB {
	const n = parseInt(hex.slice(1), 16);
	return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

function toHex([r, g, b]: RGB): string {
	return '#' + [r, g, b].map((c) => Math.round(c).toString(16).padStart(2, '0')).join('');
}

/** Mezcla `color` con `other` (0 = color, 1 = other). */
function mix(color: string, other: string, amount: number): string {
	const a = toRgb(color);
	const b = toRgb(other);
	return toHex([0, 1, 2].map((i) => a[i] + (b[i] - a[i]) * amount) as RGB);
}

function luminance(hex: string): number {
	const [r, g, b] = toRgb(hex).map((c) => {
		const s = c / 255;
		return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
	});
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Contraste WCAG entre dos colores (1 a 21). */
export function contrast(a: string, b: string): number {
	const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
	return (hi + 0.05) / (lo + 0.05);
}

/** ¿Se lee bien texto blanco sobre este color? */
export function readableWithWhite(hex: string): boolean {
	return contrast(hex, '#ffffff') >= MIN_CONTRAST;
}

export type ClientTheme = { primary: string; accent: string };

/**
 * Variables CSS para un contenedor: los colores del cliente reemplazan las escalas
 * `ink` y `brand` de Tailwind solo dentro de ese contenedor.
 * Devuelve `undefined` si se usan los colores de la plataforma.
 */
export function themeStyle(
	theme: { primary?: string | null; accent?: string | null } | null | undefined
): string | undefined {
	const primary = theme?.primary && isHexColor(theme.primary) ? theme.primary : null;
	const accent = theme?.accent && isHexColor(theme.accent) ? theme.accent : null;
	if (!primary && !accent) return undefined;

	const vars: Record<string, string> = {};
	if (primary) {
		vars['--color-ink-950'] = primary;
		vars['--color-ink-900'] = mix(primary, '#ffffff', 0.08);
		vars['--color-ink-800'] = mix(primary, '#ffffff', 0.14);
		// Texto secundario sobre la franja: blanco suavizado con el color principal.
		vars['--color-ink-300'] = mix('#ffffff', primary, 0.22);
	}
	if (accent) {
		vars['--color-brand-50'] = mix(accent, '#ffffff', 0.9);
		vars['--color-brand-100'] = mix(accent, '#ffffff', 0.8);
		vars['--color-brand-200'] = mix(accent, '#ffffff', 0.62);
		vars['--color-brand-300'] = mix(accent, '#ffffff', 0.45);
		vars['--color-brand-500'] = mix(accent, '#ffffff', 0.12);
		vars['--color-brand-600'] = accent;
		vars['--color-brand-700'] = mix(accent, '#000000', 0.2);
		vars['--color-brand-800'] = mix(accent, '#000000', 0.38);
	}
	return Object.entries(vars)
		.map(([k, v]) => `${k}: ${v}`)
		.join('; ');
}
