// Shared Inertia props (see app/Http/Middleware/HandleInertiaRequests.php).

export type SessionAdmin = { id: string; email: string; name: string };

export type SharedProps = {
	admin: SessionAdmin | null;
	flash: { success: string | null; error: string | null };
	errors: Record<string, string>;
};
