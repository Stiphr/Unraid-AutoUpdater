# Architecture and implementation plan

This document records what the existing plugin did, which native Unraid mechanisms were verified
(against the public `unraid/webgui` source, not from memory), and the design that follows from them.

## 1. Existing plugin (v2026.10.28a), as found in the repository

The repository contained only `docker.autoupdate.plg` (self-contained, files inlined) and `LICENSE`.

| Area | What existed |
|---|---|
| Layout | `.page` (Settings > User Utilities), `include/lib.php`, `include/api.php`, `scripts/run.php`, `assets/app.{css,js}` |
| Config | `/boot/config/plugins/docker.autoupdate/config.json` (`global` + `containers{auto,notify,schedule}`) |
| Scheduling | One cron line per distinct schedule slot (`run.php --scheduled --slot=KEY`) in `docker-autoupdate.cron` |
| Docker | Containers listed from `docker inspect`; update flag from Unraid's `unraid-update-status.json`; update via `update_container` |
| Extras | Unraid notifications, history (100 entries), log, per-container schedule/notify, bi-weekly gating, lock file |

Defects found while verifying against Unraid's source (all fixed in this release):

1. `dau_refresh_updates()` called `scripts/dockerupdate.php`. The real script is `scripts/dockerupdate`
   (no extension), so the pre-run refresh never executed on a real server.
2. `update_container` reports progress over nchan, not stdout, and **exits 0 when the image pull fails**
   (it `continue`s). v1 inferred failure from the exit code, so a failed pull was recorded as
   "already on the latest image". Success is now decided by independent verification.
3. v1 ran `docker image prune -f` after updates. Unraid's `update_container` already removes the
   replaced orphan image, and a blanket prune is broader than necessary. Replaced by a targeted,
   non-forced removal of the one previous image after a verified update.
4. The API accepted mutating actions over GET. Mutating actions are now POST-only.

## 2. Native mechanisms (verified in `unraid/webgui`)

| Need | Mechanism | Notes |
|---|---|---|
| Docker update status | `/var/lib/docker/unraid-update-status.json`: `{image:tag: {local, remote, status: true/false/undef}}` | `local`/`remote` are image digests. Used as the update identity for delays. |
| Refresh Docker status | `dynamix.docker.manager/scripts/dockerupdate check nonotify` | `nonotify` avoids a duplicate native notification. |
| Apply Docker update | `dynamix.docker.manager/scripts/update_container NAME[*NAME2]` | Pull, stop, remove, recreate from the user template. Exit code is not reliable. |
| Installed plugins | symlinks `/var/log/plugins/*.plg` -> `/boot/config/plugins/*.plg` | Built-ins `unRAIDServer`, `unRAIDServer-` are skipped, as Unraid does. |
| Plugin check | `plugin check NAME.plg` | Downloads `pluginURL` of the installed plg to `/tmp/plugins/NAME.plg`, prints its version. |
| Plugin update | `plugin update NAME.plg` | Installs from `/tmp/plugins/NAME.plg`; enforces `min`/`max` Unraid version; refuses same-version reinstall. |
| Version compare | `strcmp(latest, installed) > 0` | What Unraid's own `updateall` uses; this plugin uses the same rule. |
| OS version | `/etc/unraid-version` | |
| OS update check | `plugin check unRAIDServer.plg` (the flow the stock UI uses for the built-in plg) | `unraidcheck` is not in the public repo, so its output format could not be verified. |
| Cron | `/boot/config/plugins/<plugin>/<job>.cron` + `/usr/local/sbin/update_cron` | Same pattern Unraid's own `parse_cron_cfg` uses; files on flash survive reboots. |
| Notifications | `webGui/scripts/notify -e -s -d -i -l -x` | |

Not verifiable from source: the page URL of the "Update OS" tool (it ships with the OS, not the public repo).
The UI links to **Tools** and names the menu entry instead of guessing a URL.

## 3. Design decisions

* **Keep the architecture.** PHP + vanilla JS, same file layout, same config file, same cron mechanism.
  New code lives in new `include/*.php` modules; `lib.php` stays the entry point.
* **One dispatcher ("tick").** A single cron line (`*/5 * * * *`) runs `run.php --tick`. The tick is
  stateless and cheap: it reads config + state and starts a job only when something is *due*.
  This replaces one-cron-line-per-slot and gives:
  * catch-up after a reboot (bounded to a 6 hour grace),
  * retries, delay expiry and maintenance windows without extra cron lines,
  * no blocking waits (a busy lock just means "try again next tick").
  If nothing is enabled the cron file is removed entirely.
* **One lock for everything.** Docker, plugin and OS jobs share a single `flock`, so they cannot overlap.
* **Verification is independent of exit codes** (Docker: image id + container state + health;
  plugin: installed plg version re-read after install).
* **Test seams are in-process only.** `dau_run()` (commands), `dau_http_post()` and `dau_now()` can be
  replaced by globals set by the test harness. Nothing reads them from the environment or the web.

## 4. Safety model for plugin auto-update

Auto-installing a plugin means running that author's installer as root. The plugin cannot make that safe;
it can only make it deliberate and verifiable:

* Off by default; opt-in globally **and** per plugin; optional delay (default 24 h) so a bad release can be pulled.
* Before installing, the downloaded plg must pass: HTTPS-only URLs, no shell metacharacters in the update URL,
  same `pluginURL` as the installed plg, same plugin name, strictly newer version, Unraid `min`/`max` compatible,
  no *new* download hosts compared with the installed plg, size limit, no external XML entities.
  Anything else is **skipped and reported**, never forced.
* SHA-256 of the downloaded plg is recorded after validation and re-checked immediately before `plugin update`.
* The previous plg is copied to a recovery folder first (bounded retention).
* After install the installed plg is re-read and its version must match; otherwise the update is reported as
  failed verification.
* This plugin never auto-updates itself (it would replace files while running).
* Rollback is **not automated**: re-installing an old plg re-downloads whatever its URLs point at, which may no
  longer exist. The recovery copy plus written guidance is provided instead.

## 5. Delay model

`first_seen` is stored per container against the **remote image digest**. A repeated check of the same
digest never resets it; a different digest starts a new delay. The clock starts when this plugin first
*sees* the digest (registries do not expose a reliable publish time via the digest), so a delay is
"at least N hours after first seen". If a digest is not available (`status: undef`) the update is not
auto-applied when a delay is configured.

## 6. Test strategy

PHP harness under `tests/` using the in-process seams; no network, no Docker, no Unraid needed.
Real `proc_open` is covered separately with harmless commands (timeouts, output limits).
