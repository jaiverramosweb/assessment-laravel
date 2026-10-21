import { describe, expect, it } from 'vitest';
import {
	contrast,
	DEFAULT_ACCENT,
	DEFAULT_PRIMARY,
	isHexColor,
	readableWithWhite,
	themeStyle
} from './theme';

describe('colores del cliente', () => {
	it('valida el formato #RRGGBB', () => {
		expect(isHexColor('#0B7A6E')).toBe(true);
		expect(isHexColor('0b7a6e')).toBe(false);
		expect(isHexColor('#fff')).toBe(false);
		expect(isHexColor('#0b7a6e; color: red')).toBe(false);
	});

	it('calcula el contraste WCAG', () => {
		expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 0);
		expect(contrast('#ffffff', '#ffffff')).toBeCloseTo(1, 5);
	});

	it('exige colores oscuros para poner texto blanco encima', () => {
		expect(readableWithWhite('#0e1726')).toBe(true);
		expect(readableWithWhite('#0b7a6e')).toBe(true);
		// Colores de la plataforma (SISTEL): negro azul petróleo y azul tech.
		expect(readableWithWhite(DEFAULT_PRIMARY)).toBe(true);
		expect(readableWithWhite(DEFAULT_ACCENT)).toBe(true);
		expect(contrast(DEFAULT_ACCENT, '#ffffff')).toBeGreaterThan(5.5);
		expect(readableWithWhite('#f5c400')).toBe(false); // amarillo claro
		expect(readableWithWhite('#7fb3ff')).toBe(false); // celeste
	});

	it('genera variables solo para los colores definidos', () => {
		expect(themeStyle(null)).toBeUndefined();
		expect(themeStyle({ primary: '', accent: '' })).toBeUndefined();
		const style = themeStyle({ primary: '#1a2b5c', accent: '#c2410c' })!;
		expect(style).toContain('--color-ink-950: #1a2b5c');
		expect(style).toContain('--color-brand-600: #c2410c');
		expect(style).toMatch(/--color-brand-50: #[0-9a-f]{6}/);
		expect(themeStyle({ accent: '#c2410c' })).not.toContain('--color-ink-950');
	});

	it('ignora valores que no son colores (no se puede inyectar CSS)', () => {
		expect(themeStyle({ primary: 'red; background: url(x)', accent: '#00000g' })).toBeUndefined();
	});
});
