# Daily Task Reporting (Time-In & Time-Out)

This rule defines how to generate daily task logs and work reports for **Time-In** and **Time-Out** prompts.

---

## 1. Triggers & Inquiries

Activate this formatting rule whenever the user asks for:
- **Time-In**:
  - *"List all the tasks I am doing"*
  - *"Time in"* / *"My time in"* / *"What tasks are in progress today?"*
  - Starts around shift start (~1:00 PM).
- **Time-Out**:
  - *"List all the tasks I did for today"*
  - *"Time out"* / *"My time out"* / *"End of day report"*
  - Can be generated whenever the user concludes work (even before 5:00 PM or standard end-of-day).

---

## 2. Data Retrieval & Source of Truth

- **Git Commits & Log History**:
  - Check the repository's git commit log for the day (`git log --since="today 00:00:00"` or matching commit timestamps for the target date) to extract exact tasks, documents, features, and fixes committed and pushed.
- **Session & Staging Context**:
  - Combine git commits with files modified, audits produced, documents pushed to staging/production, and research conducted during the day's session.
- **Day-by-Day Accuracy**:
  - Only include tasks belonging to that specific calendar day.

---

## 3. Formatting Guidelines

1. **Title Header**:
   - `Time-In — [Month Day, Year]` (e.g., `Time-In — August 20, 2026`)
   - `Time-Out — [Month Day, Year]` (e.g., `Time-Out — August 20, 2026`)
2. **No Time Mentions in the Body**:
   - Do **NOT** list specific clock times, hours, or timestamps (e.g., do not add `1:00 PM`, `14:30`, or time ranges).
3. **Category Headings**:
   - Group work into clear, descriptive functional titles (e.g., `2-Way SMS Audit & Handoff`, `Documentation & Provider Research`, `Development & Workflow Rules`, `API Bugfixes & Optimization`).
4. **Item Descriptions**:
   - Write clear, concise, declarative sentences detailing what was audited, implemented, researched, committed, or pushed to branches/staging.
   - Mention branch status (e.g. committed and pushed to staging) and next steps/dependencies where relevant.

---

## 4. Standard Format Template

```markdown
Time-In — [Month Day, Year]

[Category / Task Name 1]
- [Accomplishment or in-progress summary]
- [Commit/push status or staging detail]
- [Next steps or dependency details]

[Category / Task Name 2]
- [Accomplishment or research summary]
- [Decision, scope refinement, or documentation created]

[Category / Task Name 3]
- [Accomplishment summary]
- [Action taken / review completed]
```

---

## 5. Reference Example

```markdown
Time-In — August 20, 2026

2-Way SMS Audit & Handoff
- Completed the 2-Way SMS Audit & Implementation Handoff for the planned UniSMS 2-way SMS implementation.
- Committed and pushed the completed audit document to staging.
- The audit and implementation planning are complete, with the next step pending the availability of the required UniSMS virtual numbers.

Documentation & Provider Research
- Committed and pushed the **App Installation Permissions Guide** to staging.
- Reviewed Cast.ph as an alternative provider and its related client context, but removed these sections from the current audit to keep the planned UniSMS 2-way SMS implementation scope focused.
- Kept the Cast.ph research and client context for a separate audit and future implementation planning if the provider needs to be switched.

Development & Workflow Rules
- Created development and workflow rules with four rule files under .agents/rules/.
- Reviewed the rules to establish consistent development practices and file-handling procedures.
```
