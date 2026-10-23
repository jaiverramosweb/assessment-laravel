# Configurable AI provider (Anthropic or OpenAI) from the admin panel

Objective: choose the AI provider and model from a "Configuración" screen, store each provider's API key encrypted in the database, and list the provider's current models.
Branch: `feat/ia-configurable` · Delivery: `ask-on-risk` · Runners: `php artisan test`, `npm run build`.

## Specs

- **S1** — User: "es posible cambiar a que la IA quese consuma sea la de OpenAI?" → The open-answer analysis can run on OpenAI (Responses API, strict JSON schema, same prompt, schema, anonymization and Spanish error messages) or on Anthropic (unchanged behavior).
- **S2** — User: "si visualmente lo puedo hacer desde el administrador en configuraciones mejor, elegir cual modelo" → New "Configuración" section in the admin sidebar (full-access admins only) to pick the active provider and the model of each provider.
- **S3** — User: "poder colocar la API asi se guarde en la base de datos encriptada de ser posible y la puedas usar" → Each provider's API key is saved encrypted (SecretBox, AES-256-GCM) in the database and used by the analysis. The key is never sent back to the browser: only "configured · ending in XXXX". It can be replaced or removed.
- **S4** — User: "traerme los ultimos modelos que ha hoy listados alli tanto de OpenAI como ANTHROPIC" → An "Actualizar lista" action asks the provider's models endpoint (with the saved key) for its current models, newest first, and offers them in the model selector. OpenAI's list keeps only text-generation models. A typed model id is still accepted.
- **S5** — Assumption: when nothing is saved in the database, the previous `.env` values (`ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`) keep working, so existing installs do not break.
- **S6** — Assumption: settings changes are audited without the key value.

## Tasks

| ID | Specs | Scope | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | S1,S3,S5,S6 | `app_settings` migration, AiSettings (DB + .env fallback, encrypted keys), OpenAiAnalyzer, analyzer factory wired into job/controller/props/timeout, tests | inline (no writer reason); high risk (credentials, migration) → independent verifier | done (RED 13 failed; GREEN 343 passed) | see git log |
| T2 | S2,S3,S4 | Configuración routes/controller/page, model listing (Anthropic + OpenAI), sidebar link, tests, README | inline (no writer reason) | done (RED 7 failed; GREEN 350 passed; svelte-check 0/0; build ok; browser check pending) | see git log |

## Log

- **L1** (2026-10-23) — User: "bien ahora me estan pregunrando por lo de la IA que esta en los reportes o algo asi que esta con la api de antrhopi, es posible cambiar a que la IA quese consuma sea la de OpenAI?"
- **L2** (2026-10-23) — User: "si claro de una y si visualmente lo puedo hacer desde el administrador en configuraciones mejor, elegir cual modelo, poder colocar la API asi se guarde en la base de datos encriptada de ser posible y la puedas usar y traerme los ultimos modelos que ha hoy listados alli tanto de OpenAI como ANTHROPIC es posible eso?"
- **L4** — Independent verifier on `eae0be4`: S1, S2, S4, S5, S6 met; S3 blocker: a key with a control character or line break makes Guzzle reject the header with a message that echoes the value, and the `catch (Throwable)` blocks logged `$e->getMessage()` (refresh only trimmed the typed key; save allowed non-whitespace control chars). Advisories: reports 500 if the migration was not run; provider read at job time vs model checked at dispatch; no rate limit on refresh; SecretBox without associated data; a saved model cannot be cleared.
- **L5** — Correction: one key rule `AiSettings::validKey` (printable ASCII, no spaces, 10–500) on save and refresh; the three `catch (Throwable)` log the exception class only (also AnthropicAnalyzer, since its key can now come from the form). Verifier probes committed as `AiSettingsProbeTest` (blocker probe RED without the fix, GREEN with it; strengthened to assert rejection, flash, no outbound call and class-only logging). Full suite 369 passed.
- **L3** — Verified docs: OpenAI `POST /v1/responses` with `text.format {type: json_schema, name, schema, strict: true}`, `status: incomplete`, `refusal` content; the existing output schema already meets strict rules. Anthropic `GET /v1/models` (`x-api-key`, `anthropic-version`) returns `data[]` with `id`, `display_name`, `created_at`, `has_more`. OpenAI `GET /v1/models` returns `data[]` with `id`, `created`. Raw HTTP kept (the project already calls Anthropic without an SDK).
