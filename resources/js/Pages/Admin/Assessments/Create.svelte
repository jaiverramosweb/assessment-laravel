<script lang="ts">
	import { Link } from '@inertiajs/svelte';
	import AdminLayout from '@/Layouts/AdminLayout.svelte';
	import AssessmentForm, { type AssessmentFormValues } from '@/components/AssessmentForm.svelte';
	import { ui } from '@/lib/ui';

	let {
		values,
		timezones,
		clients
	}: {
		values: AssessmentFormValues;
		timezones: string[];
		clients: { id: string; name: string }[];
	} = $props();
</script>

<svelte:head>
	<title>Nuevo assessment · Administración</title>
</svelte:head>

<AdminLayout>
	<nav class="text-sm text-slate-500">
		<Link href="/admin" class="hover:underline">Assessments</Link> / Nuevo
	</nav>
	<h1 class="mt-2 text-2xl font-semibold tracking-tight">Nuevo assessment</h1>

	<div class="mt-6 {ui.card}">
		<AssessmentForm
			action="/admin/assessments/nuevo"
			{values}
			{timezones}
			{clients}
			submitLabel="Crear y agregar preguntas"
		/>
	</div>
</AdminLayout>
