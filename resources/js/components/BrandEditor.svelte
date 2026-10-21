<script lang="ts">
	// Logo y colores de un cliente, con vista previa de la pantalla de ingreso del participante.
	import { page, router, useForm } from '@inertiajs/svelte';
	import { ui } from '@/lib/ui';
	import ClientLogo from '@/components/ClientLogo.svelte';
	import type { SharedProps } from '@/types';
	import {
		DEFAULT_ACCENT,
		DEFAULT_PRIMARY,
		MIN_CONTRAST,
		contrast,
		isHexColor,
		readableWithWhite,
		themeStyle
	} from '@/lib/theme';

	let {
		action,
		logoUrl,
		themePrimary,
		themeAccent,
		previewTitle
	}: {
		/** Client URL: the logo and colors are saved at `${action}/logo` and `${action}/colores`. */
		action: string;
		logoUrl: string | null;
		themePrimary: string | null;
		themeAccent: string | null;
		previewTitle: string;
	} = $props();

	// Results: success messages arrive as flash, errors under the keys logo, primary and accent.
	const shared = $derived($page.props as unknown as SharedProps);
	const success = $derived(shared.flash.success);
	const errors = $derived(shared.errors ?? {});
	const keep = { preserveState: true, preserveScroll: true } as const;

	const logoForm = useForm<{ logo: File | null }>({ logo: null });
	let logoInput: HTMLInputElement | undefined = $state();

	function uploadLogo(event: SubmitEvent) {
		event.preventDefault();
		$logoForm.post(`${action}/logo`, {
			...keep,
			forceFormData: true,
			onSuccess: () => {
				$logoForm.reset();
				if (logoInput) logoInput.value = '';
			}
		});
	}

	function removeLogo(event: SubmitEvent) {
		event.preventDefault();
		router.delete(`${action}/logo`, keep);
	}

	// svelte-ignore state_referenced_locally
	let primary = $state(themePrimary ?? DEFAULT_PRIMARY);
	// svelte-ignore state_referenced_locally
	let accent = $state(themeAccent ?? DEFAULT_ACCENT);
	let savingTheme = $state(false);

	function saveColors(event: SubmitEvent) {
		event.preventDefault();
		router.post(
			`${action}/colores`,
			{ primary, accent },
			{ ...keep, onStart: () => (savingTheme = true), onFinish: () => (savingTheme = false) }
		);
	}

	function platformColors() {
		primary = DEFAULT_PRIMARY;
		accent = DEFAULT_ACCENT;
		router.delete(`${action}/colores`, keep);
	}
	const customTheme = $derived(!!(themePrimary || themeAccent));
	const previewStyle = $derived(
		themeStyle({
			primary: isHexColor(primary) ? primary : undefined,
			accent: isHexColor(accent) ? accent : undefined
		})
	);
	const ratio = (n: number) => n.toLocaleString('es-CO', { maximumFractionDigits: 1 });
	/** Advertencia (no bloquea) cuando el texto blanco no se leería bien sobre el color. */
	function lightWarning(hex: string): string | null {
		if (!isHexColor(hex) || readableWithWhite(hex)) return null;
		return `Tono claro: el texto blanco encima podría no leerse bien (contraste ${ratio(contrast(hex, '#ffffff'))}:1; se recomienda al menos ${ratio(MIN_CONTRAST)}:1). Revise la vista previa; puede guardarlo igual.`;
	}
	const primaryWarning = $derived(lightWarning(primary));
	const accentWarning = $derived(lightWarning(accent));
</script>

<!-- Logo -->
<section class={ui.card} aria-labelledby="logo-titulo">
	<h2 id="logo-titulo" class={ui.sectionTitle}>Logo</h2>
	<p class="mt-1 text-sm text-slate-600">
		Se muestra a los participantes al ingresar y al responder, y en la portada de los informes PDF.
	</p>
	<div class="mt-4 flex flex-wrap items-center gap-5">
		<div
			class="flex h-24 w-40 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-canvas p-3"
		>
			{#if logoUrl}
				<img src={logoUrl} alt="Logo actual" class="max-h-full max-w-full object-contain" />
			{:else}
				<span class="text-xs text-slate-600">Sin logo</span>
			{/if}
		</div>
		<form
			method="POST"
			enctype="multipart/form-data"
			class="flex flex-wrap items-end gap-3"
			onsubmit={uploadLogo}
		>
			<div>
				<label for="logo" class={ui.label}>Imagen (PNG, JPG o WebP · máx. 2 MB)</label>
				<input
					id="logo"
					name="logo"
					type="file"
					accept="image/png,image/jpeg,image/webp"
					required
					bind:this={logoInput}
					onchange={(e) => ($logoForm.logo = e.currentTarget.files?.[0] ?? null)}
					class="mt-1 block text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold hover:file:bg-slate-200"
				/>
			</div>
			<button class={ui.btnSecondary} disabled={$logoForm.processing}
				>{logoUrl ? 'Reemplazar logo' : 'Subir logo'}</button
			>
		</form>
		{#if logoUrl}
			<form method="POST" onsubmit={removeLogo}>
				<button class={ui.btnDanger}>Quitar logo</button>
			</form>
		{/if}
	</div>
	{#if errors.logo}<p class="mt-3 {ui.alertError}" role="alert">{errors.logo}</p>{/if}
	{#if success === 'Logo actualizado.'}<p class="mt-3 {ui.alertSuccess}" role="status">Logo actualizado.</p>{/if}
	{#if success === 'Logo eliminado.'}<p class="mt-3 {ui.alertSuccess}" role="status">Logo eliminado.</p>{/if}
</section>

<!-- Colores -->
<section class={ui.card} aria-labelledby="colores-titulo">
	<h2 id="colores-titulo" class={ui.sectionTitle}>Colores</h2>
	<p class="mt-1 text-sm text-slate-600">
		Reemplazan los colores de la plataforma en las pantallas del participante y en los informes PDF.
		{customTheme ? '' : 'Ahora se usan los colores de la plataforma.'}
	</p>

	<div class="mt-5 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
		<form method="POST" class="flex flex-col gap-5" onsubmit={saveColors}>
			{#each [{ id: 'primary', label: 'Color principal', hint: 'Franja superior e instrucciones (reemplaza el azul oscuro).', ok: isHexColor(primary), warning: primaryWarning }, { id: 'accent', label: 'Color de acento', hint: 'Botones, barra de progreso y opción seleccionada.', ok: isHexColor(accent), warning: accentWarning }] as field (field.id)}
				<div>
					<label for="{field.id}-hex" class={ui.label}>{field.label}</label>
					<div class="mt-1 flex items-center gap-3">
						{#if field.id === 'primary'}
							<input
								type="color"
								bind:value={primary}
								aria-label="Elegir {field.label.toLowerCase()}"
								class="h-11 w-14 cursor-pointer rounded-lg border border-slate-300 bg-white p-1"
							/>
							<input
								id="{field.id}-hex"
								name="primary"
								bind:value={primary}
								maxlength="7"
								spellcheck="false"
								aria-invalid={!field.ok}
								class="{ui.input} mt-0 w-32 font-mono uppercase"
							/>
						{:else}
							<input
								type="color"
								bind:value={accent}
								aria-label="Elegir {field.label.toLowerCase()}"
								class="h-11 w-14 cursor-pointer rounded-lg border border-slate-300 bg-white p-1"
							/>
							<input
								id="{field.id}-hex"
								name="accent"
								bind:value={accent}
								maxlength="7"
								spellcheck="false"
								aria-invalid={!field.ok}
								class="{ui.input} mt-0 w-32 font-mono uppercase"
							/>
						{/if}
					</div>
					<p class={ui.hint}>{field.hint}</p>
					{#if field.warning}
						<p
							class="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
						>
							⚠ {field.warning}
						</p>
					{/if}
					{#if errors[field.id]}
						<p class={ui.fieldError} role="alert">{errors[field.id]}</p>
					{/if}
				</div>
			{/each}

			<div class="flex flex-wrap gap-2">
				<button class={ui.btnPrimary} disabled={savingTheme}>Guardar colores</button>
				{#if customTheme}
					<button type="button" class={ui.btnSecondary} onclick={platformColors}
						>Usar colores de la plataforma</button
					>
				{/if}
			</div>
			{#if success === 'Colores guardados.'}
				<p class={ui.alertSuccess} role="status">
					Colores guardados.{primaryWarning || accentWarning
						? ' Quedó un tono claro: revise que el texto se lea bien en la vista previa.'
						: ''}
				</p>
			{/if}
			{#if success === 'Se restablecieron los colores de la plataforma.'}
				<p class={ui.alertSuccess} role="status">Se restablecieron los colores de la plataforma.</p>
			{/if}
		</form>

		<!-- Vista previa: así verá el participante la pantalla de ingreso -->
		<figure class="m-0">
			<figcaption class="mb-2 text-xs font-semibold tracking-wider text-slate-600 uppercase">
				Vista previa
			</figcaption>
			<div
				class="overflow-hidden rounded-2xl border border-slate-200 bg-canvas"
				style={previewStyle}
			>
				<div class="flex flex-col gap-3 bg-ink-950 px-5 pt-5 pb-10 text-white">
					<ClientLogo src={logoUrl} size="sm" />
					<p class="font-display text-lg leading-tight font-extrabold">{previewTitle}</p>
					<p class="text-xs text-ink-300">Disponible hasta la fecha de cierre</p>
				</div>
				<div class="-mt-6 px-4 pb-5">
					<div class="flex flex-col gap-3 rounded-xl bg-white p-4 shadow">
						<div class="h-8 rounded-md border border-slate-300"></div>
						<div class="rounded-lg bg-brand-600 py-2 text-center text-sm font-semibold text-white">
							Ingresar
						</div>
						<div class="h-1.5 rounded-full bg-slate-100">
							<div class="h-1.5 w-2/3 rounded-full bg-brand-600"></div>
						</div>
						<div
							class="rounded-lg border border-brand-600 bg-brand-50 px-3 py-2 text-xs font-semibold text-ink-950"
						>
							Opción seleccionada
						</div>
					</div>
				</div>
			</div>
		</figure>
	</div>
</section>
