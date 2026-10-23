<script lang="ts">
	import { Link, page, router } from '@inertiajs/svelte';
	import type { SharedProps } from '@/types';
	import AssessmentLayout, { type AssessmentContext } from '@/Layouts/AssessmentLayout.svelte';
	import BarList, { type BarItem } from '@/components/BarList.svelte';
	import AiAnalysisPanel from '@/components/AiAnalysisPanel.svelte';
	import ParticipantStatusBadge from '@/components/ParticipantStatusBadge.svelte';
	import type { StoredAnalysis } from '@/lib/ai-analysis';
	import type { ParticipantStatus } from '@/lib/constants';
	import { formatDateTime, formatPercent } from '@/lib/format';
	import { ui } from '@/lib/ui';

	// Props from app/Http/Controllers/Admin/Reports/ReportController.php (report: App\Services\Reports\Reports::build).
	type ChoiceStat = {
		id: string;
		number: number;
		prompt: string;
		required: boolean;
		respondents: number;
		omitted: number;
		aligned: number;
		alignmentRate: number | null;
		options: { id: string; text: string; isExpected: boolean; count: number; rate: number }[];
	};
	type OpenStat = {
		id: string;
		number: number;
		prompt: string;
		required: boolean;
		responses: { participantName: string; email: string; area: string | null; cargo: string | null; text: string }[];
	};
	type Report = {
		filters: { area: string | null; cargo: string | null };
		summary: {
			invited: number;
			inProgress: number;
			submitted: number;
			participationRate: number | null;
			averageAlignment: number | null;
			mostAligned: ChoiceStat[];
			leastAligned: ChoiceStat[];
		};
		choice: ChoiceStat[];
		open: OpenStat[];
		participants: {
			id: string;
			name: string;
			email: string;
			area: string | null;
			cargo: string | null;
			status: ParticipantStatus;
			submittedAt: number | null;
		}[];
	};

	type Props = {
		context: AssessmentContext;
		report: Report;
		areas: string[];
		cargos: string[];
		query: string;
		ai: { configured: boolean };
		analyses: Record<string, StoredAnalysis>;
		aiPending: string[];
	};

	let { context, report: r, areas, cargos, query, ai, analyses, aiPending }: Props = $props();

	const a = $derived(context.assessment);
	// Report-only admins can read the AI analyses but not run them (the server refuses it).
	const canRunAi = $derived(($page.props as unknown as SharedProps).admin?.access.full ?? false);
	const s = $derived(r.summary);
	const base = $derived(`/admin/assessments/${context.assessment.id}/reportes`);

	const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

	/** Summary: alignment of each choice question (one series, one color). */
	const overview = $derived<BarItem[]>(
		r.choice.map((c) => ({
			key: c.id,
			label: `${c.number}. ${c.prompt.length > 60 ? c.prompt.slice(0, 57) + '…' : c.prompt}`,
			rate: c.alignmentRate ?? 0,
			value: formatPercent(c.alignmentRate),
			tooltip:
				c.alignmentRate === null
					? `Pregunta ${c.number}: sin respuestas`
					: `Pregunta ${c.number}: ${c.aligned} de ${c.respondents} con la opción esperada (${formatPercent(c.alignmentRate)})`,
			highlight: true,
			// Below the average: amber, to point at the questions to review.
			tone:
				c.alignmentRate !== null && s.averageAlignment !== null && c.alignmentRate < s.averageAlignment
					? 'warn'
					: 'accent',
			href: `#pregunta-${c.number}`
		}))
	);

	function optionBars(c: ChoiceStat): BarItem[] {
		return c.options.map((o) => ({
			key: o.id,
			label: o.text,
			rate: o.rate,
			value: `${o.count} · ${formatPercent(c.respondents ? o.rate : null)}`,
			tooltip: `${o.text}: ${plural(o.count, 'persona', 'personas')} (${formatPercent(c.respondents ? o.rate : null)})`,
			highlight: o.isExpected,
			badge: o.isExpected ? '✓ Esperada' : undefined
		}));
	}

	const hasFilters = $derived(!!(r.filters.area || r.filters.cargo));
	const tiles = $derived([
		{ label: 'Participantes', value: String(s.invited) },
		{ label: 'Entregas', value: String(s.submitted) },
		{ label: 'Tasa de participación', value: formatPercent(s.participationRate) },
		{ label: 'En progreso', value: String(s.inProgress) }
	]);

	function applyFilters(event: SubmitEvent) {
		event.preventDefault();
		const data = new FormData(event.currentTarget as HTMLFormElement);
		const params: Record<string, string> = {};
		for (const [key, value] of data) if (value) params[key] = String(value);
		router.get(base, params, { preserveScroll: true });
	}
</script>

<svelte:head>
	<title>Reportes · {a.title}</title>
</svelte:head>

<AssessmentLayout {context}>
	<div class="space-y-8">
		<!-- Downloads (they respect the applied filters) -->
		<div class="flex flex-wrap items-center justify-between gap-3">
			<p class="text-sm text-slate-600">
				{hasFilters
					? 'Las descargas incluyen solo los participantes del filtro aplicado.'
					: 'Descargue el informe completo.'}
			</p>
			<div class="flex gap-2">
				<a href="{base}/excel{query}" download class={ui.btnSecondary}>Descargar Excel</a>
				<a href="{base}/pdf{query}" download class={ui.btnPrimary}>Descargar PDF</a>
			</div>
		</div>

		<!-- Filters -->
		{#if areas.length || cargos.length}
			<form method="GET" action={base} onsubmit={applyFilters} class="flex flex-wrap items-end gap-3">
				{#if areas.length}
					<div>
						<label for="f-area" class={ui.label}>Área</label>
						<select id="f-area" name="area" value={r.filters.area ?? ''} class={ui.input}>
							<option value="">Todas</option>
							{#each areas as v (v)}<option value={v}>{v}</option>{/each}
						</select>
					</div>
				{/if}
				{#if cargos.length}
					<div>
						<label for="f-cargo" class={ui.label}>Cargo</label>
						<select id="f-cargo" name="cargo" value={r.filters.cargo ?? ''} class={ui.input}>
							<option value="">Todos</option>
							{#each cargos as v (v)}<option value={v}>{v}</option>{/each}
						</select>
					</div>
				{/if}
				<button class={ui.btnSecondary}>Aplicar</button>
				{#if hasFilters}
					<Link href={base} class="py-2 text-sm text-brand-600 hover:underline">Quitar filtros</Link>
				{/if}
			</form>
		{/if}

		<!-- General summary -->
		<section aria-labelledby="resumen" class="space-y-4">
			<h2 id="resumen" class={ui.sectionTitle}>Resumen general</h2>
			<div class="grid gap-4 lg:grid-cols-[minmax(0,20rem)_1fr]">
				<div class="flex flex-col justify-center gap-2 rounded-2xl bg-ink-950 p-6 text-white">
					<p class="text-sm text-ink-300">Alineación promedio</p>
					<p class="font-display text-6xl leading-none font-extrabold tabular-nums">
						{formatPercent(s.averageAlignment)}
					</p>
					<p class="text-sm leading-relaxed text-ink-300">
						Promedio, entre las preguntas de selección, del porcentaje que eligió la opción esperada.
					</p>
				</div>
				<dl class="grid grid-cols-2 gap-4">
					{#each tiles as t (t.label)}
						<div class="flex flex-col gap-1 rounded-2xl border border-slate-200 bg-white px-5 py-4">
							<dt class="text-sm text-slate-600">{t.label}</dt>
							<dd class="font-display text-3xl font-extrabold tabular-nums">{t.value}</dd>
						</div>
					{/each}
				</dl>
			</div>

			{#if s.mostAligned.length}
				<div class="grid gap-4 md:grid-cols-2">
					{#each [{ title: 'Mayor alineación', list: s.mostAligned }, { title: 'Menor alineación', list: s.leastAligned }] as group (group.title)}
						{#if group.list.length}
							<div class={ui.card}>
								<h3 class="text-sm font-semibold">{group.title}</h3>
								<ol class="mt-2 space-y-1 text-sm">
									{#each group.list as c (c.id)}
										<li class="flex justify-between gap-3">
											<a href="#pregunta-{c.number}" class="truncate text-slate-700 hover:underline"
												>{c.number}. {c.prompt}</a
											>
											<span class="shrink-0 font-medium tabular-nums">{formatPercent(c.alignmentRate)}</span>
										</li>
									{/each}
								</ol>
							</div>
						{/if}
					{/each}
				</div>
			{/if}
		</section>

		{#if s.submitted === 0}
			<p class={ui.alertWarning}>
				{hasFilters
					? 'No hay entregas para los filtros seleccionados.'
					: 'Todavía no hay entregas. Los resultados aparecerán cuando los participantes entreguen sus respuestas.'}
			</p>
		{/if}

		<!-- Alignment per question -->
		{#if r.choice.length}
			<section aria-labelledby="alineacion" class={ui.card}>
				<h2 id="alineacion" class={ui.sectionTitle}>Alineación por pregunta</h2>
				<p class="mt-1 mb-5 text-sm text-slate-600">
					Porcentaje de quienes respondieron que eligió la opción esperada.
				</p>
				<BarList items={overview} label="Alineación por pregunta" />
				<p class="mt-5 flex flex-wrap gap-5 text-xs text-slate-600">
					<span class="inline-flex items-center gap-2"
						><span class="h-3 w-3 rounded-sm bg-brand-600" aria-hidden="true"></span>Igual o sobre el
						promedio</span
					>
					<span class="inline-flex items-center gap-2"
						><span class="h-3 w-3 rounded-sm bg-amber-500" aria-hidden="true"></span>Bajo el promedio
						(revisar)</span
					>
				</p>
			</section>
		{/if}

		<!-- Results per question -->
		<section aria-labelledby="por-pregunta" class="space-y-4">
			<h2 id="por-pregunta" class={ui.sectionTitle}>Resultados por pregunta</h2>
			{#each r.choice as c (c.id)}
				<article id="pregunta-{c.number}" class="{ui.card} scroll-mt-20">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<h3 class="font-medium whitespace-pre-line">
							<span class="text-slate-500">{c.number}.</span>
							{c.prompt}
						</h3>
						<p class="text-sm whitespace-nowrap">
							Alineación <strong class="tabular-nums">{formatPercent(c.alignmentRate)}</strong>
						</p>
					</div>
					<p class="mt-1 text-xs text-slate-500">
						{plural(c.respondents, 'persona respondió', 'personas respondieron')} · {plural(
							c.aligned,
							'eligió',
							'eligieron'
						)} la opción esperada{#if c.omitted}
							· {plural(c.omitted, 'la omitió', 'la omitieron')}{/if}
					</p>
					<div class="mt-4">
						<BarList items={optionBars(c)} label="Respuestas de la pregunta {c.number}" />
					</div>
				</article>
			{/each}

			{#if r.open.length}
				<article class="{ui.card} border-l-4 border-brand-400" aria-labelledby="global-titulo">
					<h3 id="global-titulo" class="font-medium">Análisis global de las preguntas abiertas</h3>
					<p class="mt-1 text-xs text-slate-500">
						Combina todas las preguntas abiertas para encontrar temas transversales.
						{#if hasFilters}El análisis con IA siempre usa todas las entregas (no aplica los filtros).{/if}
					</p>
					<div class="mt-4">
						<AiAnalysisPanel
							assessmentId={a.id}
							analysis={analyses.global}
							configured={ai.configured}
							canRun={canRunAi}
							timezone={a.timezone}
							pending={aiPending.includes('global')}
						/>
					</div>
				</article>
			{/if}

			{#each r.open as q (q.id)}
				<article id="pregunta-{q.number}" class="{ui.card} scroll-mt-20">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<h3 class="font-medium whitespace-pre-line">
							<span class="text-slate-500">{q.number}.</span>
							{q.prompt}
						</h3>
						<span class="text-xs whitespace-nowrap text-slate-500">Respuesta abierta</span>
					</div>
					<details class="mt-3">
						<summary class="cursor-pointer text-sm text-brand-700">
							Ver {plural(q.responses.length, 'respuesta', 'respuestas')}
						</summary>
						{#if q.responses.length === 0}
							<p class="mt-3 text-sm text-slate-500">Sin respuestas.</p>
						{:else}
							<ul class="mt-3 divide-y divide-slate-100">
								{#each q.responses as resp (resp.email)}
									<li class="py-3">
										<p class="text-xs text-slate-500">
											{resp.participantName}{resp.area ? ` · ${resp.area}` : ''}{resp.cargo
												? ` · ${resp.cargo}`
												: ''}
										</p>
										<p class="mt-1 text-sm whitespace-pre-line text-slate-800">{resp.text}</p>
									</li>
								{/each}
							</ul>
						{/if}
					</details>
					<div class="mt-4">
						<AiAnalysisPanel
							assessmentId={a.id}
							analysis={analyses[q.id]}
							questionId={q.id}
							configured={ai.configured}
							canRun={canRunAi}
							timezone={a.timezone}
							stale={!hasFilters && !!analyses[q.id] && analyses[q.id].responseCount !== q.responses.length}
							pending={aiPending.includes(q.id)}
						/>
					</div>
				</article>
			{/each}
		</section>

		<!-- Per participant -->
		<section aria-labelledby="por-participante">
			<h2 id="por-participante" class={ui.sectionTitle}>Por participante</h2>
			{#if r.participants.length === 0}
				<p class="mt-3 text-sm text-slate-500">
					No hay participantes{hasFilters ? ' para estos filtros' : ''}.
				</p>
			{:else}
				<div class="mt-3 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
					<table class="min-w-full divide-y divide-slate-200 text-sm">
						<thead class="bg-slate-50 text-left text-slate-600">
							<tr>
								<th scope="col" class="px-4 py-2 font-medium">Nombre</th>
								<th scope="col" class="px-4 py-2 font-medium">Área</th>
								<th scope="col" class="px-4 py-2 font-medium">Cargo</th>
								<th scope="col" class="px-4 py-2 font-medium">Estado</th>
								<th scope="col" class="px-4 py-2 font-medium">Fecha de entrega</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-slate-100">
							{#each r.participants as p (p.id)}
								<tr>
									<td class="px-4 py-2">
										{p.name}
										<span class="block text-xs text-slate-500">{p.email}</span>
									</td>
									<td class="px-4 py-2 text-slate-600">{p.area ?? '—'}</td>
									<td class="px-4 py-2 text-slate-600">{p.cargo ?? '—'}</td>
									<td class="px-4 py-2"><ParticipantStatusBadge status={p.status} /></td>
									<td class="px-4 py-2 whitespace-nowrap text-slate-600">
										{p.submittedAt ? formatDateTime(p.submittedAt, a.timezone) : '—'}
									</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
			{/if}
		</section>
	</div>
</AssessmentLayout>
