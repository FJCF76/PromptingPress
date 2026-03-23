# PromptingPress

An AI-first WordPress theme — a clean component framework designed so any AI can understand, build, and edit the entire site directly.

## What makes this different

Most WordPress themes are optimized for human developers who accumulate WordPress knowledge over time. AI can't accumulate — it re-infers everything from the code on every session.

PromptingPress flips this: the structure itself is the documentation. An AI can load `AI_CONTEXT.md`, map the entire site in seconds, and make confident edits without knowing WordPress internals.

## Architecture

- **WordPress** handles the backend: admin, users, media, plugins, ACF, database
- **This theme** handles rendering — and nothing else
- **`/lib/wp.php`** is the only file that calls WordPress functions. Templates never touch WP directly.
- **`/components/`** are isolated PHP partials with typed props and `schema.json`
- **`AI_CONTEXT.md`** is the AI's map of the full site

## Status

Work in progress.
