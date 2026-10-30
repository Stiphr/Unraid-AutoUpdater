#!/usr/bin/env python3
"""Builds unraid-swiss-manager.plg from src/ (every file is inlined, as Unraid plugins require).
Usage: python3 build.py [VERSION] [--beta]      default version = today's date, e.g. 2026.10.30 (add a letter for a second release that day: 2026.10.30a)
Also writes the redirect stubs for the former plugin names (unraid-autoupdater.plg on both branches, docker.autoupdate.plg on stable):
installs made under an old name are offered the stub, which installs this plugin (settings are carried over) and removes the old entry.
"""
import sys, re, pathlib, datetime, hashlib, base64, textwrap

root = pathlib.Path(__file__).parent
src = root / 'src'
args = [a for a in sys.argv[1:] if not a.startswith('--')]
BETA = '--beta' in sys.argv          # a beta build updates itself from the beta branch and does not touch the migration stub
version = args[0] if args else datetime.date.today().strftime('%Y.%m.%d')
unknown = [a for a in sys.argv[1:] if a.startswith('-') and a != '--beta']
if unknown or len(args) > 1 or not re.fullmatch(r'\d{4}\.\d{2}\.\d{2}[a-z]?(b\d+)?', version) or BETA != bool(re.search(r'b\d+$', version)):
    sys.exit(__doc__ + '\nNothing was built. A beta version (2026.10.30b1) needs --beta, and --beta needs a beta version.')
BRANCH = 'beta' if BETA else 'stable'
files = [
    ('UnraidSwissManager.page', '0644'), ('SwissManager.page', '0644'), ('UnraidSwissManagerDash.page', '0644'),
    ('include/page.php', '0644'), ('include/lib.php', '0644'), ('include/config.php', '0644'), ('include/schedule.php', '0644'), ('include/state.php', '0644'),
    ('include/discord.php', '0644'), ('include/backup.php', '0644'), ('include/backups.php', '0644'), ('include/docker.php', '0644'), ('include/plugins.php', '0644'),
    ('include/os.php', '0644'), ('include/workflow.php', '0644'), ('include/extras.php', '0644'), ('include/appdata.php', '0644'), ('include/remove.php', '0644'), ('include/jobs.php', '0644'), ('include/api.php', '0644'),
    ('images/unraid-swiss-manager.svg', '0644'), ('scripts/run.php', '0755'), ('assets/app.css', '0644'), ('assets/app.js', '0644'),
]
changes = (root / 'CHANGES.txt').read_text().strip()

out = ["""<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name    "unraid-swiss-manager">
<!ENTITY author  "Stiphr">
<!ENTITY version "%s">
<!ENTITY emhttp  "/usr/local/emhttp/plugins/&name;">
<!ENTITY flash   "/boot/config/plugins/&name;">
<!ENTITY pluginURL "https://raw.githubusercontent.com/Stiphr/Unraid-Swiss-Manager/refs/heads/%s/unraid-swiss-manager.plg">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" launch="Settings/UnraidSwissManager" min="6.9.0" icon="unraid-swiss-manager.png">

<CHANGES>
%s
</CHANGES>
""" % (version, BRANCH, changes)]

for rel, mode in files:
    text = (src / rel).read_text()
    assert ']]>' not in text, rel
    if rel == 'include/lib.php':
        assert '@@VERSION@@' in text
        text = text.replace('@@VERSION@@', version)
    # Unraid never overwrites an existing file on update unless a hash is given and differs, so every file carries one.
    # The plugin manager writes trim(INLINE) + newline, so hash exactly that.
    written = text.strip(" \t\n\r\0\x0b") + "\n"
    sha = hashlib.sha256(written.encode()).hexdigest()
    out.append('<FILE Name="&emhttp;/%s" Mode="%s">\n<INLINE><![CDATA[%s]]></INLINE>\n<SHA256>%s</SHA256>\n</FILE>\n' % (rel, mode, text, sha))

# One-time migration from the former plugin names (unraid-autoupdater, before that docker.autoupdate): settings, webhook, state, history
# and template backups are copied (never overwriting anything already present; the newer name first), the old runtime folder, schedule
# and run files are removed, and each old settings folder is kept as ".migrated". A job of the old plugin that is still running is
# waited for (at most 10 minutes) so nothing is copied halfway through a write.
# DAU_PLUGS / DAU_EMHTTP / DAU_RUN / DAU_LOGDIR only exist so the tests can run this block in a sandbox.
MIGRATE = """# one-time migration from the former plugin names
PLUGS="${DAU_PLUGS:-/boot/config/plugins}"
EM="${DAU_EMHTTP:-/usr/local/emhttp/plugins}"
RUN="${DAU_RUN:-/var/run}"
LOGS="${DAU_LOGDIR:-/var/log}"
NEW="$PLUGS/unraid-swiss-manager"
for OLDNAME in unraid-autoupdater docker.autoupdate; do
  OLD="$PLUGS/$OLDNAME"
  [ -d "$OLD" ] || continue
  RUNNAME=$(echo "$OLDNAME" | tr . -)
  if [ -e "$RUN/$RUNNAME.lock" ] && command -v flock > /dev/null; then flock -w 600 "$RUN/$RUNNAME.lock" true; fi
  mkdir -p "$NEW"
  cp -rn "$OLD"/. "$NEW"/ 2>/dev/null
  rm -f "$NEW/$RUNNAME.cron" "$OLD/$RUNNAME.cron"
  rm -rf "$OLD.migrated"
  mv "$OLD" "$OLD.migrated"
  rm -rf "$EM/$OLDNAME"
  rm -f "$RUN/$RUNNAME.lock" "$RUN/$RUNNAME.progress" "$RUN/$RUNNAME.stop"
  if [ -f "$LOGS/$RUNNAME.log" ] && [ ! -f "$LOGS/unraid-swiss-manager.log" ]; then mv "$LOGS/$RUNNAME.log" "$LOGS/unraid-swiss-manager.log"; fi
  if [ -x /usr/local/sbin/update_cron ]; then /usr/local/sbin/update_cron; fi
  echo "Settings of the former $OLDNAME plugin were carried over (a copy is kept in $OLD.migrated)"
done
"""
out.append('<FILE Run="/bin/bash">\n<INLINE><![CDATA[' + MIGRATE + ']]></INLINE>\n</FILE>\n')

# The SVG is the logo; the PNG is rendered from it because Unraid only accepts .png for plugin and page icons. The PNG is a binary file, so it is carried as base64 and unpacked by a script (no download needed). It is shown on the Plugins page
# (the plg "icon" attribute) and in the dashboard header. The plg is re-run at every boot, so the icon is always restored.
icon = (root / 'assets' / 'icon.png').read_bytes()
icon_b64 = '\n'.join(textwrap.wrap(base64.b64encode(icon).decode(), 76))
icon_sha = hashlib.sha256(icon).hexdigest()
out.append("""<FILE Run="/bin/bash">
<INLINE>
mkdir -p &emhttp;/images
echo '""" + icon_b64 + """' | base64 -d > /tmp/dau-icon.png 2>/dev/null
if [ "$(sha256sum /tmp/dau-icon.png | cut -d' ' -f1)" = \"""" + icon_sha + """\" ]; then mv -f /tmp/dau-icon.png &emhttp;/images/unraid-swiss-manager.png; chmod 644 &emhttp;/images/unraid-swiss-manager.png; else echo "icon check failed, skipped"; fi
rm -f /tmp/dau-icon.png
</INLINE>
</FILE>
""")

out.append("""<FILE Run="/bin/bash">
<INLINE>
mkdir -p &flash;
chmod 755 &emhttp;/scripts/run.php
# migrates settings from an older version, rebuilds the schedule and removes leftovers (runs at every boot too)
/usr/bin/php &emhttp;/scripts/run.php --install
echo ""
echo "----------------------------------------------------"
echo " Unraid Swiss Manager &version; installed"
echo " Open it from Swiss Manager in the top menu, or Settings > User Utilities > Unraid Swiss Manager"
echo "----------------------------------------------------"
echo ""
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
/usr/bin/php &emhttp;/scripts/run.php --uninstall 2>/dev/null
rm -f &flash;/unraid-swiss-manager.cron
/usr/local/sbin/update_cron 2>/dev/null
rm -rf &emhttp;
rm -f /var/run/unraid-swiss-manager.lock /var/run/unraid-swiss-manager.progress /var/run/unraid-swiss-manager.stop
echo "Unraid Swiss Manager removed. Settings, history and backups were kept in &flash;"
</INLINE>
</FILE>

</PLUGIN>
""")
(root / 'unraid-swiss-manager.plg').write_text('\n'.join(out))
print('built unraid-swiss-manager.plg', version, '(beta)' if BETA else '')

# ---- redirect stubs for the former plugin names ----
# Installed copies compare versions as text, so a stub must stay newer than the last release made under its old name (2026.11.02 / 2026.11.02b1).
STUB_VERSION = '2026.11.03'
RAW = 'https://raw.githubusercontent.com/Stiphr/Unraid-Swiss-Manager/refs/heads/'

def stub(old, branch, title):
    return """<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name    "%(old)s">
<!ENTITY author  "Stiphr">
<!ENTITY version "%(ver)s">
<!ENTITY pluginURL "%(raw)s%(branch)s/%(old)s.plg">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" launch="Settings/UnraidSwissManager" min="6.9.0" icon="refresh">

<CHANGES>
###%(ver)s
- Renamed: %(title)s is now Unraid Swiss Manager (unraid-swiss-manager). Updating installs the new plugin, keeps your settings, history and webhook, and removes this old entry.
</CHANGES>

<FILE Run="/bin/bash">
<INLINE><![CDATA[echo "%(title)s is now Unraid Swiss Manager. Installing the new plugin..."
/usr/local/sbin/plugin install %(raw)s%(branch)s/unraid-swiss-manager.plg
# this entry is registered by the update that is running right now, so it is removed a moment later
nohup setsid bash -c 'sleep 20; /usr/local/sbin/plugin remove %(old)s.plg' > /dev/null 2>&1 &
echo "The old %(old)s entry will disappear in a few seconds."
]]></INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE><![CDATA[echo "Old %(old)s entry removed. Unraid Swiss Manager and its settings are untouched."
]]></INLINE>
</FILE>

</PLUGIN>
""" % {'old': old, 'ver': STUB_VERSION, 'raw': RAW, 'branch': branch, 'title': title}

(root / 'unraid-autoupdater.plg').write_text(stub('unraid-autoupdater', BRANCH, 'Unraid AutoUpdater'))
print('built unraid-autoupdater.plg (redirect stub)', STUB_VERSION, BRANCH)
if BETA: sys.exit(0)      # the oldest name only ever existed on stable
(root / 'docker.autoupdate.plg').write_text(stub('docker.autoupdate', 'stable', 'Docker Auto Update'))
print('built docker.autoupdate.plg (redirect stub)', STUB_VERSION)
