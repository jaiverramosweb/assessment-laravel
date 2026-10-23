# Admin access levels: report-only users scoped to clients

Objective: let the admin create users who can only see reports, choosing whether they see every client or only specific clients.
Branch: `feat/roles-permisos` · Delivery: `ask-on-risk` · Runners: `php artisan test`, `npm run build`.

## Specs

- **S1** — Client: "necesito saber si puedo crear un administrador que pueda ingresar pero solo a ver los reportes ... me van a pedir poder ver los avances en las respuestas". User: "crear un sistema de roles y permisos para poder crear usuarios y darle permisos para ver por los momento reporte pero es mejor que quede que el administrador pueda agregar". → In "Usuarios", each user has an access level: full access (current behavior) or limited access with a list of permissions the admin grants. Today the only grantable permission is "Ver reportes"; the catalog is built so more permissions can be added later. Existing admins keep full access. Changes audited.
- **S2** — User: "puedes hacer un selector en donde al darle ese permiso de ver los reportes desidir cual puede ver? si es todo o los de assessments de un cliente y asi a ese usuario?" → When "Ver reportes" is granted, a selector picks the scope: all clients, or specific clients (assumption: one or more clients can be checked). Set at creation and editable later.
- **S3** — Enforcement on the server, default deny: a limited user can only open the assessments list (filtered to their scope), the Reportes and Mediciones tabs (screen, Excel and PDF) of assessments in scope, the client "Informe general" of clients in scope, and "Mi cuenta". Any other admin URL answers 403, including every mutation and the AI analysis. Assessments without a client are visible only with the "all clients" scope.
- **S4** — The UI hides what a limited user cannot use: sidebar links (Clientes, Usuarios, Auditoría), assessment tabs other than Reportes/Mediciones, "Nuevo assessment", management buttons and the AI analysis button.
- **S5** — An admin cannot change their own access level (avoids locking themself out); a limited user can never reach the Usuarios tab.

## Tasks

| ID | Specs | Scope | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | S1,S2,S3,S5 | Migration (`access`, `permissions`), access policy, middleware (default deny + report scope), list filtering, user create/edit access, tests | inline (no writer reason); high risk (permissions, migration) → independent verifier | done (RED: 15 failed, no `access` column; GREEN 321 passed) | see git log |
| T2 | S1,S2,S4 | Usuarios UI (access selector + client scope), sidebar/tabs/buttons hidden, shared `admin.access` prop, README | inline (no writer reason) | done (svelte-check 0 errors, `npm run build` ok; browser check pending: local `.env`/migrate denied) | see git log |

## Log

- **L1** (2026-10-23) — User: "el cliente solicita crear un sistema de roles y permisos para poder crear usuarios y darle permisos para ver por los momento reporte pero es mejor que quede que el administrador pueda agregar como dece "ve es que ya publique con el cliente unas encuestas, pero necesito saber si puedo crear un administrador que pueda ingresar pero solo a ver los reportes no es pa hoy pero la otra semana seguro me van a pedir poder ver los avances en las respuestas" me puedes ayudar con eso?"
- **L2** (2026-10-23) — User: "puedes hacer un selector en donde al darle ese permiso de ver los reportes desidir cual puede ver? si es todo o los de assessments de un cliente y asi a ese usuario?"
- **L3** — Design: default deny. The `/admin` route group gets an `admin.full` middleware; only the routes a limited user may open opt out with `withoutMiddleware('admin.full')` and a scope check. New admin routes are therefore blocked for limited users unless they opt in.
