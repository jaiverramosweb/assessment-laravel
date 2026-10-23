<script lang="ts">
	import { router, useForm } from '@inertiajs/svelte';
	import AdminLayout from '@/Layouts/AdminLayout.svelte';
	import { formatDateTime } from '@/lib/format';
	import { ui } from '@/lib/ui';

	// Props from app/Http/Controllers/Admin/Settings/SettingsController.php (App\Services\Ai\AiSettings::forUi).
	type Provider = 'anthropic' | 'openai';
	type ModelOption = { id: string; name: string; created: number | null };
	type ProviderState = {
		configured: boolean;
		keySource: 'db' | 'env' | null;
		keyHint: string | null;
		model: string;
		models: ModelOption[];
		modelsFetchedAt: number | null;
	};

	let {
		ai,
		labels,
		suggestions
	}: {
		ai: { provider: Provider; providers: Record<Provider, ProviderState> };
		labels: Record<Provider, string>;
		suggestions: Record<Provider, ModelOption[]>;
	} = $props();

	const providers: Provider[] = ['anthropic', 'openai'];
	const OTHER = '__otro__';

	// The form starts from the saved values; after saving, the page props refresh the badges.
	// svelte-ignore state_referenced_locally
	const initial = ai;
	const form = useForm({
		provider: initial.provider,
		anthropicModel: initial.providers.anthropic.model,
		openaiModel: initial.providers.openai.model,
		anthropicApiKey: '',
		openaiApiKey: '',
		removeAnthropicKey: false,
		removeOpenaiKey: false
	});
	const errors = $derived($form.errors as Record<string, string | undefined>);

	const modelField = (p: Provider): 'anthropicModel' | 'openaiModel' => (p === 'anthropic' ? 'anthropicModel' : 'openaiModel');
	const keyField = (p: Provider): 'anthropicApiKey' | 'openaiApiKey' => (p === 'anthropic' ? 'anthropicApiKey' : 'openaiApiKey');
	const removeField = (p: Provider): 'removeAnthropicKey' | 'removeOpenaiKey' =>
		p === 'anthropic' ? 'removeAnthropicKey' : 'removeOpenaiKey';

	// Options of the model selector: the fetched list (or the suggestions), plus the current model.
	function options(p: Provider): ModelOption[] {
		const list = ai.providers[p].models.length ? ai.providers[p].models : suggestions[p];
		const current = $form[modelField(p)];
		return current && !list.some((m) => m.id === current) ? [{ id: current, name: current, created: null }, ...list] : list;
	}

	// "Escribir otro modelo": a free text field instead of the selector.
	let typing: Record<Provider, boolean> = $state({ anthropic: false, openai: false });

	function pickModel(p: Provider, value: string) {
		if (value === OTHER) {
			typing[p] = true;
			$form[modelField(p)] = '';
		} else {
			$form[modelField(p)] = value;
		}
	}

	function save(event: SubmitEvent) {
		event.preventDefault();
		$form.post('/admin/configuracion/ia', {
			preserveScroll: true,
			// API keys never stay in the fields.
			onFinish: () => $form.reset('anthropicApiKey', 'openaiApiKey', 'removeAnthropicKey', 'removeOpenaiKey')
		});
	}

	let refreshing: Provider | null = $state(null);

	function refresh(p: Provider) {
		router.post(
			`/admin/configuracion/ia/modelos/${p}`,
			{ apiKey: $form[keyField(p)] },
			{
				preserveScroll: true,
				preserveState: true,
				only: ['ai', 'flash', 'errors'],
				onStart: () => (refreshing = p),
				onFinish: () => (refreshing = null)
			}
		);
	}

	function keyStatus(s: ProviderState): string {
		if (s.keySource === 'db') return `Guardada (cifrada) · termina en ${s.keyHint}`;
		if (s.keySource === 'env') return `Tomada del archivo .env del servidor · termina en ${s.keyHint}`;
		return 'Sin API key';
	}
</script>

<svelte:head>
	<title>Configuración · Administración</title>
</svelte:head>

<AdminLayout>
	<div class="flex flex-col gap-8">
		<div class="flex flex-col gap-1.5">
			<p class={ui.eyebrow}>Panel de administración</p>
			<h1 class={ui.pageTitle}>Configuración</h1>
		</div>

		<form method="POST" onsubmit={save} class="{ui.card} flex max-w-3xl flex-col gap-6">
			<div class="flex flex-col gap-1">
				<h2 class="text-base font-semibold">Inteligencia artificial</h2>
				<p class="text-sm text-slate-600">
					Proveedor y modelo del análisis de respuestas abiertas en Reportes. Las respuestas se envían
					anonimizadas. Las API keys se guardan cifradas y nunca se vuelven a mostrar.
				</p>
			</div>

			<fieldset class="flex flex-col gap-2">
				<legend class={ui.label}>Proveedor que se usa</legend>
				{#each providers as p (p)}
					<label class="flex items-center gap-2 text-sm">
						<input type="radio" name="provider" value={p} bind:group={$form.provider} />
						<span class="font-medium">{labels[p]}</span>
						{#if ai.providers[p].configured}
							<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200"
								>Listo</span
							>
						{:else}
							<span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-slate-300"
								>Sin configurar</span
							>
						{/if}
					</label>
				{/each}
				{#if errors.provider}<p class={ui.fieldError}>{errors.provider}</p>{/if}
			</fieldset>

			{#each providers as p (p)}
				{@const s = ai.providers[p]}
				<section
					class="flex flex-col gap-4 rounded-lg p-4 ring-1 {$form.provider === p
						? 'bg-brand-50/40 ring-brand-300'
						: 'bg-slate-50 ring-slate-200'}"
					aria-labelledby="titulo-{p}"
				>
					<h3 id="titulo-{p}" class="text-sm font-semibold">
						{labels[p]}
						{#if $form.provider === p}<span class="ml-1 text-xs font-normal text-brand-700">(en uso)</span>{/if}
					</h3>

					<div class="flex flex-col gap-1">
						<label for="key-{p}" class={ui.label}>API key</label>
						<p class="text-xs text-slate-600">{keyStatus(s)}</p>
						<input
							id="key-{p}"
							type="password"
							autocomplete="off"
							spellcheck="false"
							placeholder={s.keySource ? 'Escriba una nueva solo para reemplazarla' : 'Pegue aquí la API key'}
							bind:value={$form[keyField(p)]}
							aria-invalid={!!errors[keyField(p)]}
							class={ui.input}
						/>
						{#if errors[keyField(p)]}<p class={ui.fieldError}>{errors[keyField(p)]}</p>{/if}
						{#if s.keySource === 'db'}
							<label class="mt-1 flex items-center gap-2 text-sm text-slate-700">
								<input type="checkbox" bind:checked={$form[removeField(p)]} />
								Eliminar la API key guardada
							</label>
						{/if}
					</div>

					<div class="flex flex-col gap-1">
						<label for="model-{p}" class={ui.label}>Modelo</label>
						{#if typing[p]}
							<input
								id="model-{p}"
								maxlength="100"
								placeholder="Id del modelo, por ejemplo el que aparece en la consola del proveedor"
								bind:value={$form[modelField(p)]}
								aria-invalid={!!errors[modelField(p)]}
								class={ui.input}
							/>
							<button type="button" class="self-start text-sm text-brand-600 hover:underline" onclick={() => (typing[p] = false)}
								>Elegir de la lista</button
							>
						{:else}
							<select
								id="model-{p}"
								value={$form[modelField(p)]}
								onchange={(e) => pickModel(p, e.currentTarget.value)}
								aria-invalid={!!errors[modelField(p)]}
								class={ui.input}
							>
								<option value="" disabled>Elija un modelo</option>
								{#each options(p) as m (m.id)}
									<option value={m.id}>{m.name === m.id ? m.id : `${m.name} (${m.id})`}</option>
								{/each}
								<option value={OTHER}>Escribir otro modelo…</option>
							</select>
						{/if}
						{#if errors[modelField(p)]}<p class={ui.fieldError}>{errors[modelField(p)]}</p>{/if}
						<div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-600">
							<button
								type="button"
								class={ui.btnSecondary}
								disabled={refreshing !== null}
								onclick={() => refresh(p)}
							>
								{refreshing === p ? 'Consultando…' : 'Actualizar lista de modelos'}
							</button>
							{#if s.modelsFetchedAt}
								<span>Lista consultada a {labels[p]} el {formatDateTime(s.modelsFetchedAt)} ({s.models.length} modelos).</span>
							{:else if suggestions[p].length}
								<span>Mostrando modelos sugeridos. Actualice la lista para ver los disponibles en su cuenta.</span>
							{:else}
								<span>Actualice la lista para ver los modelos disponibles en su cuenta.</span>
							{/if}
						</div>
					</div>
				</section>
			{/each}

			<div>
				<button class={ui.btnPrimary} disabled={$form.processing}>Guardar configuración</button>
			</div>
		</form>
	</div>
</AdminLayout>
