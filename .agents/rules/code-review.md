# Code Review Rules

## Scope and Necessity
- Every changed line must be justified by the task requirements.
- Do not include refactors, formatting fixes, or unrelated cleanup in the same change.
- Flag any change that touches files outside the task scope.

## Code Quality
- Ensure logic is clear and readable without requiring a comment to explain it.
- Remove all debug statements, temporary code, and dead code before review.
- Confirm error handling is explicit and appropriate for each failure case.
- Check for duplicated logic that should be extracted into a shared utility.

## Security
- Verify no secrets, credentials, or tokens are hardcoded.
- Confirm all external input is validated before use.
- Ensure sensitive data is not logged or returned in responses.
- Check that the change does not weaken existing authentication or authorization.

## Consistency
- Verify the change follows the project's existing coding style and patterns.
- Confirm naming conventions match the rest of the codebase.
- Ensure new files are placed in the correct directory per the project structure.
