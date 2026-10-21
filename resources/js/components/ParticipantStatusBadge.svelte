<script lang="ts">
	import { PARTICIPANT_STATUS_LABELS, type ParticipantStatus } from '@/lib/constants';

	let { status }: { status: ParticipantStatus } = $props();

	const styles: Record<ParticipantStatus, string> = {
		pendiente: 'bg-slate-100 text-slate-700 ring-slate-300',
		en_progreso: 'bg-sky-50 text-sky-800 ring-sky-300',
		completado: 'bg-emerald-50 text-emerald-800 ring-emerald-300'
	};
</script>

<span
	class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset {styles[
		status
	]}"
>
	{PARTICIPANT_STATUS_LABELS[status]}
</span>
