## What

- [ ] Describe the change and the problem it solves.

## Performance & privacy

- [ ] Collector stays async and adds no render-blocking work.
- [ ] No new personal data collected; no cookies set. If some is unavoidable
      (for example an opt-out record), it is documented in `plugin/readme.txt`
      and `SECURITY.md`, and it is cleaned up by `uninstall.php`.
- [ ] New queries use existing indexes; large tables checked with `EXPLAIN`.

## Verification

- [ ] `composer lint` passes.
- [ ] `bash tests/run.sh` passes against the local stand (see README).
- [ ] Plugin Check reports nothing: `wp plugin check mudrava-rum` inside the stand.
- [ ] Tested on PHP 7.4 and the latest supported PHP.

## Docs

- [ ] `plugin/readme.txt` and `CHANGELOG.md` updated.
