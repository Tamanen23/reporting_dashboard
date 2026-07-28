# Report processing lifecycle

Creation stores raw inputs, records checksums, derives the fingerprint, then queues work.

```text
file_storage → structural_validation → business_validation → normalization
→ calculation → result_validation → chart_generation → template_rendering
→ output_verification → publishing → cleanup
```

```text
uploaded → queued → validating → processing → rendering → verifying
→ completed | completed_with_warnings | failed | cancelled
```

Each transition appends an event and updates `last_progress_at`. Completion is legal only
after every expected artifact passes verification. Permanent validation failures do not
retry; transient failures use 30, 120, and 300 second delays.
