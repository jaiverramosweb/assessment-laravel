// Shared Inertia props (see app/Http/Middleware/HandleInertiaRequests.php).

/** Access level of an admin (app/Auth/AdminAccess.php). `reports` null = no report permission. */
export type AdminAccessInfo = {
	full: boolean;
	reports: { all: boolean; clientIds: string[] } | null;
};

export type SessionAdmin = { id: string; email: string; name: string; access: AdminAccessInfo };

export type SharedProps = {
	admin: SessionAdmin | null;
	flash: { success: string | null; error: string | null };
	errors: Record<string, string>;
};
