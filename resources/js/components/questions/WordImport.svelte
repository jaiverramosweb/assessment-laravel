<script lang="ts" module>
	import type { QuestionType } from '@/lib/constants';

	export type WordPreview = {
		fileName: string;
		questions: {
			type: QuestionType;
			prompt: string;
			required: boolean;
			options: { text: string; isCorrect: boolean }[];
		}[];
		warnings: string[];
	};
</script>

<script lang="ts">
	import { QUESTION_TYPE_LABELS } from '@/lib/constants';
	import { ui } from '@/lib/ui';
	import { postJson } from './http';

	// Import from Word: preview → loaded in the editor without saving.
	let {
		readUrl,
		templateUrl,
		editorCount,
		onApply
	}: {
		readUrl: string;
		templateUrl: string;
		/** Questions currently in the editor (changes the button labels). */
		editorCount: number;
		/** Loads the preview in the editor; resolves false when the admin cancelled. */
		onApply: (preview: WordPreview, mode: 'agregar' | 'reemplazar') => Promise<boolean>;
	} = $props();

	let preview = $state<WordPreview | null>(null);
	let wordErrors = $state<string[]>([]);
	let leyendo = $state(false);

	const previewCounts = $derived({
		choice: preview?.questions.filter((q) => q.type === 'opcion_unica').length ?? 0,
		open: preview?.questions.filter((q) => q.type === 'abierta').length ?? 0
	});

	async function read(event: SubmitEvent) {
		event.preventDefault();
		leyendo = true;
		wordErrors = [];
		preview = null;
		try {
			const res = await postJson<{ word?: WordPreview; wordErrors?: string[] }>(
				readUrl,
				new FormData(event.currentTarget as HTMLFormElement)
			);
			if (res.ok) preview = res.data.word ?? null;
			else wordErrors = res.data?.wordErrors ?? ['No se pudo leer el documento.'];
		} catch {
			wordErrors = ['No se pudo leer el documento. Intente de nuevo.'];
		} finally {
			leyendo = false;
		}
	}

	async function apply(mode: 'agregar' | 'reemplazar') {
		if (preview && (await onApply(preview, mode))) preview = null;
	}
</script>

<section class={ui.card} aria-labelledby="word-titulo">
	<div class="flex flex-wrap items-start justify-between gap-3">
		<div>
			<h2 id="word-titulo" class="text-sm font-semibold">Importar preguntas desde Word</h2>
			<p class="mt-1 max-w-2xl text-sm text-slate-600">
				Numere cada pregunta (1., 2., …), ponga sus opciones debajo con letras (a), b), …) y
				<u>subraye</u> o <mark class="bg-yellow-200 px-0.5">resalte</mark> la respuesta correcta. Una pregunta
				sin opciones queda como abierta; escriba <em>(opcional)</em> al final si no es obligatoria.
			</p>
		</div>
		<a href={templateUrl} class="{ui.btnSecondary} shrink-0" download> Descargar plantilla Word </a>
	</div>

	<form
		method="POST"
		action={readUrl}
		enctype="multipart/form-data"
		class="mt-4 flex flex-wrap items-end gap-4"
		onsubmit={read}
	>
		<div>
			<label for="documento" class={ui.label}>Documento (.docx, máx. 10 MB)</label>
			<input
				id="documento"
				name="documento"
				type="file"
				accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
				required
				class="mt-1 block text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-slate-200"
			/>
		</div>
		<button class={ui.btnPrimary} disabled={leyendo}>
			{leyendo ? 'Leyendo…' : 'Leer documento'}
		</button>
	</form>

	{#if wordErrors.length}
		<div class="mt-4 {ui.alertError}" role="alert">
			{#each wordErrors as e (e)}<p>{e}</p>{/each}
		</div>
	{/if}

	{#if preview}
		<div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4" aria-live="polite">
			<p class="text-sm">
				<strong>Vista previa:</strong> se leyeron {preview.questions.length} preguntas de
				<span class="font-medium">{preview.fileName}</span> ({previewCounts.choice} de selección · {previewCounts.open}
				abiertas). Todavía no se ha guardado nada.
			</p>

			{#if preview.warnings.length}
				<div class="mt-3 {ui.alertWarning}">
					<p class="font-medium">
						Revise {preview.warnings.length === 1 ? 'esto' : 'estos puntos'}:
					</p>
					<ul class="mt-1 list-disc pl-5">
						{#each preview.warnings as w (w)}<li>{w}</li>{/each}
					</ul>
				</div>
			{/if}

			<ol class="mt-3 max-h-96 space-y-2 overflow-y-auto pr-1">
				{#each preview.questions as q, i (i)}
					<li class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
						<p class="font-medium">
							<span class="text-slate-500">{i + 1}.</span>
							<span class="whitespace-pre-line">{q.prompt}</span>
							<span class="ml-1 text-xs font-normal text-slate-500"
								>{QUESTION_TYPE_LABELS[q.type]}{q.required ? '' : ' · Opcional'}</span
							>
						</p>
						{#if q.type === 'opcion_unica'}
							<ul class="mt-1 space-y-0.5 pl-4">
								{#each q.options as o, j (j)}
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

			<div class="mt-4 flex flex-wrap gap-2">
				<button type="button" class={ui.btnPrimary} onclick={() => apply('agregar')}>
					{editorCount ? 'Agregar al final del editor' : 'Cargar en el editor'}
				</button>
				{#if editorCount}
					<button type="button" class={ui.btnSecondary} onclick={() => apply('reemplazar')}>
						Reemplazar las del editor
					</button>
				{/if}
				<button type="button" class={ui.btnSecondary} onclick={() => (preview = null)}> Descartar </button>
			</div>
		</div>
	{/if}
</section>
