# Testing Rules

## Test After Every Change
- After making changes, test all directly affected functionality.
- Run the existing test suite before marking a task complete.
- Do not skip tests because a change looks trivial.

## Prevent Regressions
- Verify that existing features still work after your changes.
- If a change touches shared code (utilities, middleware, models), test all consumers.
- Fix any regressions before considering the task done.

## Write and Update Tests
- Add or update tests when introducing new functionality or fixing bugs.
- Tests must cover the happy path and key failure/edge cases.
- Do not delete existing tests unless the behavior they cover has been intentionally removed.

## Test Quality
- Tests must be deterministic — no random failures.
- Do not use production data in tests.
- Keep test setup and teardown clean so tests do not affect each other.
