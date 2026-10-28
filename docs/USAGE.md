# Unraid AutoUpdater – detailed guide

Back to the [README](../README.md).

## What is on and what is off (defaults)

| Feature | Default |
|---|---|
| Docker auto-update schedule | **Off**. A container is only auto-updated when its policy is *Auto update*. |
| New containers | Policy *Ignore* (nothing happens until you choose) |
| Plugin update checks + notifications | On (report only) |
| **Automatic plugin installation** | **Off** (global switch, then per plugin) |
| Unraid OS update check | On, **notify only**. Automatic install / reboot are opt-in and confirmed in the UI |
| Discord | Off until you add a webhook |
| Backups before update | Off |
| Health verification, disk check, old-image cleanup | On |

## Policies

- **Auto update**: checked on the schedule, updated automatically once the delay, window and approval rules allow it.
- **Notify only**: you are told once per new image/version; nothing is installed.
- **Ignore**: nothing happens and no notifications are sent.

Containers can have their own schedule, delay, notification toggle, *critical* flag (needs your approval for each new image) and backup toggle. Unselected containers are never touched by schedules.

## Docker updates, step by step

1. Free-space check (blocks below 512 MB, warns below your threshold).
2. Optional backup (see below).
3. `docker pull` first, so network/registry problems are recognised. If it fails, the container was not touched.
4. Unraid's own `update_container` recreates the container from its template (keeps your settings).
5. Verification (does not rely on exit codes): new image in use, container running and stable, health check if one exists, up to a timeout. Results: *healthy*, *running (no health check)*, *timeout (still starting)*, *unhealthy*, *failed to start*, *image mismatch*. A container with no health check is **not** a failure.
6. Only after a verified success, the one replaced image is removed (`docker image rm`, never forced, never a prune). On failure the old image is kept.

**Update delay**: 0 / 24 h / 3 d / 7 d / custom, counted from the moment a given image digest was first seen. Repeated checks never reset it; a *new* digest starts a new delay. If the digest is unknown and a delay is set, the update is not applied automatically.

**Maintenance window**: optional days + start/end (overnight windows allowed). Outside it, updates wait; manual updates ignore it.

**Retries**: only for temporary pull errors (network, registry rate limit) that left the container untouched, up to *N* times with an interval. Never retried after the container was changed, and never for permanent errors (bad image name, auth). Each retry starts with a fresh status check.

**Recovery**: if an update fails, the container is **not** automatically recreated or rolled back. The history entry shows the previous image digest and the exact manual step (set Repository to `image@sha256:…` and apply). The old image is kept for this reason.

## Backups (optional, per container)

Destination must be under `/mnt`. Three different things:

- **Config**: the container template and `docker inspect` (environment values are blanked). Small.
- **Image**: `docker save` of the image currently in use. Large; needs free space at the destination.
- **Appdata**: tar of the container's host folders under `/mnt`, taken **while the container runs**, so databases may be inconsistent. Use a dedicated backup plugin for those.

Choose whether a failed backup blocks the update (*required*) or the update continues with a warning. Newest *N* backup sets are kept.

## Plugin updates

Uses Unraid's `plugin check` and `plugin update`. Per plugin: Auto / Notify / Ignore, delay (default 24 h), maintenance window. Before installing automatically:

- plugin URL must be plain `https://` with no shell metacharacters;
- the downloaded file must be a valid plugin, not larger than 2 MB, no external XML entities;
- same plugin name and source URL, announced version = downloaded version, newer than installed;
- compatible with this Unraid version (min/max);
- **no new download host** compared with the installed version (otherwise it is blocked for manual review);
- the file's SHA-256 is re-checked right before install; a copy of the old `.plg` is kept (last 3) in `/boot/config/plugins/unraid-autoupdater/plugin-recovery`;
- afterwards the installed file must report the new version.

This plugin never updates itself automatically. No automatic rollback: on failure you get recovery guidance.

## Unraid OS

**Default: check and notify only.** The check uses `plugin check unRAIDServer.plg`; each version is announced once, withdrawn releases and failed checks are reported.

Two opt-in modes (Settings → Unraid OS → *When an update is found*; each needs a confirmation box, and a mode without a matching confirmation is ignored, also if the settings file is edited by hand):

| Mode | What happens |
|---|---|
| Check and notify only (default) | Nothing is installed, nothing is rebooted. |
| Install updates, I reboot myself | The update is installed; it becomes active when you reboot. |
| Install updates and reboot automatically | As above, then the server reboots by itself. |

Safeguards for the automatic modes:
- only inside the OS maintenance window (default Sunday 03:00-05:00; required), and only after the waiting period (default 7 days after the release was first seen);
- stable releases only (`-rc`, beta, next are skipped) unless you turn that off;
- a fresh check right before installing, the same plugin safety validation as plugins (same source URL, https, no new download hosts, compatible), and at least 600 MB free on the flash drive;
- installed with Unraid's own `plugin update unRAIDServer.plg`; the install is verified (version and boot files) before any reboot;
- the reboot (stock `/sbin/reboot -n`, the command the Reboot button uses) is postponed while a parity check, sync or rebuild is running or the array is in an unusual state, retried every 30 minutes inside the window, and happens at most once per version;
- notifications (Unraid + Discord) are sent before the reboot; after the reboot the running version is compared with the expected one and the result is recorded (history, notification, Discord);
- one automatic attempt per version: a failed install is not retried by itself and never reboots.

### Pre-OS maintenance workflow

An OS install (automatic, or from the buttons on the Unraid OS tab) can be preceded by a controlled maintenance run. It reuses the normal plugin and Docker updaters (same safety checks, history and notifications), it is not a second updater:

1. a fresh OS check finds the release;
2. *(optional notice)* "pre-OS maintenance started" (Unraid + Discord);
3. **plugins** are updated first (only plugins whose policy is *Auto update*, or every non-ignored plugin if you choose; the plugin safety validation still applies; this plugin never updates itself);
4. **Docker containers** are updated next, only if enabled (only containers set to *Auto update*; *critical* containers are never included);
5. the results are checked: required steps must have succeeded, then the system is re-checked (array state, free space on the flash drive, all plugin files readable, Docker responding if it was used);
6. *(optional)* the workflow pauses and waits for your **Confirm** (or **Abort**) on the Unraid OS tab, for up to 24 hours;
7. only then the OS update is installed (and the server rebooted if, and only if, you chose that mode or button).

Any failed required step, failed system check or abort stops the workflow before the OS is touched, records the reason (history, dashboard, Unraid notification, Discord) and lists what was updated, skipped or failed. A job that dies midway is recorded as *interrupted* and never resumed blindly. One lock prevents overlapping jobs. The OS tab dialog lets you tick or untick the plugin/Docker steps for a manual install.

| Setting (Settings → Unraid OS → Before an OS update) | Default |
|---|---|
| Update plugins before the OS update | On |
| Require successful plugin updates | On |
| Which plugins | Only those set to Auto update |
| Update Docker containers before the OS update | **Off** |
| Require successful Docker updates | On (applies only when Docker updates are on) |
| Ask for confirmation before the OS is installed | Off (an automatic mode is already confirmed once in Settings; manual installs show a confirmation dialog) |
| Notify when the workflow starts | On |

The workflow only ever runs as part of an OS install, which itself is opt-in; in the default *notify only* mode nothing changes.

There is no automatic rollback. Unraid keeps the previous release on the flash drive: **Tools → Update OS → Restore**. The Unraid OS tab also has manual *Install update*, *Install and reboot* and *Reboot now* buttons (each with a confirmation).

## Discord

Settings → Discord: enable, paste the webhook (messages are sent as **Unraid AutoUpdater** with an orange-on-grey icon as avatar and header; set *Icon image URL* to use your own https image), **Send test message**. Toggles per category (Docker/Plugins/OS) and per type (available / success / failed / summary), combine or one message per notification, max messages per run. Embeds respect Discord limits; rate limits (HTTP 429) are honoured; a rejected webhook (401/403/404) is marked invalid until you save a new one. Mentions are disabled. The URL is stored only in a separate `secrets.json`, is never shown again, never logged, never in exports. Notification failures never fail an update.

## Scheduling

One dispatcher runs every 5 minutes (`/boot/config/plugins/unraid-autoupdater/unraid-autoupdater.cron`, registered through Unraid's `update_cron`, rebuilt at every boot). A schedule that was due while the server was off still runs if it is at most 6 hours late, otherwise it waits for the next occurrence. Newly created schedules do not run retroactively. Bi-weekly means at least 12 days between runs. One lock prevents overlapping jobs; **Stop** ends a job after the current step. A job interrupted by a reboot is recorded as *interrupted* and is not blindly resumed. Times use the server's time zone.

## Data

`/boot/config/plugins/unraid-autoupdater/`: `config.json`, `secrets.json`, `state.json`, `history.json` (bounded), `plugin-recovery/`. Log: `/var/log/unraid-autoupdater.log` (rotated, structured `key=value`). Settings can be exported/imported (without the webhook).

## Upgrading from an earlier version

Automatic at install/boot. Old `auto: true` containers become *Auto update*, others *Ignore*; schedules and notification toggles are kept. A copy is saved as `config.v1.backup.json`. A damaged config falls back to safe defaults and is kept as `config.corrupt-<time>.json`.

## Compatibility

Unraid 6.9.0+ (PHP-7.4-safe syntax). Behaviour was verified against the current Unraid web GUI source (`dockerupdate`, `update_container`, `plugin`), and by mocked tests. Not tested on a real server by the author of this change; see Limitations.

## Limitations

- The flash drive is FAT: file permissions (e.g. for `secrets.json`) cannot be enforced; the file is only readable through the root-only web GUI.
- The OS check output of Unraid's internal tool could not be verified from the public source; `plugin check unRAIDServer.plg` is used and a failed parse is reported, not hidden.
- The delay starts when the update is first *seen*, not when it was published (registries do not expose that reliably).
- No automatic rollback for containers or plugins (by design); guidance only.
- Containers not created from an Unraid template (e.g. compose) are listed but cannot be updated here.
- Appdata backups are not application-consistent.

## Troubleshooting

Activity tab shows history and the log. *Docker service is not running* banner: start Docker. An update stuck in *Waiting*: hover the status for the reason. Discord *Webhook rejected*: save a new URL.

## Removal

Remove from Plugins. Settings, history and backups stay in `/boot/config/plugins/unraid-autoupdater/` and can be deleted manually.


## Beta features

- **Update after**: in a container's settings, pick the containers that must be updated first. If one fails, its dependants are skipped.
- **Skip this version**: Docker, Plugins and Unraid OS rows have *Skip*; *Un-skip* undoes it. A newer version clears the skip.
- **Discord action links**: Discord webhooks cannot show real buttons, so messages contain links (Update now / Skip). They open the web GUI and need your Unraid login; set the web GUI address in Settings.
- **Quiet hours**: non-urgent notifications are held and sent by the next run after the window ends.
- **Rollback**: *Roll back* on a container restores the previous image. The container is then pinned and that new version skipped until you press *Unpin*. Check that your data is compatible with the older version.
- **Major-version guard**: enable *watch major* for a container (e.g. postgres). A major jump is held; use *Update anyway* to allow it.
- **Cleanup**: lists unused images and leftover containers with sizes. Nothing is removed until you select and confirm. Volumes and appdata are never touched.
- **Appearance**: dark, midnight or light theme, accent colour, custom CSS and a switch for the Dashboard tile.

### Cleanup: appdata, quarantine, templates

- **Appdata folders** lists folders in the appdata share (default `/mnt/user/appdata`, change it in Settings > Cleanup) that no container maps. Stopped containers count as users. A container that maps a whole parent folder such as `/mnt/user` is listed but does not block anything. Symbolic links and separate mount points are never offered. If Docker is not running nothing is offered.
- **Safe mode** (default): selected folders are moved into a hidden `.unraid-autoupdater-quarantine` folder inside the same share, so no space is freed until you delete them there. With Safe mode off you can delete for good after a confirmation. *Preview* shows what would happen without changing anything.
- **Quarantine**: *Restore* puts a folder back and never overwrites; if the place is taken you can restore it as `name-restored`. *Delete* removes it for good.
- **Saved templates**: Unraid keeps a template for every container you ever created. *Archive* saves a verified backup in the plugin's own folder and removes the template; *Restore* brings it back while the name is free.
- Not included (compared with Appdata Cleanup Plus): ZFS dataset handling, purge timers, exporting backups and translations.

### Cleanup extras

- **Scan everything** scans appdata, images, volumes and leftover containers in one go and shows the total. Nothing is removed by scanning. The tab badge shows what the last scan found.
- **Contents** lists what is inside an unused folder; **Never offer** hides a folder for good (undo with the ✕ next to the ignored name). Folders changed in the last 30 days are marked *recently changed*.
- **Delete quarantined folders after N days** (Settings > Cleanup, off by default) lets the scheduled run delete old quarantine entries for you. **Weekly report** sends one notification when there is something to clean up and the numbers changed; it follows your Docker notification settings and quiet hours.
- **Volumes**: unused volumes are listed with their size. Unnamed ones are usually leftovers; named ones were made on purpose. Docker refuses to remove a volume that is in use and nothing is forced.
