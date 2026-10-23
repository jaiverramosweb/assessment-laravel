<script lang="ts">
	import { page, router } from '@inertiajs/svelte';
	import { FREQUENCY_LABELS, type StoredAnalysis } from '@/lib/ai-analysis';
	import { formatDateTime } from '@/lib/format';
	import { ui } from '@/lib/ui';

	// Panel de análisis con IA de una pregunta abierta (o del análisis global).
	// Todo el contenido generado por la IA se muestra como texto (nunca {@html}).
	// El análisis corre en la cola del servidor: mientras está pendiente, el panel recarga
	// `analyses`, `aiPending` y `aiErrors` cada pocos segundos hasta que termina.
	let {
		assessmentId,
		analysis,
		questionId,
		configured,
		timezone,
		stale = false,
		pending = false,
		canRun = true
	}: {
		assessmentId: string;
		analysis: StoredAnalysis | undefined;
		questionId?: string;
		configured: boolean;
		timezone: string;
		stale?: boolean;
		pending?: boolean;
		/** False for report-only admins: they read the analysis but cannot run it. */
		canRun?: boolean;
	} = $props();

	const POLL_MS = 4000;

	type AiPageProps = { aiErrors?: Record<string, string>; aiCached?: string | null };

	const key = $derived(questionId ?? 'global');
	const shared = $derived($page.props as unknown as AiPageProps);
	const error = $derived(shared.aiErrors?.[key]);
	const cachedNotice = $derived(shared.aiCached === key);

	let sending = $state(false);
	const working = $derived(sending || pending);

	function analyze() {
		router.post(
			`/admin/assessments/${assessmentId}/ia/analizar`,
			{ ...(questionId ? { questionId } : {}), regenerar: !!analysis },
			{
				preserveScroll: true,
				onStart: () => (sending = true),
				onFinish: () => (sending = false)
			}
		);
	}

	$effect(() => {
		if (!pending) return;
		const timer = setInterval(() => {
			router.reload({ only: ['analyses', 'aiPending', 'aiErrors'] });
		}, POLL_MS);
		return () => clearInterval(timer);
	});

	const freqStyle = {
		alta: 'bg-brand-100 text-brand-900 ring-brand-300',
		media: 'bg-slate-100 text-slate-800 ring-slate-300',
		baja: 'bg-white text-slate-600 ring-slate-200'
	};
</script>

<div class="rounded-md bg-slate-50 p-4 ring-1 ring-slate-200">
	<div class="flex flex-wrap items-center justify-between gap-3">
		<p class="text-sm font-semibold">Análisis con IA</p>
		{#if configured && canRun}
			<button type="button" class={analysis ? ui.btnSecondary : ui.btnPrimary} disabled={working} onclick={analyze}>
				{#if working}
					<span
						class="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-current border-r-transparent"
						aria-hidden="true"
					></span>
					Analizando…
				{:else}
					{analysis ? 'Regenerar' : 'Analizar con IA'}
				{/if}
			</button>
		{/if}
	</div>

	{#if working}
		<p class="mt-2 text-xs text-slate-500" role="status">
			Enviando las respuestas anonimizadas a la IA. Puede tardar uno o dos minutos.
		</p>
	{/if}
	{#if error && !working}
		<p class="mt-3 {ui.alertError}" role="alert">{error}</p>
	{/if}
	{#if !canRun && !analysis}
		<p class="mt-2 text-sm text-slate-600">Todavía no hay un análisis con IA para esta pregunta.</p>
	{:else if !configured && canRun}
		<p class="mt-2 text-sm text-slate-600">
			Para habilitarlo, configure <code>ANTHROPIC_API_KEY</code> en el archivo <code>.env</code> del servidor.
		</p>
	{/if}

	{#if analysis}
		{@const r = analysis.result}
		{#if stale}
			<p class="mt-3 {ui.alertWarning}">
				Hay respuestas nuevas desde este análisis ({analysis.responseCount} analizadas). Use "Regenerar"
				para actualizarlo.
			</p>
		{/if}
		{#if cachedNotice}
			<p class="mt-3 text-xs text-slate-500" role="status">
				Las respuestas no cambiaron: se muestra el análisis guardado.
			</p>
		{/if}

		<p class="mt-3 text-sm whitespace-pre-line text-slate-800">{r.summary}</p>

		{#if r.themes.length}
			<h4 class="mt-4 text-sm font-semibold">Temas principales</h4>
			<ul class="mt-2 space-y-2">
				{#each r.themes as t, i (i)}
					<li class="text-sm">
						<span class="font-medium">{t.name}</span>
						<span
							class="ml-1 inline-flex rounded-full px-1.5 py-0.5 text-xs ring-1 ring-inset {freqStyle[
								t.frequency
							]}"
						>
							Frecuencia {FREQUENCY_LABELS[t.frequency].toLowerCase()} · ~{t.approxCount}
						</span>
						<span class="block text-slate-600">{t.description}</span>
					</li>
				{/each}
			</ul>
		{/if}

		<div class="mt-4 grid gap-4 md:grid-cols-2">
			{#if r.highlights.length}
				<div>
					<h4 class="text-sm font-semibold">Puntos destacados</h4>
					<ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-slate-700">
						{#each r.highlights as h, i (i)}<li>{h}</li>{/each}
					</ul>
				</div>
			{/if}
			{#if r.concerns.length}
				<div>
					<h4 class="text-sm font-semibold">Preocupaciones recurrentes</h4>
					<ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-slate-700">
						{#each r.concerns as c, i (i)}<li>{c}</li>{/each}
					</ul>
				</div>
			{/if}
		</div>

		{#if r.quotes.length}
			<h4 class="mt-4 text-sm font-semibold">Citas representativas</h4>
			<ul class="mt-2 space-y-2">
				{#each r.quotes as q, i (i)}
					<li class="border-l-2 border-brand-300 pl-3 text-sm text-slate-700 italic">“{q}”</li>
				{/each}
			</ul>
		{/if}

		<p class="mt-4 text-xs text-slate-500">
			Generado el {formatDateTime(analysis.createdAt, timezone)} con {analysis.model} sobre
			{analysis.responseCount}
			{analysis.responseCount === 1 ? 'respuesta' : 'respuestas'} anónimas.
		</p>
	{:else if configured && !working}
		<p class="mt-2 text-sm text-slate-500">
			Aún no se ha generado. Las respuestas se envían sin nombres ni correos.
		</p>
	{/if}
</div>
