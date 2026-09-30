# Evaluation dataset and `eval:run` (M3)

PRD §73 (targets), §75 (dataset). Skill: `.claude/skills/prompt-eval`.

## Datasets

| Name | Path | In git | What it is |
|---|---|---|---|
| `sample` | `tests/Eval/data-sample/` | yes | 66 **synthetic** cases (fictional projects and people). Written by the assistant from the PRD rules, not from real usage. Proves the harness, the validator and the label format; gives a rough first baseline. |
| `realistic` | `tests/Eval/data-realistic/` | yes | 107 **synthetic** cases written to look like real Telegram notes (casual Indonesian, abbreviations, typos, mixed languages, vague references, five fictional clients, 20 tasks). Same caveat: labels come from the PRD rules, 37 are flagged `review`. |
| `local` | `tests/Eval/data/` | **no** (gitignored) | Your real, anonymised messages. This is the one that decides thresholds and prompt changes. |

Both use the same format: `snapshot.json` + `cases.jsonl`.

## Format

`snapshot.json` – the state of the tasks before any message is processed:

```json
{ "today": "2026-09-30", "timezone": "Asia/Jakarta",
  "projects": [{"key": "harbor", "name": "…", "aliases": ["…"]}],
  "people":   [{"key": "rina", "name": "Rina"}],
  "tasks": [{"key": "harbor:tracking", "project": "harbor", "title": "…", "status": "in_progress",
             "people": ["rina"], "completed_at": null, "last_activity_at": "2026-09-25T08:00:00+00:00",
             "activities": [{"date": "2026-09-25", "type": "development", "summary": "…"}]}] }
```
(`waiting` tasks may set `"waiting_reason"`; Completed tasks need `completed_at` for the 30 day candidate window.)

`cases.jsonl` – one JSON object per line:

```json
{"id": "C001", "categories": ["basic", "indonesian"], "message": "…", "review": false,
 "today": "2026-09-30",
 "expected": {"items": [{"project": "harbor", "task": "harbor:tracking" , "activity_type": "development",
                         "status_to": null, "date": 0, "title": "only for new tasks"}],
              "ambiguous": false, "decision": "needs_confirmation:date_older_than_30_days"}}
```
- `task`: a snapshot task key, or `"new"`. `date`: days relative to `today` (`0`, `-2`) or an absolute `"2026-08-15"`.
- `status_to`: only when the message clearly changes the status; otherwise `null`.
- One item per distinct piece of work. A note with no work (thanks, questions, plans for the future) has `"items": []`.
- `ambiguous: true` means a careful assistant must ask instead of picking: it passes when nothing is accepted automatically.
- `decision` (optional): the backend outcome you expect (`rejected:<reason>` / `needs_confirmation:<reason>`).
- `review: true` marks a label you are unsure about; the report lists these ids.
- Fake credentials are written as `{{secret:openai|github|password|generic}}` and expanded at runtime (never commit real ones).
- Cover the hard categories of §75: `vague`, `multi_item`, `cross_project`, `backdated`, `mixed_language`, `reopen`.

## Running

```bash
docker compose exec app php artisan eval:run                       # sample dataset, oracle "fake" provider
docker compose exec app php artisan eval:run --dataset=realistic   # larger, real-looking dataset
docker compose exec app php artisan eval:run --json --out=/tmp/base.json
docker compose exec app php artisan eval:run --prompt=worklog_extraction@v2 --baseline=/tmp/base.json
```
`--provider=fake` answers from the labels, so 100% only proves the harness. The real measurement needs DeepSeek:

```bash
# DEEPSEEK_API_KEY must be in .env (you fill it; the assistant never reads it) and AI_PROVIDER may stay "fake".
docker compose exec app php artisan eval:run --provider=deepseek --send-to-deepseek --dataset=local
```
`--send-to-deepseek` confirms that every message of the chosen dataset is sent to DeepSeek. Decide first whether that is
acceptable for the data (client confidentiality, DeepSeek's retention policy: Open Decision #7).

The report prints numbers and case ids only. Each run happens in a database transaction that is always rolled back.

## Reading the result

1. Compare the four PRD §73 metrics with their targets (90 / 95 / 90 / 95), then look at each hard category; a regression in
   one category matters even if the average rises.
2. "Task matching by model confidence": if the `high` bucket is not clearly more accurate than `medium`, raise
   `ai.confidence.high`; choose the cut-off that keeps the User Correction Rate under 15% (skill `prompt-eval`).
3. Ids under "Labels flagged for human review" and "Cases with a wrong metric" are where to look first: sometimes the label is wrong.
4. Changing the prompt = a new file `resources/prompts/worklog_extraction/v2.md` (never edit v1; a test checks its checksum),
   then run both versions and compare with `--baseline`.
