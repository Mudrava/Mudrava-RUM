## What

- [ ] Describe the change and the problem it solves.

## Performance & privacy

- [ ] Collector stays async and adds no render-blocking work.
- [ ] No new personal data collected; no cookies set.
- [ ] New queries use existing indexes; large tables checked with `EXPLAIN`.

## Verification

- [ ] `composer lint` passes.
- [ ] `bash tests/run.sh` passes against a local WP container (if ingestion or
      queries changed).
- [ ] Tested on PHP 7.4 and the latest supported PHP.

## Docs

- [ ] `plugin/readme.txt` and `CHANGELOG.md` updated.
