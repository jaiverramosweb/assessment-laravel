// Clases de Tailwind reutilizadas en formularios y botones, para mantener un estilo uniforme.
// Colores de marca definidos en src/routes/layout.css (@theme: brand, ink, canvas).

export const ui = {
	label: 'block text-sm font-semibold text-ink-950',
	hint: 'mt-1 text-xs text-slate-600',
	input:
		'mt-1 block w-full rounded-lg border-slate-300 bg-white px-3 py-2.5 shadow-none focus:border-brand-600 focus:ring-brand-600 sm:text-sm aria-[invalid=true]:border-red-500',
	fieldError: 'mt-1 text-sm text-red-700',
	card: 'rounded-2xl border border-slate-200 bg-white p-6',
	btnPrimary:
		'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-60',
	btnDark:
		'inline-flex items-center justify-center gap-2 rounded-lg bg-ink-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-ink-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-950 disabled:cursor-not-allowed disabled:opacity-60',
	btnSecondary:
		'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-ink-950 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-60',
	btnDanger:
		'inline-flex items-center justify-center gap-2 rounded-lg border border-red-200 bg-white px-4 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600',
	btnIcon:
		'inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-600 hover:bg-slate-50 hover:text-ink-950 disabled:opacity-40',
	alertError: 'rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 ring-1 ring-red-200',
	alertSuccess: 'rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800 ring-1 ring-brand-200',
	alertWarning: 'rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200',
	pageTitle: 'font-display text-3xl font-extrabold tracking-tight text-ink-950',
	sectionTitle: 'font-display text-lg font-bold text-ink-950',
	eyebrow: 'text-xs font-semibold tracking-wider text-slate-600 uppercase'
} as const;
