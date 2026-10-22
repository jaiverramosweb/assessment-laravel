<script lang="ts">
	import { page, router } from '@inertiajs/svelte';
	import { tick } from 'svelte';
	import AssessmentLayout, { type AssessmentContext } from '@/Layouts/AssessmentLayout.svelte';
	import WordImport, { type WordPreview } from '@/components/questions/WordImport.svelte';
	import { MAX_OPTIONS, MIN_OPTIONS } from '@/components/questions/limits';
	import { QUESTION_TYPE_LABELS, type QuestionType } from '@/lib/constants';
	import { ui } from '@/lib/ui';

	type AdminQuestion = {
		id: string;
		type: QuestionType;
		prompt: string;
		required: boolean;
		position: number;
		options: { id: string; text: string; isCorrect: boolean; position: number }[];
	};
	type Result = {
		saved?: number;
		errors?: string[];
		imported?: number;
		modo?: 'agregar' | 'reemplazar';
		importErrors?: string[];
	} | null;

	let {
		context,
		questions: serverQuestions,
		result
	}: { context: AssessmentContext; questions: AdminQuestion[]; result: Result } = $props();

	// ---- Local editor state ------------------------------------------------------
	// Every question and option carries a stable local `key` for {#each} and the input ids.
	type EditOption = { key: string; text: string; isCorrect: boolean };
	type EditQuestion = {
		key: string;
		type: QuestionType;
		prompt: string;
		required: boolean;
		options: EditOption[];
	};

	const newKey = () => crypto.randomUUID();
	const blankOptions = (): EditOption[] =>
		Array.from({ length: MIN_OPTIONS }, (_, i) => ({ key: newKey(), text: '', isCorrect: i === 0 }));

	function fromData(list: AdminQuestion[]): EditQuestion[] {
		return list.map((q) => ({
			key: newKey(),
			type: q.type,
			prompt: q.prompt,
			required: q.required,
			options: q.options.map((o) => ({ key: newKey(), text: o.text, isCorrect: o.isCorrect }))
		}));
	}

	/** What is sent to the server (without the local keys). */
	function toPayload(list: EditQuestion[]) {
		return list.map((q) =>
			q.type === 'opcion_unica'
				? {
						type: q.type,
						prompt: q.prompt,
						required: q.required,
						options: q.options.map((o) => ({ text: o.text, isCorrect: o.isCorrect }))
					}
				: { type: q.type, prompt: q.prompt, required: q.required }
		);
	}

	// The editor starts from the saved questions; later it only resets after a save or an import.
	// svelte-ignore state_referenced_locally
	let questions = $state<EditQuestion[]>(fromData(serverQuestions));
	// svelte-ignore state_referenced_locally
	let savedJson = $state(JSON.stringify(toPayload(fromData(serverQuestions))));
	const payloadJson = $derived(JSON.stringify(toPayload(questions)));
	const dirty = $derived(payloadJson !== savedJson);
	let guardando = $state(false);
	let importando = $state(false);
	let loadedNotice = $state('');

	const counts = $derived({
		choice: questions.filter((q) => q.type === 'opcion_unica').length,
		open: questions.filter((q) => q.type === 'abierta').length
	});

	function resetFromServer(list: AdminQuestion[]) {
		questions = fromData(list);
		savedJson = JSON.stringify(toPayload(questions));
	}

	async function addQuestion(type: QuestionType) {
		const q: EditQuestion = {
			key: newKey(),
			type,
			prompt: '',
			required: true,
			options: type === 'opcion_unica' ? blankOptions() : []
		};
		questions.push(q);
		await tick();
		document.getElementById(`prompt-${q.key}`)?.focus();
	}

	function removeQuestion(i: number) {
		const q = questions[i];
		if (q.prompt.trim() && !confirm(`¿Eliminar la pregunta ${i + 1}?`)) return;
		questions.splice(i, 1);
	}

	function move(i: number, delta: -1 | 1) {
		const j = i + delta;
		if (j < 0 || j >= questions.length) return;
		[questions[i], questions[j]] = [questions[j], questions[i]];
	}

	function setCorrect(q: EditQuestion, key: string) {
		for (const o of q.options) o.isCorrect = o.key === key;
	}

	async function addOption(q: EditQuestion) {
		const o = { key: newKey(), text: '', isCorrect: false };
		q.options.push(o);
		await tick();
		document.getElementById(`option-${o.key}`)?.focus();
	}

	function removeOption(q: EditQuestion, key: string) {
		const wasCorrect = q.options.find((o) => o.key === key)?.isCorrect;
		q.options = q.options.filter((o) => o.key !== key);
		if (wasCorrect && q.options.length) q.options[0].isCorrect = true;
	}

	// Warning when leaving with unsaved changes (own form posts do not ask).
	$effect(() =>
		router.on('before', (event) => {
			const visit = event.detail.visit;
			if (!dirty || visit.method !== 'get') return;
			if (!confirm('Hay cambios sin guardar. ¿Salir de todas formas?')) event.preventDefault();
		})
	);

	function onBeforeUnload(e: BeforeUnloadEvent) {
		if (dirty) e.preventDefault();
	}

	const base = $derived(`/admin/assessments/${context.assessment.id}`);
	const created = $derived(new URL($page.url, 'http://x').searchParams.get('creado') === '1');

	function savedQuestions(p: { props: Record<string, unknown> }): AdminQuestion[] {
		return (p.props.questions as AdminQuestion[]) ?? [];
	}

	function save(event: SubmitEvent) {
		event.preventDefault();
		router.post(
			`${base}/preguntas/guardar`,
			{ payload: payloadJson },
			{
				preserveScroll: true,
				onStart: () => (guardando = true),
				onFinish: () => (guardando = false),
				onSuccess: (p) => {
					if ((p.props.result as Result)?.saved !== undefined) resetFromServer(savedQuestions(p));
				}
			}
		);
	}

	function importExcel(event: SubmitEvent) {
		event.preventDefault();
		const data = new FormData(event.currentTarget as HTMLFormElement);
		router.post(`${base}/preguntas/importar`, data, {
			forceFormData: true,
			preserveScroll: true,
			onStart: () => (importando = true),
			onFinish: () => (importando = false),
			onSuccess: (p) => {
				if ((p.props.result as Result)?.imported !== undefined) resetFromServer(savedQuestions(p));
			}
		});
	}

	function duplicate(event: SubmitEvent) {
		event.preventDefault();
		router.post(`${base}/duplicar`, {}, { preserveState: false });
	}

	/** Loads the Word preview in the editor without saving. */
	async function applyPreview(preview: WordPreview, mode: 'agregar' | 'reemplazar'): Promise<boolean> {
		if (
			mode === 'reemplazar' &&
			questions.length &&
			!confirm(`¿Reemplazar las ${questions.length} preguntas del editor por las del documento?`)
		) {
			return false;
		}
		const loaded: EditQuestion[] = preview.questions.map((q) => ({
			key: newKey(),
			type: q.type,
			prompt: q.prompt,
			required: q.required,
			options:
				q.type === 'opcion_unica'
					? q.options.map((o) => ({ key: newKey(), text: o.text, isCorrect: o.isCorrect }))
					: []
		}));
		const firstIndex = mode === 'agregar' ? questions.length : 0;
		questions = mode === 'agregar' ? [...questions, ...loaded] : loaded;
		loadedNotice = `Se cargaron ${loaded.length} preguntas del documento en el editor. Revíselas y pulse "Guardar preguntas" para guardarlas.`;
		await tick();
		document.getElementById(`pregunta-${firstIndex + 1}`)?.scrollIntoView({ block: 'start' });
		return true;
	}

	/** What the editor would not let save in a choice question. */
	function reviewHint(q: EditQuestion): string | null {
		if (q.type !== 'opcion_unica') return null;
		if (q.options.length < MIN_OPTIONS) return `Necesita al menos ${MIN_OPTIONS} opciones.`;
		if (q.options.length > MAX_OPTIONS) return `Puede tener máximo ${MAX_OPTIONS} opciones.`;
		if (q.options.filter((o) => o.isCorrect).length !== 1) return 'Marque la opción correcta.';
		return null;
	}
</script>

<svelte:window onbeforeunload={onBeforeUnload} />

<svelte:head>
	<title>Preguntas · {context.assessment.title}</title>
</svelte:head>

<AssessmentLayout {context}>
	{#if created && serverQuestions.length === 0}
		<p class="mb-6 {ui.alertSuccess}" role="status">
			Assessment creado. Ahora agregue las preguntas o impórtelas desde Word o Excel.
		</p>
	{/if}

	{#if context.locked}
		<!-- Read-only view: there are responses or the assessment is part of a series -->
		<div class="{ui.alertWarning} flex flex-wrap items-center justify-between gap-3">
			<p>{context.lockReason}</p>
			<form method="POST" action="{base}/duplicar" onsubmit={duplicate}>
				<button class={ui.btnSecondary}>Duplicar assessment</button>
			</form>
		</div>

		<ol class="mt-6 space-y-4">
			{#each serverQuestions as q, i (q.id)}
				<li class={ui.card}>
					<p class="text-xs font-medium tracking-wide text-slate-500 uppercase">
						Pregunta {i + 1} · {QUESTION_TYPE_LABELS[q.type]}{q.required ? '' : ' · Opcional'}
					</p>
					<p class="mt-1 whitespace-pre-line">{q.prompt}</p>
					{#if q.type === 'opcion_unica'}
						<ul class="mt-3 space-y-1 text-sm">
							{#each q.options as o (o.id)}
								<li class={o.isCorrect ? 'font-medium text-emerald-800' : 'text-slate-700'}>
									{o.isCorrect ? '✓' : '○'}
									{o.text}
									{#if o.isCorrect}<span class="sr-only">(opción correcta)</span>{/if}
								</li>
							{/each}
						</ul>
					{/if}
				</li>
			{/each}
		</ol>
	{:else}
		<!-- Importación desde Word -->
		<WordImport
			readUrl="{base}/preguntas/leer-word"
			templateUrl="{base}/preguntas/plantilla-word"
			editorCount={questions.length}
			onApply={applyPreview}
		/>

		<!-- Importación desde Excel -->
		<details class="mt-4 {ui.card} group" open={!!result?.importErrors || !!result?.imported}>
			<summary class="cursor-pointer text-sm font-semibold">Importar preguntas desde Excel o CSV</summary>
			<div class="mt-4 space-y-4 text-sm">
				<p class="text-slate-600">
					Descargue la <a href="{base}/preguntas/plantilla" class="text-brand-600 underline" download
						>plantilla de Excel</a
					>, complétela y súbala. Si alguna fila tiene errores no se importa nada y se indica el detalle.
				</p>
				{#if dirty}
					<p class={ui.alertWarning}>
						Tiene cambios sin guardar en el editor: guárdelos antes de importar o se perderán.
					</p>
				{/if}
				<form
					method="POST"
					action="{base}/preguntas/importar"
					enctype="multipart/form-data"
					class="flex flex-wrap items-end gap-4"
					onsubmit={importExcel}
				>
					<div>
						<label for="archivo" class={ui.label}>Archivo (.xlsx o .csv, máx. 2 MB)</label>
						<input
							id="archivo"
							name="archivo"
							type="file"
							accept=".xlsx,.csv"
							required
							class="mt-1 block text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200"
						/>
					</div>
					<fieldset class="flex gap-4">
						<legend class="sr-only">Modo de importación</legend>
						<label class="flex items-center gap-1.5">
							<input type="radio" name="modo" value="agregar" checked class="text-brand-600" />
							Agregar al final
						</label>
						<label class="flex items-center gap-1.5">
							<input type="radio" name="modo" value="reemplazar" class="text-brand-600" />
							Reemplazar todas
						</label>
					</fieldset>
					<button class={ui.btnSecondary} disabled={importando}>Importar</button>
				</form>

				{#if result?.importErrors}
					<div class={ui.alertError} role="alert">
						<p class="font-medium">No se importó ninguna pregunta:</p>
						<ul class="mt-1 max-h-60 list-disc overflow-y-auto pl-5">
							{#each result.importErrors as e (e)}<li>{e}</li>{/each}
						</ul>
					</div>
				{/if}
				{#if result?.imported}
					<p class={ui.alertSuccess} role="status">
						Se importaron {result.imported} preguntas.
					</p>
				{/if}
			</div>
		</details>

		<!-- Editor -->
		<form method="POST" action="{base}/preguntas/guardar" class="mt-6" onsubmit={save}>
			<input type="hidden" name="payload" value={payloadJson} />

			<div class="flex flex-wrap items-center justify-between gap-3">
				<p class="text-sm text-slate-600" aria-live="polite">
					{questions.length}
					{questions.length === 1 ? 'pregunta' : 'preguntas'}: {counts.choice} de selección
					{#if questions.length}({Math.round((counts.choice / questions.length) * 100)}%){/if} · {counts.open}
					abiertas
				</p>
			</div>

			{#if result?.errors}
				<div class="mt-4 {ui.alertError}" role="alert">
					<p class="font-medium">Revise lo siguiente antes de guardar:</p>
					<ul class="mt-1 list-disc pl-5">
						{#each result.errors as e (e)}<li>{e}</li>{/each}
					</ul>
				</div>
			{/if}
			{#if result?.saved !== undefined && !dirty}
				<p class="mt-4 {ui.alertSuccess}" role="status">Preguntas guardadas.</p>
			{:else if loadedNotice && dirty}
				<p class="mt-4 {ui.alertWarning}" role="status">{loadedNotice}</p>
			{/if}

			{#if questions.length === 0}
				<p class="mt-6 {ui.card} text-center text-slate-600">
					Todavía no hay preguntas. Agregue una con los botones de abajo.
				</p>
			{/if}

			<ol class="mt-4 space-y-4">
				{#each questions as q, i (q.key)}
					{@const hint = reviewHint(q)}
					<li id="pregunta-{i + 1}" class="{ui.card} scroll-mt-4 {hint ? 'ring-2 ring-amber-300' : ''}">
						<div class="flex flex-wrap items-center justify-between gap-2">
							<p class="text-xs font-medium tracking-wide text-slate-500 uppercase">
								Pregunta {i + 1} · {QUESTION_TYPE_LABELS[q.type]}
							</p>
							<div class="flex items-center gap-1">
								<button
									type="button"
									class={ui.btnIcon}
									onclick={() => move(i, -1)}
									disabled={i === 0}
									aria-label="Subir pregunta {i + 1}">↑</button
								>
								<button
									type="button"
									class={ui.btnIcon}
									onclick={() => move(i, 1)}
									disabled={i === questions.length - 1}
									aria-label="Bajar pregunta {i + 1}">↓</button
								>
								<button
									type="button"
									class="{ui.btnIcon} hover:text-red-700"
									onclick={() => removeQuestion(i)}
									aria-label="Eliminar pregunta {i + 1}">✕</button
								>
							</div>
						</div>

						<label for="prompt-{q.key}" class="sr-only">Enunciado de la pregunta {i + 1}</label>
						<textarea
							id="prompt-{q.key}"
							bind:value={q.prompt}
							rows="2"
							maxlength="2000"
							placeholder="Escriba el enunciado…"
							class={ui.input}
						></textarea>

						<label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-700">
							<input type="checkbox" bind:checked={q.required} class="rounded text-brand-600" />
							Obligatoria
						</label>

						{#if q.type === 'opcion_unica'}
							<fieldset class="mt-3">
								<legend class="text-sm font-medium text-slate-700">
									Opciones <span class="font-normal text-slate-500">(marque la correcta)</span>
								</legend>
								<ul class="mt-2 space-y-2">
									{#each q.options as o, j (o.key)}
										<li class="flex items-center gap-2">
											<input
												type="radio"
												name="correcta-{q.key}"
												checked={o.isCorrect}
												onchange={() => setCorrect(q, o.key)}
												aria-label="Marcar opción {j + 1} como correcta"
												class="text-emerald-600 focus:ring-emerald-500"
											/>
											<label for="option-{o.key}" class="sr-only">Texto de la opción {j + 1}</label>
											<input
												id="option-{o.key}"
												bind:value={o.text}
												maxlength="500"
												placeholder="Opción {j + 1}"
												class="{ui.input} mt-0 {o.isCorrect ? 'border-emerald-400 bg-emerald-50' : ''}"
											/>
											<button
												type="button"
												class={ui.btnIcon}
												onclick={() => removeOption(q, o.key)}
												disabled={q.options.length <= MIN_OPTIONS}
												aria-label="Quitar opción {j + 1}">✕</button
											>
										</li>
									{/each}
								</ul>
								{#if q.options.length < MAX_OPTIONS}
									<button type="button" class="mt-2 text-sm text-brand-600 hover:underline" onclick={() => addOption(q)}>
										+ Agregar opción
									</button>
								{/if}
								{#if hint}
									<p class="mt-2 text-sm text-amber-800">⚠ {hint}</p>
								{/if}
							</fieldset>
						{:else}
							<p class="mt-2 text-xs text-slate-500">
								Respuesta de texto libre, hasta {context.assessment.openMaxChars.toLocaleString('es-CO')} caracteres.
							</p>
						{/if}
					</li>
				{/each}
			</ol>

			<div class="mt-4 flex flex-wrap gap-2">
				<button type="button" class={ui.btnSecondary} onclick={() => addQuestion('opcion_unica')}>
					+ Pregunta de selección
				</button>
				<button type="button" class={ui.btnSecondary} onclick={() => addQuestion('abierta')}>
					+ Pregunta abierta
				</button>
			</div>

			<!-- Barra de guardado fija -->
			<div
				class="sticky bottom-0 -mx-4 mt-6 flex items-center justify-end gap-3 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur"
			>
				{#if dirty}<span class="text-sm text-amber-700">Cambios sin guardar</span>{/if}
				<button class={ui.btnPrimary} disabled={guardando || !dirty}>
					{guardando ? 'Guardando…' : 'Guardar preguntas'}
				</button>
			</div>
		</form>
	{/if}
</AssessmentLayout>
