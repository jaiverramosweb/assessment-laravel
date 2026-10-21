import { describe, expect, it } from 'vitest';
import { isValidTimeZone, parseLocalDateTime, toLocalDateTimeInput } from './datetime';

describe('fechas con zona horaria', () => {
	it('interpreta la hora local de Bogotá (UTC-5)', () => {
		const d = parseLocalDateTime('2026-10-20T08:30', 'America/Bogota');
		expect(d?.toISOString()).toBe('2026-10-20T13:30:00.000Z');
	});

	it('respeta el horario de verano (Madrid en julio es UTC+2)', () => {
		const d = parseLocalDateTime('2026-07-01T10:00', 'Europe/Madrid');
		expect(d?.toISOString()).toBe('2026-07-01T08:00:00.000Z');
	});

	it('ida y vuelta: instante → input → instante', () => {
		const original = new Date('2026-12-31T23:45:00Z');
		const input = toLocalDateTimeInput(original, 'America/Bogota');
		expect(input).toBe('2026-12-31T18:45');
		expect(parseLocalDateTime(input, 'America/Bogota')?.getTime()).toBe(original.getTime());
	});

	it.each(['', '2026-02-31T10:00', '2026-13-01T10:00', '20/10/2026 08:00', '2026-10-20T25:00'])(
		'rechaza el valor inválido %j',
		(value) => {
			expect(parseLocalDateTime(value, 'America/Bogota')).toBeNull();
		}
	);

	it('valida nombres de zona horaria', () => {
		expect(isValidTimeZone('America/Bogota')).toBe(true);
		expect(isValidTimeZone('Marte/Olympus')).toBe(false);
	});
});
