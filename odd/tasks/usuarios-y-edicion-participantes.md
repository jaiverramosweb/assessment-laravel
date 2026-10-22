# Admin users tab and participant editing

Objective: let the admin edit participants' name/area/cargo from the table, and manage admin users (create, reset password, deactivate, change own password) from a new "Usuarios" tab.
Branch: `feat/usuarios-y-edicion-participantes` · Delivery: `ask-on-risk` · Runners: `php artisan test`, `npm run build`.

## Specs

- **S1** — Client: "el informe tiene unos datos pero fijate que yo no deje donde colocarlos" (Área and Cargo empty for "ingreso abierto" participants). User: "hay manera de que ella visualmente pueda editar eso y agregar lo que falta en la tabla?" → An "Editar" action per row of the participants table edits name, área and cargo inline. Email is not editable (it pairs people across measurements). Allowed even when the participant already answered. Validation is the same as manual add (`ParticipantInput`). Audited as `participantes.editado`. A participant of another assessment cannot be edited (404-style error).
- **S2** — Client: "Y no tengo como crear otros usuarios admin o luego cambiar mi contraseña, es para cuando le vaya a dar a david y lida para pruebas". User: "poder crear o agregar ese rol a otros usuarios creados si ella lo deside en otra pestaña". New "Usuarios" tab in the admin sidebar: list admins (name, email, last login, active/inactive), create an admin (name, email, initial password ≥ 10 chars), reset another admin's password (closes their sessions), deactivate/reactivate an admin (deactivated: login rejected and sessions closed). An admin cannot deactivate themself, and the last active admin cannot be deactivated. Every action audited.
- **S3** — "puede ser solo que me deje cambiar mi contraseña por ahora para darselas pero luego cambiarla": "Mi cuenta" lets the logged-in admin change their own password (current password + new ≥ 10 chars, confirmed); other sessions of that admin are closed, the current one stays.
- **S4** — Roles (L3): "por los momentos que el que ella desida tenga el rol como ella si mas adelnate quiere agrear otro se agregan" → every admin has full access; no role column now.

## Tasks

| ID | Specs | Scope | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | S1 | Participant update: route, service, inline edit UI, tests | inline (no writer reason) | pending | |
| T2 | S2,S3,S4 | `disabled_at` migration, AdminAuth/AdminSessions changes, Usuarios page + Mi cuenta, sidebar link, tests, README | inline (no writer reason); high risk (auth + migration) → independent verifier | pending | |

## Log

- **L1** (2026-10-22) — Client request, quoted in S1/S2. User: "me ayudas a validar eso en el codigo y ver como le solucionamos eso al cliente".
- **L2** — User: "crea un plan de trabajo para eso, para que pueda editar esa parte y poder crear o agregar ese rol a otros usuarios creados si ella lo deside en otra pestaña y dime el plan antes de implementarlo".
- **L3** — User on roles: "por los momentos que el que ella desida tenga el rol como ella si mas adelnate quiere agrear otro se agregan". Plan approved.
