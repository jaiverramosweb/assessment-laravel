<script lang="ts">
	import { STATUS_LABELS, type AssessmentStatus } from '@/lib/constants';

	let { status }: { status: AssessmentStatus } = $props();

	const styles: Record<AssessmentStatus, string> = {
		borrador: 'bg-slate-100 text-slate-700',
		publicado: 'bg-brand-50 text-brand-800',
		archivado: 'bg-amber-50 text-amber-900'
	};
</script>

<span
	class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-semibold {styles[
		status
	]}"
>
	{STATUS_LABELS[status]}
</span>
