// JSON POST for the endpoints that answer data instead of a page (Word preview, password reveal).
// Laravel checks the CSRF token through the X-XSRF-TOKEN header (value of the XSRF-TOKEN cookie).

function xsrfToken(): string {
	const match = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
	return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

export type JsonResult<T> = { ok: true; status: number; data: T } | { ok: false; status: number; data: T | null };

export async function postJson<T>(url: string, body?: FormData): Promise<JsonResult<T>> {
	const res = await fetch(url, {
		method: 'POST',
		body: body ?? new FormData(),
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			'X-Requested-With': 'XMLHttpRequest',
			'X-XSRF-TOKEN': xsrfToken()
		}
	});
	let data: T | null = null;
	try {
		data = (await res.json()) as T;
	} catch {
		data = null;
	}
	return res.ok ? { ok: true, status: res.status, data: data as T } : { ok: false, status: res.status, data };
}
