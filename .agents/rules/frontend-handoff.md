# Frontend Handoff Rules

## Handoff Document — Living Document (Option B)

`docs/FRONTEND_HANDOFF.md` is a **permanent, living document** in the repository.

- **Never delete** `docs/FRONTEND_HANDOFF.md`.
- When new findings, issues, or tasks arise that affect the frontend team, **add them to the top** of the file under a new dated section.
- When an item is resolved or completed by the frontend team, **mark it as ✅ done** — do not delete it. Keep the history.
- The frontend team always knows to look at `docs/FRONTEND_HANDOFF.md` for their current and past action items.

## Structure of Each Entry

When adding a new handoff section, use this format at the top of the file:

```markdown
---

## 📋 Handoff — YYYY-MM-DD

### Summary
Brief description of what this handoff covers.

### Items
- Item 1
- Item 2

(full details below...)
```

## Email Notification

When `docs/FRONTEND_HANDOFF.md` is updated with new items, trigger the manual email workflow:

- Go to GitHub Actions → `send-handoff-email.yml` → Run workflow
- The email tells the frontend team a new section was added to `docs/FRONTEND_HANDOFF.md`
- The frontend team reads the full details in the repo

## What Goes in the Handoff

Include items in `docs/FRONTEND_HANDOFF.md` when:

- A backend audit or finding reveals something only the frontend can fix
- A new feature requires frontend UI changes (e.g. 2-way SMS phases)
- A backend change could affect frontend behavior (e.g. API response shape change)
- The backend team has completed their portion and is waiting on frontend

Do NOT put backend-only tasks or internal backend notes in this file.
