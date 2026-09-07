# Original Prompts

This file logs the original, verbatim user prompts that initiated each
plan/round of work on this project.

## 2026-09-06

> Ship version 1.0.1 of the homebrewing app. Important context: per docs/plans/0001-v1.0.0-initial-release.md, only Task T1 (bare project scaffold) has actually been implemented so far — there is no router, front controller, auth, recipe/batch CRUD, or any templates yet, even though v1.0.0 was tagged. The requests below (especially #2-4) depend on functionality that doesn't exist yet, so treat this as: build the necessary minimum of the outstanding v1.0.0 foundation (routing, session auth/login, recipe CRUD, batch+diary log CRUD, a real homepage template) as far as needed to support the four items below, then layer these on top. Use judgment on how much of T2-T12 is strictly required as a dependency vs. out of scope — don't gold-plate, but don't fake the features either.
>
> Scope for v1.0.1:
> 1. Add a README.md at the repo root (the user asked for "README.com" but meant README.md) covering: what the project is about; a glossary of domain terms; the structure of the project; how to deploy it on a generic PHP/MySQL shared hosting account (no specific hosting provider named).
> 2. Improve the homepage so it visually showcases the latest recipes (card grid of N most recently created/updated recipes with name, style/type, link to view).
> 3. The homepage/nav should have a clear separation between a "Recipes" section and a "Diaries" section (batches/brew diaries), each with their own listing view.
> 4. When a user is logged in, they must be able to mark each of their own recipes and diaries (batches) as public or private (a visibility toggle), and private ones must not be viewable by other users or via public/share links unless made public.
>
> Bump the version to 1.0.1 wherever the project tracks its version (e.g. composer.json "version" field) and make sure this is reflected in the README/changelog if one exists.
>
> Follow the existing plan-file and task-checklist conventions already established in docs/plans/ for this repo.

## 2026-09-07T22:14:12Z

> Run agents to create version 1.0.2 with:
> 1. git storing the repo in the remote location `git@github.com:andrealorenzani/homebrewing.git`
> 2. prepare a script to upload files into a shared host: this assumes that the script uses user and pass for sftp and for ssh
> 3. the above script should do a full deploy, whatever it takes in terms of actions, but it must be very safe and all configurations should be stored in a gitignored file
> 4. Everything should be well documented in the README file

**Scoping note:** item 1 (configuring/pushing the `git@github.com:andrealorenzani/homebrewing.git` remote) was explicitly declared out of scope for the coordinator and every implementer round in this unit of work — that is repository administration handled outside this workflow (by the calling session directly), not something any task/implementer touched. No task ran `git remote`, `git push`, or touched `.git` config. Only items 2-4 were actually implemented, shipped as version 1.0.2: a safe SFTP/SSH deploy script (`apps/web/bin/deploy.sh`) with staged local builds, timestamped remote backup-before-overwrite, SFTP upload with secret-excluding paths, remote migration execution with automatic `.env` carry-forward across releases, and optional health-check-triggered automatic (code-only) rollback; its gitignored configuration file (`apps/web/deploy.conf`, templated by the committed `apps/web/deploy.conf.example`); and a new README section documenting the whole workflow. No application code, routes, database schema, or user-facing behavior changed in this release.
