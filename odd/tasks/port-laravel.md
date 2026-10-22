# Port to Laravel (assessment platform)

Objective: rebuild `C:\laragon\www\Claude-encuesta` (SvelteKit + Node) as Laravel 12 + Inertia + Svelte 5 so it runs on the cPanel host without Node.
Branch: `feat/port-laravel` · Delivery: `ask-on-risk` · Runners: `php artisan test` (PHPUnit), `npx vitest --run` (frontend libs), `npm run build`.

## Specs

- **S1** — Runs on the target host: "este proyecto lo voy a subir a un cpanel a una capeta que es de un sub-dominio que se llama \"assesment.sisteltestserver.com\" y no corre npm". Host facts (L5): no Node, PHP 8.3.36 with pdo_sqlite, sqlite3, openssl, zip, gd, mbstring, curl, intl, dom, xml, fileinfo; `PASSWORD_ARGON2ID` defined; no sodium; Composer present; jailshell terminal. Composer platform pinned to PHP 8.3.0. Frontend assets are built locally; nothing on the server needs npm.
- **S2** — Functional parity with the original app: every admin and participant feature documented in the original `README.md` (assessments, questions incl. Word/Excel import and templates, access password hashed + encrypted, participants incl. Excel/CSV import, invitations by email or copy, participant answer flow with drafts and single submission, reports with filters, Excel/PDF exports, clients with logo/colors and client report, measurement series with comparison, AI analysis with anonymization, audit log, rate limiting, security headers). The original source is the behavioral reference.
- **S3** — "con laravel para subirlo alli": Laravel 12 + Inertia + Svelte 5, reusing the original Svelte UI where possible. SQLite database.
- **S4** — AI analysis must not depend on web request time limits: run as a queued job processed by a cPanel cron (`schedule:run`).
- **S5** — Deployable by upload: a packaged release (with `vendor/` and `public/build/`) plus step-by-step cPanel instructions.

Assumptions: the database keeps the original table/column layout (UUID text ids, ms timestamps) so data and behavior map 1:1; Laravel's own tables (sessions, cache, jobs) are added.

## Tasks

| ID | Specs | Scope | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | S1,S3 | Foundation: schema migrations, models, Inertia + Svelte + Tailwind setup, shared TS libs/components, security headers, secret box, rate limiter, audit, admin auth (login/logout/guard), admin layout, home + error pages, admin:create command; shared AssessmentContext + AssessmentLayout, Brand, Theme, Import\Table | inline | done (36 PHPUnit + 14 vitest green) | `57b8af1`, `42a4e82`, next |
| T2 | S2 | Clients: list/create/edit/delete, logo upload + admin/public logo serving, brand editor | delegated (parallel wave 1) | done (25 tests, RED observed) | `d0a9636` |
| T3 | S2 | Assessments: list/dashboard, create, general tab (edit, access password, publish, duplicate, delete, next measurement), questions editor, Word/Excel import + templates | delegated (parallel wave 1) | done | see L8 |
| T4 | S2 | Participants (manual + Excel/CSV import + template) and invitations (template, email send, copy) | delegated (parallel wave 1) | done | see L8 |
| T5 | S2 | Participant flow: /a/{slug} access, answer (drafts, submit), delivered page | delegated (parallel wave 1) | done | see L8 |
| T6 | S2 | Reports + Excel/PDF exports, client report + exports, measurements comparison + exports, audit page | delegated (parallel wave 2) | in progress | |
| T7 | S2,S4 | AI analysis (anonymize, prompts, chunking, cache by input hash) as queued job + scheduler | delegated (parallel wave 2) | in progress | |
| T8 | S1,S5 | Packaging for cPanel, README deployment guide, backup command | inline | pending | |

## Log

- **L1** (2026-10-21) User: "este proyecto lo voy a subir a un cpanel a una capeta que es de un sub-dominio que se llama \"assesment.sisteltestserver.com\" y no corre npm, dime como lo puedo subir y hacer que funcione"
- **L2** Host has no Node and the user cannot install it ("en el servidor no esta node instalado y no lo puedo instalar"). User chose Laravel: "con laravel para subirlo alli"; approved start: "si dale".
- **L3** Original repo HEAD `92b4d02`. Node bundle was built and verified under Linux Node 22 (works), but is unusable on this host.
- **L4** Scaffold: Laravel 12.70.1, inertia-laravel 2.0.28, phpspreadsheet 5, dompdf 3.1.6; svelte 5.57, @inertiajs/svelte 2.3, TS 6 (svelte-check requires ≤6). Commit `61903c2` on main; work on `feat/port-laravel`.
- **L6** Route declaration: T2–T5 are four independent units with disjoint edit surfaces, each far heavier than a subagent start → Writer trigger (a), launched together as wave 1. T6/T7 depend on T3/T4 services → wave 2. Parent owns git, installs and the final seam check (full suite + build).
- **L7** Conventions for every unit: same table/column layout as the original; models in app/Models (UUID + ms timestamps via `UsesUuidAndMsTimestamps`, time via `App\Support\Clock::nowMs()`); admin = `$request->attributes->get('admin')`; audit via `App\Security\Audit::log`; validation messages copied verbatim from the original zod schemas; general form error key `form`, flash `success`/`error`; pages under resources/js/Pages, assessment tabs wrapped in `AssessmentLayout` with `context = AssessmentContext::props($a)`; forms with `useForm`/`router` from `@inertiajs/svelte`.
- **L5** Host check (user terminal output): ea-php83 = 8.3.36; extensions listed in S1; CLI `max_execution_time=0`, `memory_limit=2048M`, `upload_max_filesize=256M`.
- **L8** Wave 1 results (each with RED observed first): T3 `6db5d72` (55 tests), T4 `5acb54a` (40), T5 `cc85ce4` (40), T2 `d0a9636` (25). Seam check: 199 PHPUnit + 14 vitest green, `npm run build` OK. Accepted deviations: failed form actions redirect 303 with one-shot props (Inertia); participant answers read from raw JSON (no trimming); no in-memory per-key lock (DB constraints instead); copyable invitation message passes through the session (review in final verification); access password reveal is a JSON call. Shared fixes by parent: AdminLayout `props`→`shared`, Login `formError`, `trimStrings(except: ['confirm'])`.
- **L9** Wave 2 contract: `AiStore::latest` + `AiReportProps::for` (`ai`, `analyses`, `aiPending`) committed `29778eb`; T6 and T7 launched in parallel.
