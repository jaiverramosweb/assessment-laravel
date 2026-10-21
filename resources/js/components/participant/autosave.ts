// Autosave request of the answer page (POST /a/{slug}/responder/guardar).
// It is a plain fetch, not an Inertia visit: the page stays put and only shows the save state.

export type SaveResult =
	| { type: 'success'; savedAt: number }
	| { type: 'redirect'; location: string }
	| { type: 'failure'; errors?: string[] };

/** Laravel's CSRF token, readable from the XSRF-TOKEN cookie (the same one Inertia uses). */
function xsrfToken(): string {
	const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
	return match ? decodeURIComponent(match[1]) : '';
}

/** Sends the changed answers (`q_<questionId>` => value). Throws on network errors. */
export async function saveDraft(url: string, fields: Record<string, string>): Promise<SaveResult> {
	const res = await fetch(url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			'X-Requested-With': 'XMLHttpRequest',
			'X-XSRF-TOKEN': xsrfToken()
		},
		body: JSON.stringify(fields)
	});
	try {
		const data = (await res.json()) as SaveResult;
		if (data && typeof data === 'object' && 'type' in data) return data;
	} catch {
		// Not JSON (e.g. an expired page): reported as a generic failure below.
	}
	return { type: 'failure' };
}
