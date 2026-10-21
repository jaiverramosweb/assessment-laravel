<script lang="ts">
	import { Link, useForm } from '@inertiajs/svelte';
	import AssessmentLayout, { type AssessmentContext } from '@/Layouts/AssessmentLayout.svelte';
	import { renderTemplate, type InviteVars } from '@/lib/invite-template';
	import { ui } from '@/lib/ui';

	type Outcome = {
		templateSaved?: boolean;
		sendError?: string;
		sendResult?: { sent: number; failed: number; total: number };
		copyError?: string;
		generic?: { subject: string; body: string };
	} | null;

	type Props = {
		context: AssessmentContext;
		template: { subject: string; body: string };
		placeholders: Record<string, string>;
		previewVars: InviteVars;
		recipientCounts: { sin_invitar: number; no_completados: number };
		mailerKind: 'smtp' | 'consola';
		passwordStored: boolean;
		outcome: Outcome;
	};

	let { context, template, placeholders, previewVars, recipientCounts, mailerKind, passwordStored, outcome }: Props =
		$props();
	const a = $derived(context.assessment);
	const base = $derived(`/admin/assessments/${context.assessment.id}`);

	// Editable template with live preview.
	// svelte-ignore state_referenced_locally
	const templateForm = useForm({ subject: template.subject, body: template.body });
	const templateErrors = $derived($templateForm.errors as Record<string, string | undefined>);
	const preview = $derived({
		subject: renderTemplate($templateForm.subject, previewVars),
		body: renderTemplate($templateForm.body, previewVars)
	});

	const sendForm = useForm({ destinatarios: 'sin_invitar', password: '' });
	const copyForm = useForm({ password: '' });

	let copiado = $state(false);

	function saveTemplate(event: SubmitEvent) {
		event.preventDefault();
		$templateForm.post(`${base}/invitaciones/plantilla`, { preserveScroll: true, preserveState: true });
	}

	function send(event: SubmitEvent) {
		event.preventDefault();
		if (!confirm('¿Enviar las invitaciones ahora?')) return;
		$sendForm.post(`${base}/invitaciones/enviar`, {
			preserveScroll: true,
			preserveState: true,
			onFinish: () => $sendForm.reset('password')
		});
	}

	function generate(event: SubmitEvent) {
		event.preventDefault();
		$copyForm.post(`${base}/invitaciones/copiar`, {
			preserveScroll: true,
			preserveState: true,
			onFinish: () => $copyForm.reset('password')
		});
	}

	async function copiar(text: string) {
		try {
			await navigator.clipboard.writeText(text);
			copiado = true;
			setTimeout(() => (copiado = false), 2000);
		} catch {
			copiado = false;
		}
	}
</script>

<svelte:head>
	<title>Invitaciones · {a.title}</title>
</svelte:head>

<AssessmentLayout {context}>
	<div class="space-y-6">
		{#if !a.hasPassword}
			<p class={ui.alertWarning}>
				Defina la contraseña de acceso en <Link href={base} class="underline">General</Link> antes de invitar.
			</p>
		{/if}

		<!-- Plantilla -->
		<section class={ui.card} aria-labelledby="tpl-titulo">
			<h2 id="tpl-titulo" class="text-base font-semibold">Plantilla del mensaje</h2>
			<p class="mt-1 text-sm text-slate-600">
				Use estas variables; se reemplazan para cada participante:
			</p>
			<ul class="mt-2 flex flex-wrap gap-2 text-xs">
				{#each Object.entries(placeholders) as [key, label] (key)}
					<li class="rounded bg-slate-100 px-2 py-1" title={label}>
						<code>{`{{${key}}}`}</code> <span class="text-slate-500">{label}</span>
					</li>
				{/each}
			</ul>

			<div class="mt-4 grid gap-6 lg:grid-cols-2">
				<form method="POST" onsubmit={saveTemplate} class="space-y-3">
					<div>
						<label for="subject" class={ui.label}>Asunto</label>
						<input
							id="subject"
							name="subject"
							bind:value={$templateForm.subject}
							maxlength="200"
							class={ui.input}
						/>
						{#if templateErrors.subject}
							<p class={ui.fieldError}>{templateErrors.subject}</p>
						{/if}
					</div>
					<div>
						<label for="body" class={ui.label}>Mensaje</label>
						<textarea
							id="body"
							name="body"
							bind:value={$templateForm.body}
							rows="14"
							maxlength="5000"
							class="{ui.input} font-mono text-xs"
						></textarea>
						{#if templateErrors.body}
							<p class={ui.fieldError} role="alert">{templateErrors.body}</p>
						{/if}
					</div>
					<div class="flex items-center gap-3">
						<button class={ui.btnPrimary} disabled={$templateForm.processing}>Guardar plantilla</button>
						{#if outcome?.templateSaved}<span class="text-sm text-emerald-700" role="status"
								>Plantilla guardada.</span
							>{/if}
					</div>
				</form>

				<div>
					<p class={ui.label}>Vista previa</p>
					<div class="mt-1 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm">
						<p><span class="text-slate-500">Asunto:</span> <strong>{preview.subject}</strong></p>
						<hr class="my-3 border-slate-200" />
						<p class="whitespace-pre-line">{preview.body}</p>
					</div>
				</div>
			</div>
		</section>

		<div class="grid gap-6 lg:grid-cols-2">
			<!-- Envío por correo -->
			<section class={ui.card} aria-labelledby="enviar-titulo">
				<h2 id="enviar-titulo" class="text-base font-semibold">Enviar por correo</h2>
				{#if mailerKind === 'consola'}
					<p class="mt-2 {ui.alertWarning}">
						No hay servidor SMTP configurado: los correos se escribirán en el registro del servidor
						(<code>storage/logs</code>) en lugar de enviarse. Configure <code>MAIL_MAILER=smtp</code> y
						<code>MAIL_HOST</code> en <code>.env</code> para enviarlos.
					</p>
				{/if}
				{#if a.status !== 'publicado'}
					<p class="mt-2 {ui.alertWarning}">
						El assessment no está publicado. Publíquelo en
						<Link href={base} class="underline">General</Link> para poder enviar invitaciones.
					</p>
				{/if}

				<form method="POST" onsubmit={send} class="mt-4 space-y-4">
					<fieldset>
						<legend class={ui.label}>Destinatarios</legend>
						<div class="mt-2 space-y-2 text-sm">
							<label class="flex items-center gap-2">
								<input
									type="radio"
									name="destinatarios"
									value="sin_invitar"
									bind:group={$sendForm.destinatarios}
									class="text-brand-600"
								/>
								Quienes aún no han recibido invitación ({recipientCounts.sin_invitar})
							</label>
							<label class="flex items-center gap-2">
								<input
									type="radio"
									name="destinatarios"
									value="no_completados"
									bind:group={$sendForm.destinatarios}
									class="text-brand-600"
								/>
								Todos los que no han entregado ({recipientCounts.no_completados}) — recordatorio
							</label>
						</div>
					</fieldset>
					{#if passwordStored}
						<p class="flex items-center gap-2 text-sm text-slate-600">
							<svg
								width="16"
								height="16"
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								stroke-width="2"
								stroke-linecap="round"
								stroke-linejoin="round"
								class="text-brand-700"
								aria-hidden="true"
								><rect x="4" y="11" width="16" height="10" rx="2"></rect><path
									d="M8 11V7a4 4 0 0 1 8 0v4"
								></path></svg
							>
							El mensaje incluirá automáticamente la contraseña de acceso vigente.
						</p>
					{:else}
						<div>
							<label for="send-password" class={ui.label}>Contraseña de acceso vigente</label>
							<input
								id="send-password"
								name="password"
								type="password"
								autocomplete="off"
								required
								bind:value={$sendForm.password}
								class={ui.input}
							/>
							<p class={ui.hint}>
								Se definió antes de poder consultarse: escríbala para incluirla en el mensaje (o
								defínala de nuevo en General).
							</p>
						</div>
					{/if}
					<button class={ui.btnPrimary} disabled={$sendForm.processing || a.status !== 'publicado'}>
						{$sendForm.processing ? 'Enviando…' : 'Enviar invitaciones'}
					</button>
				</form>

				{#if outcome?.sendError}
					<p class="mt-3 {ui.alertError}" role="alert">{outcome.sendError}</p>
				{/if}
				{#if outcome?.sendResult}
					<p
						class="mt-3 {outcome.sendResult.failed ? ui.alertWarning : ui.alertSuccess}"
						role="status"
					>
						Se enviaron {outcome.sendResult.sent} de {outcome.sendResult.total} invitaciones.
						{#if outcome.sendResult.failed}
							{outcome.sendResult.failed} fallaron: revise la columna "Invitación" en
							<Link href="{base}/participantes" class="underline">Participantes</Link>.
						{/if}
					</p>
				{/if}
			</section>

			<!-- Copiar mensaje -->
			<section class={ui.card} aria-labelledby="copiar-titulo">
				<h2 id="copiar-titulo" class="text-base font-semibold">Copiar mensaje</h2>
				<p class="mt-1 text-sm text-slate-600">
					Genera un mensaje general (sin nombre) para enviarlo por chat u otro medio.
				</p>
				<form method="POST" onsubmit={generate} class="mt-4 flex flex-wrap items-end gap-3">
					{#if !passwordStored}
						<div class="grow">
							<label for="copy-password" class={ui.label}>Contraseña de acceso vigente</label>
							<input
								id="copy-password"
								name="password"
								type="password"
								autocomplete="off"
								required
								bind:value={$copyForm.password}
								class={ui.input}
							/>
						</div>
					{/if}
					<button class={ui.btnSecondary} disabled={$copyForm.processing}>Generar mensaje</button>
				</form>
				{#if outcome?.copyError}
					<p class="mt-3 {ui.alertError}" role="alert">{outcome.copyError}</p>
				{/if}
				{#if outcome?.generic}
					{@const texto = `${outcome.generic.subject}\n\n${outcome.generic.body}`}
					<label for="generic" class="mt-4 {ui.label}">Mensaje</label>
					<textarea id="generic" readonly rows="12" class="{ui.input} text-xs" value={texto}></textarea>
					<button type="button" class="mt-2 {ui.btnSecondary}" onclick={() => copiar(texto)}>
						{copiado ? 'Copiado' : 'Copiar al portapapeles'}
					</button>
				{/if}
			</section>
		</div>
	</div>
</AssessmentLayout>
