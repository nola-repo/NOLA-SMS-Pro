# DevOps Rules

## Git Workflow
- Never push directly to `main`.
- All development changes must go through `staging` first.
- Keep commits focused and descriptive — one logical change per commit.
- Do not overwrite, force-push, or reset other people's work.

## Staging
- All changes must be deployed to `staging` and verified before promotion to `main`.
- Staging validation must cover the affected functionality end-to-end.
- Do not promote to `main` if staging verification has not been completed.

## CI/CD
- All CI checks (build, lint, tests) must pass before merging or deploying.
- Never bypass or ignore a failing CI check without investigating and resolving the root cause.
- If a CI check is flaky or incorrect, fix the check — do not disable it.

## Production Deployment
- Production deployments happen only after successful staging validation.
- Follow the project's existing deployment process — do not improvise steps.
- Monitor the deployment and verify key functionality immediately after release.
