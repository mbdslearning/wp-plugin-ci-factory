# WP Plugin CI Factory

This repository is a CI "factory" that runs comprehensive checks for a WordPress plugin ZIP stored in `uploads/plugin.zip`,
then optionally iterates an AI autofix loop, and finally produces `artifacts/final-plugin.zip` as the deliverable.

## Required secrets
- `OPENAI_API_KEY` (Actions secret) for autofix loop
- Optional: `OPENAI_MODEL` (Actions secret) to override model used by `tools/auto_fix_loop.py`

## How to run
Actions → "WP Plugin Comprehensive Gate (with optional autofix)" → Run workflow
Inputs:
- plugin_zip_path: uploads/plugin.zip
- max_iterations: 0..N
- php_matrix: e.g. 8.1,8.2,8.3
- wp_version: latest or specific version

Artifacts:
- gate-reports (reports/*)
- final-plugin (artifacts/final-plugin.zip)
