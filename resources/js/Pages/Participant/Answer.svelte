<script lang="ts">
	import { page, router } from '@inertiajs/svelte';
	import { onDestroy, tick } from 'svelte';
	import { formatLongDateTime } from '@/lib/format';
	import { ui } from '@/lib/ui';
	import ClientLogo from '@/components/ClientLogo.svelte';
	import { saveDraft } from '@/components/participant/autosave';

	type Question = {
		id: string;
		type: 'opcion_unica' | 'abierta';
		prompt: string;
		required: boolean;
		options: { id: string; text: string }[];
	};
	type Props = {
		assessment: {
			title: string;
			instructions: string;
			openMaxChars: number;
			endsAt: number;
			timezone: string;
			logoUrl: string | null;
			themeStyle: string | null;
		};
		participant: { name: string; email: string };
		// Without the correct option (see App\Services\ParticipantFlow\Answers::questionsFor).
		questions: Question[];
		answers: Record<string, { optionId: string | null; text: string | null }>;
		savedAt: number;
		// Result of the last failed submission (flashed by the server), or null.
		form: { errors: string[]; missing: number[] } | null;
	};

	let { assessment: a, participant, questions, answers, savedAt, form }: Props = $props();
	const base = $derived(new URL($page.url, 'http://x').pathname.replace(/\/$/, ''));

	// ---- Respuestas en el navegador -----------------------------------------
	// Valor por pregunta: id de la opción elegida o el texto escrito.
	// svelte-ignore state_referenced_locally
	let values = $state<Record<string, string>>(
		Object.fromEntries(
			questions.map((q) => [q.id, answers[q.id]?.optionId ?? answers[q.id]?.text ?? ''])
		)
	);

	const answered = $derived(questions.filter((q) => values[q.id]?.trim()).length);

	// ---- Autoguardado -------------------------------------------------------
	type SaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'error';
	let saveState = $state<SaveState>('idle');
	// svelte-ignore state_referenced_locally
	let lastSaved = $state<Date | null>(Object.keys(answers).length ? new Date(savedAt) : null);
	let saveError = $state('');
	// Contabilidad interna del autoguardado: no se muestra, así que no necesita ser reactiva.
	const pending = new Map<string, string>();
	const timers = new Map<string, ReturnType<typeof setTimeout>>();
	let inFlight: Promise<void> | null = null;

	/** Envía al servidor los cambios pendientes (todas las preguntas modificadas a la vez). */
	async function flush(): Promise<void> {
		if (inFlight) await inFlight;
		if (pending.size === 0) return;

		const body: Record<string, string> = {};
		for (const [id, v] of pending) body[`q_${id}`] = v;
		const sent = new Map(pending);
		pending.clear();
		saveState = 'saving';

		inFlight = (async () => {
			try {
				const result = await saveDraft(`${base}/guardar`, body);
				if (result.type === 'success') {
					saveState = pending.size ? 'pending' : 'saved';
					lastSaved = new Date();
					saveError = '';
				} else if (result.type === 'redirect') {
					// Sesión vencida, assessment cerrado o ya entregado: la URL la define el servidor.
					router.visit(result.location);
				} else {
					saveError = result.errors?.join(' ') ?? 'No se pudo guardar. Revise su conexión.';
					saveState = 'error';
					// Se reintentan los cambios en el próximo guardado.
					for (const [id, v] of sent) if (!pending.has(id)) pending.set(id, v);
				}
			} catch {
				saveError = 'Sin conexión. Sus cambios se guardarán al recuperarla.';
				saveState = 'error';
				for (const [id, v] of sent) if (!pending.has(id)) pending.set(id, v);
			} finally {
				inFlight = null;
			}
		})();
		await inFlight;
	}

	/** Programa el guardado: inmediato para opciones, con pausa de 1,5 s para texto. */
	function schedule(questionId: string, delayMs: number) {
		pending.set(questionId, values[questionId] ?? '');
		saveState = 'pending';
		clearTimeout(timers.get(questionId));
		timers.set(
			questionId,
			setTimeout(() => {
				timers.delete(questionId);
				void flush();
			}, delayMs)
		);
	}

	// Reintento periódico si hubo error de conexión.
	const retry = setInterval(() => {
		if (saveState === 'error' && pending.size) void flush();
	}, 10_000);
	onDestroy(() => {
		clearInterval(retry);
		for (const t of timers.values()) clearTimeout(t);
	});

	function onBeforeUnload(e: BeforeUnloadEvent) {
		if (pending.size || saveState === 'saving') e.preventDefault();
	}

	const timeText = (d: Date) => d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
	const statusText = $derived(
		{
			idle: lastSaved ? `Guardado ${timeText(lastSaved)}` : '',
			pending: 'Cambios sin guardar…',
			saving: 'Guardando…',
			saved: lastSaved ? `Guardado ${timeText(lastSaved)}` : 'Guardado',
			error: saveError
		}[saveState]
	);

	let entregando = $state(false);
	const missing = $derived(new Set<number>(form?.missing ?? []));
	const fmt = (n: number) => n.toLocaleString('es-CO');
	const percent = $derived(questions.length ? Math.round((answered / questions.length) * 100) : 0);

	// ---- Pasos de 3 preguntas -----------------------------------------------
	const PAGE_SIZE = 3;
	const pageCount = $derived(Math.max(1, Math.ceil(questions.length / PAGE_SIZE)));
	const pageOf = (i: number) => Math.floor(i / PAGE_SIZE);
	let step = $state(0);
	const isLast = $derived(step >= pageCount - 1);
	const pages = $derived(
		Array.from({ length: pageCount }, (_, p) => {
			const qs = questions.slice(p * PAGE_SIZE, (p + 1) * PAGE_SIZE);
			return { answered: qs.filter((q) => values[q.id]?.trim()).length, total: qs.length };
		})
	);
	const firstOnPage = $derived(step * PAGE_SIZE + 1);
	const lastOnPage = $derived(Math.min((step + 1) * PAGE_SIZE, questions.length));
	let stepHeading = $state<HTMLElement>();

	async function goTo(p: number) {
		if (p === step || p < 0 || p >= pageCount) return;
		void flush(); // Guarda lo pendiente del paso que se deja.
		step = p;
		await tick();
		window.scrollTo({ top: 0, behavior: 'smooth' });
		stepHeading?.focus({ preventScroll: true });
	}

	// Si la entrega falla por preguntas obligatorias, se muestra el paso de la primera que falta.
	$effect(() => {
		const first = form?.missing?.[0];
		if (first) step = pageOf(first - 1);
	});

	/** Entrega final: envía todas las respuestas del formulario y el servidor entrega. */
	function submit(event: SubmitEvent) {
		event.preventDefault();
		if (
			!confirm(
				'¿Entregar sus respuestas? Una vez entregadas no podrá modificarlas ni volver a responder.'
			)
		) {
			return;
		}
		entregando = true;
		// Los temporizadores pendientes ya no hacen falta: el formulario envía todo.
		for (const t of timers.values()) clearTimeout(t);
		timers.clear();
		pending.clear();

		// Como un formulario HTML: las opciones solo si hay una elegida, los textos siempre.
		const data: Record<string, string> = {};
		for (const q of questions) {
			const v = values[q.id] ?? '';
			if (q.type === 'abierta' || v !== '') data[`q_${q.id}`] = v;
		}
		router.post(`${base}/entregar`, data, {
			preserveState: true,
			preserveScroll: true,
			onFinish: () => (entregando = false)
		});
	}

	// Anillo de avance del banner.
	const RING = 2 * Math.PI * 26;
</script>

<svelte:window onbeforeunload={onBeforeUnload} />

<svelte:head>
	<title>{a.title}</title>
	<meta name="robots" content="noindex" />
</svelte:head>

<!-- style: colores del cliente (variables de Tailwind), calculados en el servidor. -->
<div class="min-h-screen" style={a.themeStyle}>
	<!-- Banner del cliente: color principal, logo grande y avance general. -->
	<header class="bg-ink-950 text-white">
		<div
			class="mx-auto flex max-w-3xl flex-col items-start gap-4 px-4 pt-7 pb-14 sm:flex-row sm:items-center sm:gap-6 sm:pt-9 sm:pb-16"
		>
			<span class="shrink-0"><ClientLogo src={a.logoUrl} size="lg" /></span>
			<div class="min-w-0 flex-1">
				<h1 class="font-display text-xl leading-tight font-extrabold sm:text-3xl">{a.title}</h1>
				<p class="mt-2 text-sm text-ink-300">
					Hola, {participant.name} · Cierra el {formatLongDateTime(a.endsAt, a.timezone)}
				</p>
			</div>
			<div class="relative hidden h-16 w-16 shrink-0 sm:block" aria-hidden="true">
				<svg viewBox="0 0 60 60" class="h-16 w-16 -rotate-90">
					<circle
						cx="30"
						cy="30"
						r="26"
						fill="none"
						stroke="currentColor"
						stroke-width="6"
						class="text-white/20"
					/>
					<circle
						cx="30"
						cy="30"
						r="26"
						fill="none"
						stroke="currentColor"
						stroke-width="6"
						stroke-linecap="round"
						stroke-dasharray={RING}
						stroke-dashoffset={RING * (1 - percent / 100)}
						class="text-white transition-all duration-500"
					/>
				</svg>
				<span class="absolute inset-0 flex items-center justify-center text-sm font-bold"
					>{percent}%</span
				>
			</div>
		</div>
	</header>

	<main class="mx-auto -mt-9 max-w-3xl px-4 pb-10">
		<!-- Tarjeta de pasos: queda fija arriba al bajar, con el estado del guardado. -->
		<div
			class="sticky top-2 z-10 mb-6 rounded-2xl border border-slate-200 bg-white/95 px-4 py-3 shadow-sm backdrop-blur"
		>
			<div class="flex flex-wrap items-center gap-x-4 gap-y-2">
				<p class="text-sm font-semibold">
					{#if pageCount > 1}Paso {step + 1} de {pageCount}{:else}Sus respuestas{/if}
				</p>
				{#if pageCount > 1}
					<ol class="flex min-w-32 flex-1 gap-1.5" aria-label="Pasos">
						{#each pages as p, n (n)}
							<li class="flex-1">
								<button
									type="button"
									onclick={() => goTo(n)}
									aria-label="Ir al paso {n + 1} ({p.answered} de {p.total} respondidas)"
									aria-current={n === step ? 'step' : undefined}
									class="block w-full py-1.5"
								>
									<span
										class="block h-2 overflow-hidden rounded-full bg-slate-200 {n === step
											? 'ring-2 ring-brand-300'
											: ''}"
									>
										<span
											class="block h-2 rounded-full bg-brand-600 transition-all"
											style="width: {(p.answered / p.total) * 100}%"
										></span>
									</span>
								</button>
							</li>
						{/each}
					</ol>
				{:else}
					<div
						class="h-2 min-w-32 flex-1 overflow-hidden rounded-full bg-slate-200"
						aria-hidden="true"
					>
						<div
							class="h-2 rounded-full bg-brand-600 transition-all"
							style="width: {percent}%"
						></div>
					</div>
				{/if}
				<p
					class="inline-flex shrink-0 items-center gap-1.5 text-xs {saveState === 'error'
						? 'text-red-700'
						: 'text-slate-600'}"
					role="status"
					aria-live="polite"
				>
					{#if saveState === 'saved' || saveState === 'idle'}
						<svg
							width="14"
							height="14"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							stroke-width="2.6"
							stroke-linecap="round"
							stroke-linejoin="round"
							class="text-brand-600"
							aria-hidden="true"><path d="M5 12l5 5 9-10"></path></svg
						>
					{/if}
					{statusText}
				</p>
			</div>
			<p class="mt-1 text-xs text-slate-600">
				{participant.name} · {answered} de {questions.length} respondidas
			</p>
		</div>

		{#if pageCount > 1}
			<h2
				bind:this={stepHeading}
				tabindex="-1"
				class="mb-4 text-xs font-semibold tracking-wider text-slate-600 uppercase outline-none"
			>
				Preguntas {firstOnPage}{lastOnPage > firstOnPage ? ` a ${lastOnPage}` : ''} de {questions.length}
			</h2>
		{/if}

		{#if a.instructions && step === 0}
			<section class="mb-6 rounded-2xl bg-ink-950 p-6 text-white" aria-labelledby="instr">
				<h2 id="instr" class="text-xs font-semibold tracking-wider text-ink-300 uppercase">
					Instrucciones
				</h2>
				<p class="mt-2 text-[15px] leading-relaxed whitespace-pre-line">{a.instructions}</p>
			</section>
		{/if}
		{#if step === 0}
			<p class="mb-6 text-sm text-slate-600">
				Las preguntas marcadas con
				<span class="text-red-600" aria-hidden="true">*</span><span class="sr-only">asterisco</span>
				son obligatorias. Sus respuestas se guardan solas mientras avanza.
			</p>
		{/if}

		<form method="POST" onsubmit={submit}>
			<ol class="space-y-6">
				{#each questions as q, i (q.id)}
					<li
						hidden={pageOf(i) !== step}
						id="pregunta-{i + 1}"
						class="scroll-mt-24 rounded-2xl border bg-white p-5 sm:p-6 {missing.has(i + 1)
							? 'border-red-300 ring-2 ring-red-200'
							: 'border-slate-200'}"
					>
						{#if q.type === 'opcion_unica'}
							<fieldset>
								<legend class="font-display text-lg leading-snug font-bold">
									<span class="text-brand-700">{i + 1}.</span>
									<span class="whitespace-pre-line">{q.prompt}</span>
									{#if q.required}<span class="text-red-600" aria-hidden="true"> *</span><span
											class="sr-only">(obligatoria)</span
										>{:else}<span class="text-sm font-normal text-slate-500"> (opcional)</span>{/if}
								</legend>
								<div class="mt-4 space-y-2.5">
									{#each q.options as o (o.id)}
										<label
											class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-300 px-4 py-3.5 text-[15px] hover:bg-slate-50 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:font-semibold has-[:checked]:ring-1 has-[:checked]:ring-brand-600"
										>
											<input
												type="radio"
												name="q_{q.id}"
												value={o.id}
												bind:group={values[q.id]}
												onchange={() => schedule(q.id, 0)}
												class="mt-0.5 text-brand-600 focus:ring-brand-500"
											/>
											<span>{o.text}</span>
										</label>
									{/each}
								</div>
							</fieldset>
						{:else}
							<label for="q-{q.id}" class="mb-3 block font-display text-lg leading-snug font-bold">
								<span class="text-brand-700">{i + 1}.</span>
								<span class="whitespace-pre-line">{q.prompt}</span>
								{#if q.required}<span class="text-red-600" aria-hidden="true"> *</span><span
										class="sr-only">(obligatoria)</span
									>{:else}<span class="text-sm font-normal text-slate-500"> (opcional)</span>{/if}
							</label>
							<textarea
								id="q-{q.id}"
								name="q_{q.id}"
								rows="8"
								maxlength={a.openMaxChars}
								bind:value={values[q.id]}
								oninput={() => schedule(q.id, 1500)}
								onblur={() => {
									if (pending.has(q.id)) void flush();
								}}
								aria-describedby="contador-{q.id}"
								class="{ui.input} resize-y"></textarea>
							<p
								id="contador-{q.id}"
								class="mt-1 text-right text-xs tabular-nums {(values[q.id]?.length ?? 0) >=
								a.openMaxChars * 0.95
									? 'text-amber-700'
									: 'text-slate-500'}"
							>
								{fmt(values[q.id]?.length ?? 0)} / {fmt(a.openMaxChars)} caracteres
							</p>
						{/if}
					</li>
				{/each}
			</ol>

			{#if form?.errors?.length}
				<div class="mt-6 {ui.alertError}" role="alert">
					{#each form.errors as e (e)}<p>{e}</p>{/each}
				</div>
			{/if}

			<div class="mt-8 flex flex-wrap items-center gap-3">
				{#if step > 0}
					<button type="button" class={ui.btnSecondary} onclick={() => goTo(step - 1)}
						>← Anterior</button
					>
				{/if}
				<span class="flex-1"></span>
				{#if isLast}
					<button type="submit" class={ui.btnPrimary} disabled={entregando}>
						{entregando ? 'Entregando…' : 'Entregar respuestas'}
					</button>
				{:else}
					<button type="button" class={ui.btnPrimary} onclick={() => goTo(step + 1)}
						>Siguiente →</button
					>
				{/if}
			</div>
		</form>
	</main>
</div>
