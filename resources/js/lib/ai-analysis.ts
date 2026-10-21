import { z } from 'zod';

// Estructura del análisis con IA de respuestas abiertas. Se valida con Zod lo que
// devuelve el modelo antes de guardarlo, y es lo que muestran la pantalla y el PDF.

export const FREQUENCIES = ['alta', 'media', 'baja'] as const;

export const aiAnalysisSchema = z.object({
	summary: z.string().min(1).max(4000),
	themes: z
		.array(
			z.object({
				name: z.string().min(1).max(200),
				description: z.string().max(1000),
				frequency: z.enum(FREQUENCIES),
				/** Cantidad aproximada de respuestas que tocan el tema. */
				approxCount: z.number().int().min(0)
			})
		)
		.max(15),
	highlights: z.array(z.string().max(600)).max(15),
	concerns: z.array(z.string().max(600)).max(15),
	quotes: z.array(z.string().max(400)).max(12)
});

export type AiAnalysis = z.infer<typeof aiAnalysisSchema>;

/**
 * Esquema que se le pide al modelo (salida estructurada). Sin límites de largo:
 * esos se aplican después con `normalizeAnalysis`, recortando en vez de fallar.
 */
export const aiOutputSchema = z.object({
	summary: z.string(),
	themes: z.array(
		z.object({
			name: z.string(),
			description: z.string(),
			frequency: z.enum(FREQUENCIES),
			approxCount: z.number().int()
		})
	),
	highlights: z.array(z.string()),
	concerns: z.array(z.string()),
	quotes: z.array(z.string())
});

const cut = (s: string, n: number) => (s.length > n ? s.slice(0, n - 1).trimEnd() + '…' : s);

/** Ajusta la salida del modelo a los límites del esquema guardado. */
export function normalizeAnalysis(raw: z.infer<typeof aiOutputSchema>): AiAnalysis {
	return aiAnalysisSchema.parse({
		summary: cut(raw.summary.trim(), 4000) || 'Sin resumen.',
		themes: raw.themes.slice(0, 15).map((t) => ({
			name: cut(t.name.trim(), 200) || 'Tema',
			description: cut(t.description.trim(), 1000),
			frequency: t.frequency,
			approxCount: Math.max(0, Math.round(t.approxCount))
		})),
		highlights: raw.highlights.slice(0, 15).map((s) => cut(s, 600)),
		concerns: raw.concerns.slice(0, 15).map((s) => cut(s, 600)),
		quotes: raw.quotes.slice(0, 12).map((s) => cut(s, 400))
	});
}

export const FREQUENCY_LABELS: Record<(typeof FREQUENCIES)[number], string> = {
	alta: 'Alta',
	media: 'Media',
	baja: 'Baja'
};

/** Análisis guardado, como lo consumen la pantalla y las exportaciones. */
export type StoredAnalysis = {
	id: string;
	questionId: string | null;
	model: string;
	createdAt: Date;
	responseCount: number;
	/** Huella de las respuestas analizadas: si no cambió, no hace falta volver a llamar a la IA. */
	inputHash: string;
	result: AiAnalysis;
};
