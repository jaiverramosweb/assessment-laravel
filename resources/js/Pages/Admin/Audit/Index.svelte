<script lang="ts">
	import AdminLayout from '@/Layouts/AdminLayout.svelte';
	import { formatDateTime } from '@/lib/format';

	// Props from app/Http/Controllers/Admin/Audit/AuditController.php.
	type Entry = {
		id: number;
		action: string;
		entityType: string | null;
		metadata: Record<string, unknown> | null;
		ip: string | null;
		createdAt: number;
		adminName: string | null;
		adminEmail: string | null;
		label: string;
		detail: string;
	};

	let { limit, entries }: { limit: number; entries: Entry[] } = $props();
</script>

<svelte:head>
	<title>Auditoría · Administración</title>
</svelte:head>

<AdminLayout>
	<h1 class="text-2xl font-semibold tracking-tight">Auditoría</h1>
	<p class="mt-1 text-sm text-slate-600">Últimas {limit} acciones registradas.</p>

	{#if entries.length === 0}
		<p class="mt-6 text-slate-600">Todavía no hay acciones registradas.</p>
	{:else}
		<div class="mt-6 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
			<table class="min-w-full divide-y divide-slate-200 text-sm">
				<thead class="bg-slate-50 text-left text-slate-600">
					<tr>
						<th scope="col" class="px-4 py-2 font-medium">Fecha</th>
						<th scope="col" class="px-4 py-2 font-medium">Administrador</th>
						<th scope="col" class="px-4 py-2 font-medium">Acción</th>
						<th scope="col" class="px-4 py-2 font-medium">Detalle</th>
						<th scope="col" class="px-4 py-2 font-medium">IP</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-slate-100">
					{#each entries as e (e.id)}
						<tr>
							<td class="px-4 py-2 whitespace-nowrap text-slate-600">{formatDateTime(e.createdAt)}</td>
							<td class="px-4 py-2">{e.adminName ?? '—'}</td>
							<td class="px-4 py-2">{e.label}</td>
							<td class="px-4 py-2 text-slate-600">{e.detail}</td>
							<td class="px-4 py-2 whitespace-nowrap text-slate-500">{e.ip ?? '—'}</td>
						</tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/if}
</AdminLayout>
