<script lang="ts">
	import { ui } from '@/lib/ui';

	// Access level of an admin (app/Auth/AdminAccess.php::fromInput): full access, or limited
	// to the checked permissions. "Ver reportes" picks every client or specific ones.
	let {
		idPrefix,
		clients,
		errors,
		access = $bindable(),
		permissions = $bindable(),
		reportsScope = $bindable(),
		reportsClientIds = $bindable()
	}: {
		idPrefix: string;
		clients: { id: string; name: string }[];
		errors: Record<string, string | undefined>;
		access: 'full' | 'limited';
		permissions: string[];
		reportsScope: 'all' | 'clients';
		reportsClientIds: string[];
	} = $props();
</script>

<fieldset class="flex flex-col gap-2">
	<legend class={ui.label}>Nivel de acceso</legend>
	<label class="flex items-start gap-2 text-sm">
		<input type="radio" name="{idPrefix}-access" value="limited" bind:group={access} class="mt-1" />
		<span>
			<span class="font-medium">Acceso limitado</span>
			<span class="block text-slate-600">Solo puede hacer lo que marque abajo.</span>
		</span>
	</label>
	<label class="flex items-start gap-2 text-sm">
		<input type="radio" name="{idPrefix}-access" value="full" bind:group={access} class="mt-1" />
		<span>
			<span class="font-medium">Acceso completo</span>
			<span class="block text-slate-600">Puede hacer todo, incluso administrar usuarios.</span>
		</span>
	</label>
	{#if errors.access}<p class={ui.fieldError}>{errors.access}</p>{/if}

	{#if access === 'limited'}
		<fieldset class="mt-2 flex flex-col gap-2 rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
			<legend class="sr-only">Permisos</legend>
			<label class="flex items-start gap-2 text-sm">
				<input type="checkbox" value="reports" bind:group={permissions} class="mt-1" />
				<span>
					<span class="font-medium">Ver reportes</span>
					<span class="block text-slate-600">Reportes, Mediciones e Informe general, con descargas en Excel y PDF.</span>
				</span>
			</label>
			{#if errors.permissions}<p class={ui.fieldError}>{errors.permissions}</p>{/if}

			{#if permissions.includes('reports')}
				<fieldset class="ml-6 flex flex-col gap-2">
					<legend class="text-sm font-medium">¿Qué reportes puede ver?</legend>
					<label class="flex items-center gap-2 text-sm">
						<input type="radio" name="{idPrefix}-scope" value="clients" bind:group={reportsScope} />
						Solo los de clientes específicos
					</label>
					{#if reportsScope === 'clients'}
						{#if clients.length === 0}
							<p class="ml-6 text-sm text-slate-600">Todavía no hay clientes creados.</p>
						{:else}
							<ul class="ml-6 flex max-h-48 flex-col gap-1 overflow-y-auto">
								{#each clients as c (c.id)}
									<li>
										<label class="flex items-center gap-2 text-sm">
											<input type="checkbox" value={c.id} bind:group={reportsClientIds} />
											{c.name}
										</label>
									</li>
								{/each}
							</ul>
						{/if}
					{/if}
					<label class="flex items-center gap-2 text-sm">
						<input type="radio" name="{idPrefix}-scope" value="all" bind:group={reportsScope} />
						Todos los clientes (también los assessments sin cliente)
					</label>
					{#if errors.reportsClientIds}<p class={ui.fieldError}>{errors.reportsClientIds}</p>{/if}
				</fieldset>
			{/if}
		</fieldset>
	{/if}
</fieldset>
