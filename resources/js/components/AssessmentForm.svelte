<script lang="ts" module>
	export type AssessmentFormValues = {
		title: string;
		clientId: string;
		description: string;
		instructions: string;
		timezone: string;
		startsAt: string;
		endsAt: string;
		accessMode: string;
		openMaxChars: string | number;
	};
</script>

<script lang="ts">
	import { enhance } from '$app/forms';
	import { ACCESS_MODES, ACCESS_MODE_LABELS } from '@/lib/constants';
	import { ui } from '@/lib/ui';

	let {
		values,
		errors = {},
		timezones,
		clients,
		action = '',
		submitLabel
	}: {
		values: AssessmentFormValues;
		errors?: Record<string, string>;
		timezones: string[];
		clients: { id: string; name: string }[];
		action?: string;
		submitLabel: string;
	} = $props();

	let enviando = $state(false);
</script>

<form
	method="POST"
	{action}
	class="space-y-6"
	use:enhance={() => {
		enviando = true;
		return async ({ update }) => {
			await update({ reset: false });
			enviando = false;
		};
	}}
>
	<div>
		<label for="title" class={ui.label}>Título</label>
		<input
			id="title"
			name="title"
			required
			maxlength="200"
			value={values.title}
			aria-invalid={!!errors.title}
			aria-describedby={errors.title ? 'title-error' : undefined}
			class={ui.input}
		/>
		{#if errors.title}<p id="title-error" class={ui.fieldError}>{errors.title}</p>{/if}
	</div>

	<div>
		<label for="clientId" class={ui.label}>Cliente</label>
		<select
			id="clientId"
			name="clientId"
			value={values.clientId}
			aria-invalid={!!errors.clientId}
			class={ui.input}
		>
			<option value="">Sin cliente (colores de la plataforma)</option>
			{#each clients as c (c.id)}
				<option value={c.id}>{c.name}</option>
			{/each}
		</select>
		<p class={ui.hint}>El assessment usa el logo y los colores del cliente.</p>
		{#if errors.clientId}<p class={ui.fieldError}>{errors.clientId}</p>{/if}
	</div>

	<div>
		<label for="description" class={ui.label}>Descripción</label>
		<textarea id="description" name="description" rows="3" maxlength="5000" class={ui.input}
			>{values.description}</textarea
		>
		<p class={ui.hint}>Se muestra a los participantes al ingresar.</p>
		{#if errors.description}<p class={ui.fieldError}>{errors.description}</p>{/if}
	</div>

	<div>
		<label for="instructions" class={ui.label}>Instrucciones</label>
		<textarea id="instructions" name="instructions" rows="4" maxlength="10000" class={ui.input}
			>{values.instructions}</textarea
		>
		{#if errors.instructions}<p class={ui.fieldError}>{errors.instructions}</p>{/if}
	</div>

	<fieldset class="grid gap-4 sm:grid-cols-3">
		<legend class="mb-2 text-sm font-semibold text-slate-900">Disponibilidad</legend>
		<div>
			<label for="startsAt" class={ui.label}>Inicio</label>
			<input
				id="startsAt"
				name="startsAt"
				type="datetime-local"
				required
				value={values.startsAt}
				aria-invalid={!!errors.startsAt}
				class={ui.input}
			/>
			{#if errors.startsAt}<p class={ui.fieldError}>{errors.startsAt}</p>{/if}
		</div>
		<div>
			<label for="endsAt" class={ui.label}>Finalización</label>
			<input
				id="endsAt"
				name="endsAt"
				type="datetime-local"
				required
				value={values.endsAt}
				aria-invalid={!!errors.endsAt}
				class={ui.input}
			/>
			{#if errors.endsAt}<p class={ui.fieldError}>{errors.endsAt}</p>{/if}
		</div>
		<div>
			<label for="timezone" class={ui.label}>Zona horaria</label>
			<select id="timezone" name="timezone" class={ui.input} value={values.timezone}>
				{#each timezones as tz (tz)}
					<option value={tz}>{tz.replaceAll('_', ' ')}</option>
				{/each}
			</select>
			{#if errors.timezone}<p class={ui.fieldError}>{errors.timezone}</p>{/if}
		</div>
	</fieldset>

	<div class="grid gap-4 sm:grid-cols-2">
		<fieldset>
			<legend class={ui.label}>Modo de acceso</legend>
			<div class="mt-2 space-y-2">
				{#each ACCESS_MODES as mode (mode)}
					<label class="flex items-start gap-2 text-sm">
						<input
							type="radio"
							name="accessMode"
							value={mode}
							checked={values.accessMode === mode}
							class="mt-0.5 text-brand-600 focus:ring-brand-500"
						/>
						<span>
							{ACCESS_MODE_LABELS[mode]}
							<span class="block text-xs text-slate-500">
								{mode === 'invitados'
									? 'El email debe estar en la lista de participantes.'
									: 'Cualquier persona con la contraseña puede responder.'}
							</span>
						</span>
					</label>
				{/each}
			</div>
			{#if errors.accessMode}<p class={ui.fieldError}>{errors.accessMode}</p>{/if}
		</fieldset>

		<div>
			<label for="openMaxChars" class={ui.label}>Límite de caracteres en respuestas abiertas</label>
			<input
				id="openMaxChars"
				name="openMaxChars"
				type="number"
				min="500"
				max="50000"
				step="100"
				required
				value={values.openMaxChars}
				aria-invalid={!!errors.openMaxChars}
				class={ui.input}
			/>
			<p class={ui.hint}>8.000 caracteres equivalen aproximadamente a dos páginas.</p>
			{#if errors.openMaxChars}<p class={ui.fieldError}>{errors.openMaxChars}</p>{/if}
		</div>
	</div>

	<div class="flex justify-end">
		<button type="submit" class={ui.btnPrimary} disabled={enviando}>
			{enviando ? 'Guardando…' : submitLabel}
		</button>
	</div>
</form>
