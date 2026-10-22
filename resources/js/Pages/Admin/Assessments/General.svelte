<script lang="ts">
	import { Link, page, router, useForm } from '@inertiajs/svelte';
	import AssessmentLayout, { type AssessmentContext } from '@/Layouts/AssessmentLayout.svelte';
	import AssessmentForm, { type AssessmentFormValues } from '@/components/AssessmentForm.svelte';
	import StatusBadge from '@/components/StatusBadge.svelte';
	import { postJson } from '@/components/questions/http';
	import { STATUS_LABELS, type AssessmentStatus } from '@/lib/constants';
	import { DEFAULT_ACCENT, DEFAULT_PRIMARY } from '@/lib/theme';
	import { ui } from '@/lib/ui';

	type Result = {
		saved?: boolean;
		passwordSaved?: boolean;
		statusChanged?: AssessmentStatus;
		publishErrors?: string[];
	} | null;

	let {
		context,
		values,
		timezones,
		clients,
		publishProblems,
		deletion,
		result
	}: {
		context: AssessmentContext;
		values: AssessmentFormValues;
		timezones: string[];
		clients: { id: string; name: string }[];
		publishProblems: string[];
		deletion: { questions: number; participants: number; submitted: number };
		result: Result;
	} = $props();

	const a = $derived(context.assessment);
	const base = $derived(`/admin/assessments/${context.assessment.id}`);
	const search = $derived(new URL($page.url, 'http://x').searchParams);
	const pageErrors = $derived(($page.props.errors ?? {}) as Record<string, string>);

	let copiado = $state(false);
	let copiadoClave = $state(false);

	// Current password: only shown after asking the server (it is audited).
	let revealed = $state<string | null>(null);
	let revealError = $state('');
	let revealing = $state(false);

	// New password: what is typed can be shown, or a random one generated.
	let verNueva = $state(false);
	const pw = useForm({ password: '', confirm: '' });
	const del = useForm({ confirmacion: '' });

	/** Readable random password (without characters that look alike: 0/O, 1/l/I). */
	function generar() {
		const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
		const bytes = crypto.getRandomValues(new Uint32Array(12));
		const raw = Array.from(bytes, (b) => chars[b % chars.length]).join('');
		const value = `${raw.slice(0, 4)}-${raw.slice(4, 8)}-${raw.slice(8, 12)}`;
		$pw.password = value;
		$pw.confirm = value;
		verNueva = true;
	}

	async function copiar(text: string, which: 'link' | 'clave') {
		try {
			await navigator.clipboard.writeText(text);
			if (which === 'link') copiado = true;
			else copiadoClave = true;
			setTimeout(() => (which === 'link' ? (copiado = false) : (copiadoClave = false)), 2000);
		} catch {
			// No clipboard permission: the text is still visible to copy by hand.
		}
	}

	async function mostrar() {
		revealing = true;
		revealError = '';
		const res = await postJson<{ password?: string; error?: string }>(`${base}/ver-password`);
		revealing = false;
		if (res.ok && res.data.password) revealed = res.data.password;
		else revealError = res.data?.error ?? 'No se pudo consultar la contraseña. Intente de nuevo.';
	}

	function post(action: string, question?: string) {
		if (question && !confirm(question)) return;
		// Duplicate and next measurement open another assessment: its forms must start fresh.
		const opensAnother = action === 'duplicar' || action === 'medicion';
		router.post(`${base}/${action}`, {}, { preserveScroll: !opensAnother, preserveState: !opensAnother ? true : 'errors' });
	}

	function savePassword(event: SubmitEvent) {
		event.preventDefault();
		$pw.post(`${base}/password`, { preserveScroll: true, onSuccess: () => $pw.reset() });
	}

	function deleteAssessment(event: SubmitEvent) {
		event.preventDefault();
		$del.post(`${base}/eliminar`, {
			onBefore: () => confirm(`¿Eliminar definitivamente "${a.title}"? Esta acción no se puede deshacer.`)
		});
	}
</script>

<svelte:head>
	<title>{context.assessment.title} · Administración</title>
</svelte:head>

<AssessmentLayout {context}>
	<div class="space-y-6">
		{#if search.get('duplicado') === '1'}
			<p class={ui.alertSuccess} role="status">
				Se creó la copia en borrador. Defina una contraseña de acceso antes de publicarla.
			</p>
		{/if}
		{#if search.get('medicion') === '1'}
			<p class={ui.alertSuccess} role="status">
				Se creó la nueva medición con las mismas preguntas y participantes. Revise las fechas, defina la
				contraseña de acceso, agregue personas nuevas si hace falta y publíquela.
			</p>
		{/if}
		{#if result?.statusChanged}
			<p class={ui.alertSuccess} role="status">
				Estado actualizado: {STATUS_LABELS[result.statusChanged]}.
			</p>
		{/if}

		<!-- Estado y publicación -->
		<section class={ui.card} aria-labelledby="estado-titulo">
			<h2 id="estado-titulo" class="text-base font-semibold">Estado y enlace</h2>

			<div class="mt-3">
				<span class={ui.label}>Enlace para participantes</span>
				<div class="mt-1 flex flex-wrap items-center gap-2">
					<code class="rounded bg-slate-100 px-2 py-1 text-sm break-all">{context.publicLink}</code>
					<button type="button" class={ui.btnSecondary} onclick={() => copiar(context.publicLink, 'link')}>
						{copiado ? 'Copiado' : 'Copiar enlace'}
					</button>
				</div>
				<p class={ui.hint}>
					Los participantes necesitan además la contraseña de acceso. Solo podrán ingresar si el
					assessment está publicado y dentro de las fechas.
				</p>
			</div>

			{#if result?.publishErrors}
				<div class="mt-4 {ui.alertError}" role="alert">
					<p class="font-medium">No se puede publicar todavía:</p>
					<ul class="mt-1 list-disc pl-5">
						{#each result.publishErrors as p (p)}<li>{p}</li>{/each}
					</ul>
				</div>
			{:else if a.status === 'borrador' && publishProblems.length}
				<div class="mt-4 {ui.alertWarning}">
					<p class="font-medium">Pendiente para publicar:</p>
					<ul class="mt-1 list-disc pl-5">
						{#each publishProblems as p (p)}<li>{p}</li>{/each}
					</ul>
				</div>
			{/if}

			<div class="mt-4 flex flex-wrap gap-2">
				{#if a.status !== 'publicado'}
					<button type="button" class={ui.btnPrimary} onclick={() => post('publicar')}>Publicar</button>
				{/if}
				{#if a.status !== 'borrador'}
					<button type="button" class={ui.btnSecondary} onclick={() => post('borrador')}>
						{a.status === 'archivado' ? 'Restaurar como borrador' : 'Volver a borrador'}
					</button>
				{/if}
				<button type="button" class={ui.btnSecondary} onclick={() => post('duplicar')}>Duplicar</button>
				{#if a.status !== 'archivado'}
					<button
						type="button"
						class={ui.btnDanger}
						onclick={() => post('archivar', '¿Archivar este assessment? Los participantes ya no podrán ingresar.')}
						>Archivar</button
					>
				{/if}
			</div>
		</section>

		<!-- Mediciones: la misma encuesta antes y después (o en varios momentos) -->
		<section class={ui.card} aria-labelledby="mediciones-titulo">
			<h2 id="mediciones-titulo" class={ui.sectionTitle}>Mediciones</h2>
			{#if context.series}
				<p class="mt-1 text-sm text-slate-600">
					Este assessment es la medición {context.series.find((m) => m.id === a.id)?.order} de una serie. Todas
					comparten las mismas preguntas para poder compararlas.
				</p>
				<ol class="mt-3 divide-y divide-slate-100">
					{#each context.series as m (m.id)}
						<li class="flex flex-wrap items-center gap-3 py-2 text-sm">
							<span class="w-24 shrink-0 text-slate-600">Medición {m.order}</span>
							{#if m.id === a.id}
								<span class="min-w-0 flex-1 font-semibold">{m.title} (esta)</span>
							{:else}
								<Link href="/admin/assessments/{m.id}" class="min-w-0 flex-1 font-medium hover:underline"
									>{m.title}</Link
								>
							{/if}
							<StatusBadge status={m.status} />
						</li>
					{/each}
				</ol>
			{:else}
				<p class="mt-1 text-sm text-slate-600">
					¿Va a aplicar esta misma encuesta después de una formación, o en varios momentos? Cree la siguiente
					medición y luego compare los resultados.
				</p>
			{/if}
			<div class="mt-4 flex flex-wrap gap-2">
				<button
					type="button"
					class={context.series ? ui.btnSecondary : ui.btnPrimary}
					onclick={() =>
						post(
							'medicion',
							'Se creará la siguiente medición con las mismas preguntas y participantes. Desde ese momento las preguntas de todas las mediciones quedan bloqueadas para poder compararlas. ¿Continuar?'
						)}>Crear siguiente medición</button
				>
				{#if context.series}
					<Link href="{base}/mediciones" class={ui.btnPrimary}>Ver comparativo</Link>
				{/if}
			</div>
			{#if pageErrors.medicion}<p class="mt-2 {ui.fieldError}" role="alert">
					{pageErrors.medicion}
				</p>{/if}
		</section>

		<!-- Contraseña de acceso -->
		<section class={ui.card} aria-labelledby="password-titulo">
			<h2 id="password-titulo" class={ui.sectionTitle}>Contraseña de acceso</h2>

			{#if !a.hasPassword}
				<p class="mt-1 text-sm text-slate-600">
					<span class="font-semibold text-amber-800">Sin definir.</span> Es obligatoria para publicar.
				</p>
			{:else}
				<div class="mt-4 flex flex-wrap items-center gap-3 rounded-xl bg-canvas px-4 py-3">
					<span class="text-sm text-slate-600">Contraseña vigente:</span>
					{#if revealed}
						<code
							class="rounded-md bg-white px-2.5 py-1 font-mono text-base font-semibold tracking-wide text-ink-950 ring-1 ring-slate-200"
							aria-label="Contraseña vigente">{revealed}</code
						>
						<button type="button" class={ui.btnSecondary} onclick={() => copiar(revealed!, 'clave')}>
							{copiadoClave ? 'Copiada' : 'Copiar'}
						</button>
						<button type="button" class={ui.btnSecondary} onclick={() => (revealed = null)}>Ocultar</button>
					{:else}
						<span class="font-mono text-base tracking-widest text-ink-950" aria-hidden="true">••••••••••</span>
						{#if a.passwordViewable}
							<button type="button" class={ui.btnSecondary} onclick={mostrar} disabled={revealing}>
								<svg
									width="16"
									height="16"
									viewBox="0 0 24 24"
									fill="none"
									stroke="currentColor"
									stroke-width="2"
									stroke-linecap="round"
									stroke-linejoin="round"
									aria-hidden="true"
									><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"
									></circle></svg
								>
								Mostrar
							</button>
						{/if}
					{/if}
				</div>
				<p class={ui.hint}>
					{#if a.passwordViewable}
						Cada vez que se muestra queda registrado en la auditoría. Si la cambia, las personas que ya
						están respondiendo no se ven afectadas.
					{:else}
						Esta contraseña se definió antes de poder consultarse. Defínala de nuevo para poder verla.
					{/if}
				</p>
				{#if revealError}<p class="mt-2 {ui.alertWarning}">{revealError}</p>{/if}
			{/if}

			{#if result?.passwordSaved}
				<p class="mt-4 {ui.alertSuccess}" role="status">Contraseña de acceso actualizada.</p>
			{/if}

			<form
				method="POST"
				action="{base}/password"
				class="mt-5 grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
				onsubmit={savePassword}
			>
				<div>
					<label for="password" class={ui.label}>{a.hasPassword ? 'Nueva contraseña' : 'Contraseña'}</label>
					<div class="relative">
						<input
							id="password"
							name="password"
							type={verNueva ? 'text' : 'password'}
							autocomplete="new-password"
							minlength="12"
							required
							bind:value={$pw.password}
							class="{ui.input} pr-11"
						/>
						<button
							type="button"
							class="absolute top-1/2 right-1.5 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100"
							aria-label={verNueva ? 'Ocultar lo escrito' : 'Ver lo escrito'}
							aria-pressed={verNueva}
							onclick={() => (verNueva = !verNueva)}
						>
							{#if verNueva}
								<svg
									width="18"
									height="18"
									viewBox="0 0 24 24"
									fill="none"
									stroke="currentColor"
									stroke-width="2"
									stroke-linecap="round"
									stroke-linejoin="round"
									aria-hidden="true"
									><path d="M3 3l18 18"></path><path
										d="M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7a9.8 9.8 0 0 0 5.4-1.6"
									></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg
								>
							{:else}
								<svg
									width="18"
									height="18"
									viewBox="0 0 24 24"
									fill="none"
									stroke="currentColor"
									stroke-width="2"
									stroke-linecap="round"
									stroke-linejoin="round"
									aria-hidden="true"
									><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path><circle cx="12" cy="12" r="3"
									></circle></svg
								>
							{/if}
						</button>
					</div>
				</div>
				<div>
					<label for="confirm" class={ui.label}>Repetir contraseña</label>
					<input
						id="confirm"
						name="confirm"
						type={verNueva ? 'text' : 'password'}
						autocomplete="new-password"
						required
						bind:value={$pw.confirm}
						class={ui.input}
					/>
				</div>
				<button class={ui.btnPrimary} disabled={$pw.processing}>{a.hasPassword ? 'Cambiar' : 'Guardar'}</button>
			</form>
			<button type="button" class="mt-3 text-sm font-semibold text-brand-700 hover:underline" onclick={generar}>
				Generar una contraseña segura
			</button>
			{#if $pw.errors.password || $pw.errors.confirm}
				<p class={ui.fieldError} role="alert">
					{$pw.errors.password ?? $pw.errors.confirm}
				</p>
			{/if}
		</section>

		<!-- Línea gráfica: viene del cliente -->
		<section class={ui.card} aria-labelledby="marca-titulo">
			<h2 id="marca-titulo" class={ui.sectionTitle}>Cliente y línea gráfica</h2>
			{#if a.client}
				<div class="mt-4 flex flex-wrap items-center gap-5">
					<div class="flex h-20 w-36 items-center justify-center rounded-xl border border-slate-200 bg-canvas p-3">
						{#if a.logoUrl}
							<img src={a.logoUrl} alt="Logo del cliente" class="max-h-full max-w-full object-contain" />
						{:else}
							<span class="text-xs text-slate-600">Sin logo</span>
						{/if}
					</div>
					<div class="flex flex-col gap-2">
						<p class="font-semibold">{a.client.name}</p>
						<div class="flex items-center gap-2 text-xs text-slate-600">
							<span
								class="h-5 w-5 rounded-md ring-1 ring-slate-300"
								style="background: {a.client.themePrimary ?? DEFAULT_PRIMARY}"
								aria-hidden="true"
							></span>
							<span
								class="h-5 w-5 rounded-md ring-1 ring-slate-300"
								style="background: {a.client.themeAccent ?? DEFAULT_ACCENT}"
								aria-hidden="true"
							></span>
							{a.client.themePrimary || a.client.themeAccent ? 'Colores del cliente' : 'Colores de la plataforma'}
						</div>
					</div>
					<Link href="/admin/clientes/{a.client.id}" class="{ui.btnSecondary} sm:ml-auto"
						>Editar logo y colores del cliente</Link
					>
				</div>
				<p class="mt-3 text-sm text-slate-600">
					Los cambios en el cliente se aplican a todos sus assessments. Para cambiar de cliente, use
					<em>Datos generales</em>.
				</p>
			{:else}
				<p class="mt-2 text-sm text-slate-600">
					Este assessment no tiene cliente: usa los colores de la plataforma y no muestra logo. Elija un
					cliente en <em>Datos generales</em> o
					<Link href="/admin/clientes" class="text-brand-700 underline">cree uno</Link>.
				</p>
			{/if}
		</section>

		<!-- Datos generales -->
		<section class={ui.card} aria-labelledby="datos-titulo">
			<h2 id="datos-titulo" class="mb-4 text-base font-semibold">Datos generales</h2>
			{#if result?.saved}
				<p class="mb-4 {ui.alertSuccess}" role="status">Cambios guardados.</p>
			{/if}
			<AssessmentForm action="{base}/guardar" {values} {timezones} {clients} submitLabel="Guardar cambios" />
		</section>

		<!-- Eliminación definitiva (pensada para assessments de prueba) -->
		<section class="rounded-2xl border border-red-200 bg-white p-6" aria-labelledby="eliminar-titulo">
			<h2 id="eliminar-titulo" class="text-base font-semibold text-red-800">Eliminar assessment</h2>
			<p class="mt-1 text-sm text-slate-700">
				Se borrarán para siempre el assessment y todo lo asociado:
				<strong>{deletion.questions}</strong>
				{deletion.questions === 1 ? 'pregunta' : 'preguntas'},
				<strong>{deletion.participants}</strong>
				{deletion.participants === 1 ? 'participante' : 'participantes'} y
				<strong>{deletion.submitted}</strong>
				{deletion.submitted === 1 ? 'entrega' : 'entregas'}, con sus respuestas, reportes y análisis. No se
				puede deshacer. Si solo quiere ocultarlo, use <em>Archivar</em>.
			</p>
			<form
				method="POST"
				action="{base}/eliminar"
				class="mt-4 flex flex-wrap items-end gap-3"
				onsubmit={deleteAssessment}
			>
				<div class="min-w-64 flex-1">
					<label for="confirmacion" class={ui.label}>
						Para confirmar, escriba el título: <span class="font-semibold">{a.title}</span>
					</label>
					<input
						id="confirmacion"
						name="confirmacion"
						bind:value={$del.confirmacion}
						autocomplete="off"
						spellcheck="false"
						class={ui.input}
					/>
				</div>
				<button class={ui.btnDanger} disabled={$del.confirmacion.trim() !== a.title.trim() || $del.processing}>
					Eliminar definitivamente
				</button>
			</form>
			{#if $del.errors.confirmacion}
				<p class="mt-2 {ui.fieldError}" role="alert">{$del.errors.confirmacion}</p>
			{/if}
		</section>
	</div>
</AssessmentLayout>
