<script lang="ts">
	import { router } from '@inertiajs/svelte';
	import AssessmentLayout, { type AssessmentContext } from '@/Layouts/AssessmentLayout.svelte';
	import BarList from '@/components/BarList.svelte';
	import DeltaBadge from '@/components/DeltaBadge.svelte';
	import StatusBadge from '@/components/StatusBadge.svelte';
	import type { AssessmentStatus } from '@/lib/constants';
	import { formatPercent, formatShortDate } from '@/lib/format';
	import { ui } from '@/lib/ui';

	// Props from app/Http/Controllers/Admin/Reports/MeasurementController.php (App\Services\Reports\Comparison::build).
	type Group = { name: string; counts: number[]; scores: (number | null)[]; delta: number | null };
	type Comparison = {
		mode: 'pareado' | 'todos';
		series: { id: string; title: string; order: number; status: AssessmentStatus; submitted: number }[];
		measurements: {
			id: string;
			title: string;
			order: number;
			status: AssessmentStatus;
			startsAt: number;
			endsAt: number;
			timezone: string;
			submitted: number;
			averageScore: number | null;
			averageAlignment: number | null;
		}[];
		summary: {
			paired: number;
			firstScore: number | null;
			lastScore: number | null;
			delta: number | null;
			firstAlignment: number | null;
			lastAlignment: number | null;
			alignmentDelta: number | null;
		};
		choice: {
			position: number;
			prompt: string;
			rates: (number | null)[];
			respondents: number[];
			delta: number | null;
			transitions: { improved: number; keptRight: number; worsened: number; keptWrong: number } | null;
			options: { text: string; isExpected: boolean; rates: (number | null)[] }[];
		}[];
		open: { position: number; prompt: string; responses: { email: string; name: string; texts: (string | null)[] }[] }[];
		people: {
			email: string;
			name: string;
			area: string | null;
			cargo: string | null;
			scores: (number | null)[];
			delta: number | null;
		}[];
		byArea: Group[];
		byCargo: Group[];
	};

	let { context, comparison: c, query }: { context: AssessmentContext; comparison: Comparison | null; query: string } =
		$props();

	const id = $derived(context.assessment.id);
	const base = $derived(`/admin/assessments/${context.assessment.id}/mediciones`);
	const selected = $derived(new Set(c?.measurements.map((m) => m.id) ?? []));
	const many = $derived((c?.measurements.length ?? 0) > 1);

	const bars = $derived(
		(c?.measurements ?? []).map((m) => ({
			key: m.id,
			label: `Medición ${m.order} · ${formatShortDate(m.startsAt, m.timezone)}`,
			rate: m.averageScore ?? 0,
			value: formatPercent(m.averageScore),
			tooltip: `${m.submitted} entregas · alineación ${formatPercent(m.averageAlignment)}`,
			highlight: true
		}))
	);

	const excelHref = $derived(`/admin/assessments/${id}/mediciones/excel${query}`);
	const pdfHref = $derived(`/admin/assessments/${id}/mediciones/pdf${query}`);
	const head = 'py-2 pr-4 text-right font-semibold whitespace-nowrap';

	/** GET with repeated `m` (the server reads ?modo=…&m=<id>&m=<id> like the original). */
	function update(event: SubmitEvent) {
		event.preventDefault();
		const params = new URLSearchParams();
		for (const [key, value] of new FormData(event.currentTarget as HTMLFormElement)) params.append(key, String(value));
		const search = params.toString();
		router.visit(search ? `${base}?${search}` : base, { preserveScroll: true });
	}
</script>

<svelte:head>
	<title>Mediciones · {context.assessment.title}</title>
</svelte:head>

<AssessmentLayout {context}>
	{#if !c}
		<p class="{ui.card} text-center text-slate-600">
			Este assessment no tiene otras mediciones. Créelas desde la pestaña General con "Crear siguiente
			medición".
		</p>
	{:else}
		<div class="flex flex-col gap-6">
			<!-- What is compared -->
			<form method="GET" action={base} onsubmit={update} class="{ui.card} flex flex-col gap-4">
				<div class="flex flex-wrap items-start justify-between gap-4">
					<fieldset>
						<legend class={ui.label}>Mediciones a comparar</legend>
						<div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
							{#each c.series as m (m.id)}
								<label class="flex items-center gap-2 text-sm">
									<input
										type="checkbox"
										name="m"
										value={m.id}
										checked={selected.has(m.id)}
										class="rounded text-brand-600"
									/>
									Medición {m.order}
									<span class="text-slate-500">({m.submitted} entregas)</span>
									{#if m.status === 'borrador'}<StatusBadge status={m.status} />{/if}
								</label>
							{/each}
						</div>
					</fieldset>
					<div class="flex gap-2">
						<a href={excelHref} class={ui.btnSecondary} download>Descargar Excel</a>
						<a href={pdfHref} class={ui.btnPrimary} download>Descargar PDF</a>
					</div>
				</div>
				<fieldset>
					<legend class={ui.label}>Personas incluidas</legend>
					<div class="mt-2 flex flex-wrap gap-x-6 gap-y-2 text-sm">
						<label class="flex items-start gap-2">
							<input
								type="radio"
								name="modo"
								value="pareado"
								checked={c.mode === 'pareado'}
								class="mt-0.5 text-brand-600"
							/>
							<span
								>Solo quienes respondieron todas las mediciones elegidas
								<span class="block text-xs text-slate-500">Comparación justa: las mismas personas.</span
								></span
							>
						</label>
						<label class="flex items-start gap-2">
							<input
								type="radio"
								name="modo"
								value="todos"
								checked={c.mode === 'todos'}
								class="mt-0.5 text-brand-600"
							/>
							<span
								>Todas las entregas de cada medición
								<span class="block text-xs text-slate-500">Incluye a quienes respondieron solo una.</span
								></span
							>
						</label>
					</div>
				</fieldset>
				<div><button class={ui.btnSecondary}>Actualizar comparativo</button></div>
			</form>

			<!-- Summary -->
			<dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
				<div class="flex flex-col gap-1 rounded-2xl border border-slate-200 bg-white px-5 py-4">
					<dt class="text-sm text-slate-600">Acierto promedio</dt>
					<dd class="font-display text-3xl font-extrabold text-brand-700">
						{formatPercent(c.summary.lastScore)}
					</dd>
					<dd class="flex items-center gap-2 text-sm text-slate-600">
						antes {formatPercent(c.summary.firstScore)}
						<DeltaBadge delta={c.summary.delta} />
					</dd>
				</div>
				<div class="flex flex-col gap-1 rounded-2xl border border-slate-200 bg-white px-5 py-4">
					<dt class="text-sm text-slate-600">Alineación promedio</dt>
					<dd class="font-display text-3xl font-extrabold text-ink-950">
						{formatPercent(c.summary.lastAlignment)}
					</dd>
					<dd class="flex items-center gap-2 text-sm text-slate-600">
						antes {formatPercent(c.summary.firstAlignment)}
						<DeltaBadge delta={c.summary.alignmentDelta} />
					</dd>
				</div>
				<div class="flex flex-col gap-1 rounded-2xl border border-slate-200 bg-white px-5 py-4">
					<dt class="text-sm text-slate-600">Respondieron todas</dt>
					<dd class="font-display text-3xl font-extrabold text-ink-950">{c.summary.paired}</dd>
					<dd class="text-sm text-slate-600">
						{c.mode === 'pareado' ? 'son las personas comparadas' : 'personas en todas las mediciones'}
					</dd>
				</div>
				<div class="flex flex-col gap-1 rounded-2xl border border-slate-200 bg-white px-5 py-4">
					<dt class="text-sm text-slate-600">Mediciones</dt>
					<dd class="font-display text-3xl font-extrabold text-ink-950">{c.measurements.length}</dd>
					<dd class="text-sm text-slate-600">de {c.series.length} en la serie</dd>
				</div>
			</dl>
			<p class="-mt-2 text-sm text-slate-600">
				Las diferencias van en <strong>puntos porcentuales (pp)</strong>, de la primera a la última
				medición elegida. <strong>Acierto</strong>: % de preguntas de selección que cada persona
				respondió con la opción esperada. <strong>Alineación</strong>: % de quienes respondieron cada
				pregunta que eligió la opción esperada.
			</p>

			{#if c.mode === 'pareado' && c.summary.paired === 0}
				<p class={ui.alertWarning}>
					Todavía nadie respondió todas las mediciones elegidas. Elija otras mediciones o incluya
					todas las entregas.
				</p>
			{/if}

			{#if many}
				<section class={ui.card} aria-labelledby="evolucion-titulo">
					<h2 id="evolucion-titulo" class={ui.sectionTitle}>Acierto promedio por medición</h2>
					<div class="mt-4"><BarList items={bars} label="Acierto promedio por medición" /></div>
				</section>
			{/if}

			<!-- Per question -->
			{#if c.choice.length}
				<section class={ui.card} aria-labelledby="preguntas-titulo">
					<h2 id="preguntas-titulo" class={ui.sectionTitle}>Alineación por pregunta</h2>
					<div class="mt-4 overflow-x-auto">
						<table class="w-full text-sm">
							<thead class="text-left text-xs tracking-wide text-slate-600 uppercase">
								<tr>
									<th class="py-2 pr-4 font-semibold">Pregunta</th>
									{#each c.measurements as m (m.id)}<th class={head}>Medición {m.order}</th>{/each}
									{#if many}<th class={head}>Diferencia</th>{/if}
									{#if c.mode === 'pareado' && many}<th class="py-2 font-semibold">Cambios</th>{/if}
								</tr>
							</thead>
							<tbody class="divide-y divide-slate-100">
								{#each c.choice as q (q.position)}
									<tr class="align-top">
										<td class="py-2.5 pr-4">
											<details>
												<summary class="cursor-pointer">
													<span class="text-slate-500">{q.position}.</span>
													{q.prompt}
												</summary>
												<ul class="mt-2 space-y-1 text-xs text-slate-700">
													{#each q.options as o (o.text)}
														<li class={o.isExpected ? 'font-semibold text-emerald-800' : ''}>
															{o.isExpected ? '✓' : '○'}
															{o.text}{#if o.isExpected}<span class="sr-only"> (esperada)</span>{/if}:
															{o.rates.map((r) => formatPercent(r)).join(' → ')}
														</li>
													{/each}
												</ul>
											</details>
										</td>
										{#each q.rates as r, i (i)}
											<td class="py-2.5 pr-4 text-right tabular-nums">{formatPercent(r)}</td>
										{/each}
										{#if many}<td class="py-2.5 pr-4 text-right"><DeltaBadge delta={q.delta} /></td>{/if}
										{#if q.transitions}
											<td class="py-2.5 text-xs whitespace-nowrap text-slate-700">
												<span class="text-emerald-800">↑ {q.transitions.improved} mejoraron</span> ·
												<span class="text-red-800">↓ {q.transitions.worsened} empeoraron</span>
											</td>
										{/if}
									</tr>
								{/each}
							</tbody>
						</table>
					</div>
					{#if c.mode === 'pareado' && many}
						<p class="mt-3 text-xs text-slate-600">
							"Mejoraron": personas que pasaron de una opción no esperada a la esperada entre la
							primera y la última medición. Abra cada pregunta para ver cómo se movieron las opciones.
						</p>
					{/if}
				</section>
			{/if}

			<!-- Per person -->
			{#if c.people.length}
				<section class={ui.card} aria-labelledby="personas-titulo">
					<h2 id="personas-titulo" class={ui.sectionTitle}>Acierto por persona</h2>
					<div class="mt-4 overflow-x-auto">
						<table class="w-full text-sm">
							<thead class="text-left text-xs tracking-wide text-slate-600 uppercase">
								<tr>
									<th class="py-2 pr-4 font-semibold">Persona</th>
									<th class="py-2 pr-4 font-semibold">Área</th>
									{#each c.measurements as m (m.id)}<th class={head}>Medición {m.order}</th>{/each}
									{#if many}<th class={head}>Diferencia</th>{/if}
								</tr>
							</thead>
							<tbody class="divide-y divide-slate-100">
								{#each c.people as p (p.email)}
									<tr>
										<td class="py-2.5 pr-4">
											<span class="font-medium">{p.name}</span>
											<span class="block text-xs text-slate-500">{p.email}</span>
										</td>
										<td class="py-2.5 pr-4 text-slate-700">{p.area ?? '—'}</td>
										{#each p.scores as s, i (i)}
											<td class="py-2.5 pr-4 text-right tabular-nums">{formatPercent(s)}</td>
										{/each}
										{#if many}<td class="py-2.5 pr-4 text-right"><DeltaBadge delta={p.delta} /></td>{/if}
									</tr>
								{/each}
							</tbody>
						</table>
					</div>
				</section>
			{/if}

			<!-- By area and cargo -->
			{#each [{ key: 'area', title: 'Por área', label: 'Área', groups: c.byArea }, { key: 'cargo', title: 'Por cargo', label: 'Cargo', groups: c.byCargo }] as block (block.key)}
				{#if block.groups.length}
					<section class={ui.card} aria-labelledby="{block.key}-titulo">
						<h2 id="{block.key}-titulo" class={ui.sectionTitle}>{block.title}</h2>
						<div class="mt-4 overflow-x-auto">
							<table class="w-full text-sm">
								<thead class="text-left text-xs tracking-wide text-slate-600 uppercase">
									<tr>
										<th class="py-2 pr-4 font-semibold">{block.label}</th>
										{#each c.measurements as m (m.id)}<th class={head}>Medición {m.order}</th>{/each}
										{#if many}<th class={head}>Diferencia</th>{/if}
									</tr>
								</thead>
								<tbody class="divide-y divide-slate-100">
									{#each block.groups as g (g.name)}
										<tr>
											<td class="py-2.5 pr-4 {g.name === 'Sin dato' ? 'text-slate-500 italic' : ''}">{g.name}</td>
											{#each g.scores as s, i (i)}
												<td class="py-2.5 pr-4 text-right tabular-nums"
													>{formatPercent(s)}
													<span class="text-xs text-slate-500">({g.counts[i]})</span></td
												>
											{/each}
											{#if many}<td class="py-2.5 pr-4 text-right"><DeltaBadge delta={g.delta} /></td>{/if}
										</tr>
									{/each}
								</tbody>
							</table>
						</div>
						<p class="mt-2 text-xs text-slate-600">Entre paréntesis, cuántas entregas hay en cada medición.</p>
					</section>
				{/if}
			{/each}

			<!-- Open questions -->
			{#each c.open as q (q.position)}
				<section class={ui.card} aria-labelledby="abierta-{q.position}">
					<h2 id="abierta-{q.position}" class={ui.sectionTitle}>
						<span class="text-slate-500">{q.position}.</span>
						{q.prompt}
					</h2>
					{#if q.responses.length === 0}
						<p class="mt-2 text-sm text-slate-600">Sin respuestas.</p>
					{:else}
						<details class="mt-3">
							<summary class="cursor-pointer text-sm font-medium text-brand-700">
								Ver {q.responses.length}
								{q.responses.length === 1 ? 'persona' : 'personas'}, respuesta de cada medición
							</summary>
							<div class="mt-3 overflow-x-auto">
								<table class="w-full text-sm">
									<thead class="text-left text-xs tracking-wide text-slate-600 uppercase">
										<tr>
											<th class="py-2 pr-4 font-semibold">Persona</th>
											{#each c.measurements as m (m.id)}<th class="py-2 pr-4 font-semibold">Medición {m.order}</th>{/each}
										</tr>
									</thead>
									<tbody class="divide-y divide-slate-100">
										{#each q.responses as r (r.email)}
											<tr class="align-top">
												<td class="py-2.5 pr-4 font-medium whitespace-nowrap">{r.name}</td>
												{#each r.texts as t, i (i)}
													<td class="min-w-48 py-2.5 pr-4 whitespace-pre-line text-slate-700">{t ?? '—'}</td>
												{/each}
											</tr>
										{/each}
									</tbody>
								</table>
							</div>
						</details>
					{/if}
				</section>
			{/each}
		</div>
	{/if}
</AssessmentLayout>
