#!/usr/bin/env python3
"""Builds unraid-autoupdater.plg from src/ (every file is inlined, as Unraid plugins require).
Usage: python3 build.py [VERSION] [--beta]      default version = today's date, e.g. 2026.10.30 (add a letter for a second release that day: 2026.10.30a)
Also writes docker.autoupdate.plg: a small migration stub for installs made under the former plugin name.
"""
import sys, re, pathlib, datetime, hashlib, base64, textwrap

root = pathlib.Path(__file__).parent
src = root / 'src'
args = [a for a in sys.argv[1:] if not a.startswith('--')]
BETA = '--beta' in sys.argv          # a beta build updates itself from the beta branch and does not touch the migration stub
version = args[0] if args else datetime.date.today().strftime('%Y.%m.%d')
BRANCH = 'beta' if BETA else 'main'
files = [
    ('UnraidAutoUpdater.page', '0644'), ('UnraidAutoUpdaterDash.page', '0644'),
    ('include/lib.php', '0644'), ('include/config.php', '0644'), ('include/schedule.php', '0644'), ('include/state.php', '0644'),
    ('include/discord.php', '0644'), ('include/backup.php', '0644'), ('include/docker.php', '0644'), ('include/plugins.php', '0644'),
    ('include/os.php', '0644'), ('include/workflow.php', '0644'), ('include/extras.php', '0644'), ('include/appdata.php', '0644'), ('include/jobs.php', '0644'), ('include/api.php', '0644'),
    ('scripts/run.php', '0755'), ('assets/app.css', '0644'), ('assets/app.js', '0644'),
]
changes = (root / 'CHANGES.txt').read_text().strip()

out = ["""<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name    "unraid-autoupdater">
<!ENTITY author  "Stiphr">
<!ENTITY version "%s">
<!ENTITY emhttp  "/usr/local/emhttp/plugins/&name;">
<!ENTITY flash   "/boot/config/plugins/&name;">
<!ENTITY pluginURL "https://raw.githubusercontent.com/Stiphr/Unraid-AutoUpdater/refs/heads/%s/unraid-autoupdater.plg">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" launch="Settings/UnraidAutoUpdater" min="6.9.0" icon="unraid-autoupdater.png">

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

# One-time migration from the former plugin name "docker.autoupdate": settings, webhook, state and history are copied (never overwriting
# anything already present), the old runtime folder and schedule are removed, and the old settings folder is kept as ".migrated".
# DAU_PLUGS / DAU_EMHTTP / DAU_RUN only exist so the tests can run this block in a sandbox.
MIGRATE = """# one-time migration from the former plugin name (docker.autoupdate)
PLUGS="${DAU_PLUGS:-/boot/config/plugins}"
EM="${DAU_EMHTTP:-/usr/local/emhttp/plugins}"
RUN="${DAU_RUN:-/var/run}"
OLD="$PLUGS/docker.autoupdate"
NEW="$PLUGS/unraid-autoupdater"
if [ -d "$OLD" ]; then
  mkdir -p "$NEW"
  cp -rn "$OLD"/. "$NEW"/ 2>/dev/null
  rm -f "$NEW/docker-autoupdate.cron" "$OLD/docker-autoupdate.cron"
  rm -rf "$OLD.migrated"
  mv "$OLD" "$OLD.migrated"
  rm -rf "$EM/docker.autoupdate"
  rm -f "$RUN/docker-autoupdate.lock" "$RUN/docker-autoupdate.progress" "$RUN/docker-autoupdate.stop"
  if [ -x /usr/local/sbin/update_cron ]; then /usr/local/sbin/update_cron; fi
  echo "Settings of the former docker.autoupdate plugin were migrated (a copy is kept in $OLD.migrated)"
fi
"""
out.append('<FILE Run="/bin/bash">\n<INLINE><![CDATA[' + MIGRATE + ']]></INLINE>\n</FILE>\n')

# The icon is a binary file, so it is carried as base64 and unpacked by a script (no download needed). It is shown on the Plugins page
# (the plg "icon" attribute) and in the dashboard header. The plg is re-run at every boot, so the icon is always restored.
icon = (root / 'assets' / 'icon.png').read_bytes()
icon_b64 = '\n'.join(textwrap.wrap(base64.b64encode(icon).decode(), 76))
icon_sha = hashlib.sha256(icon).hexdigest()
out.append("""<FILE Run="/bin/bash">
<INLINE>
mkdir -p &emhttp;/images
echo '""" + icon_b64 + """' | base64 -d > /tmp/dau-icon.png 2>/dev/null
if [ "$(sha256sum /tmp/dau-icon.png | cut -d' ' -f1)" = \"""" + icon_sha + """\" ]; then mv -f /tmp/dau-icon.png &emhttp;/images/unraid-autoupdater.png; chmod 644 &emhttp;/images/unraid-autoupdater.png; else echo "icon check failed, skipped"; fi
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
echo " Unraid AutoUpdater &version; installed"
echo " Open it from Settings > User Utilities > Unraid AutoUpdater"
echo "----------------------------------------------------"
echo ""
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
/usr/bin/php &emhttp;/scripts/run.php --uninstall 2>/dev/null
rm -f &flash;/unraid-autoupdater.cron
/usr/local/sbin/update_cron 2>/dev/null
rm -rf &emhttp;
rm -f /var/run/unraid-autoupdater.lock /var/run/unraid-autoupdater.progress /var/run/unraid-autoupdater.stop
echo "Unraid AutoUpdater removed. Settings, history and backups were kept in &flash;"
</INLINE>
</FILE>

</PLUGIN>
""")
(root / 'unraid-autoupdater.plg').write_text('\n'.join(out))
print('built unraid-autoupdater.plg', version, '(beta)' if BETA else '')
if BETA: sys.exit(0)

# ---- migration stub: keeps the old plugin name updatable and moves installs over to the new one ----
NEW_URL = 'https://raw.githubusercontent.com/Stiphr/Unraid-AutoUpdater/refs/heads/main/unraid-autoupdater.plg'
stub = """<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name    "docker.autoupdate">
<!ENTITY author  "Stiphr">
<!ENTITY version "%s">
<!ENTITY pluginURL "https://raw.githubusercontent.com/Stiphr/Unraid-AutoUpdater/refs/heads/main/docker.autoupdate.plg">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" launch="Settings/UnraidAutoUpdater" min="6.9.0" icon="refresh">

<CHANGES>
###%s
- Renamed: this plugin is now called Unraid AutoUpdater (unraid-autoupdater). Updating installs the new plugin, keeps your settings, history and webhook, and removes this old entry.
</CHANGES>

<FILE Run="/bin/bash">
<INLINE><![CDATA[echo "Docker Auto Update is now Unraid AutoUpdater. Installing the new plugin..."
/usr/local/sbin/plugin install %s
# this entry is registered by the update that is running right now, so it is removed a moment later
nohup setsid bash -c 'sleep 20; /usr/local/sbin/plugin remove docker.autoupdate.plg' > /dev/null 2>&1 &
echo "The old docker.autoupdate entry will disappear in a few seconds."
]]></INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE><![CDATA[echo "Old docker.autoupdate entry removed. Unraid AutoUpdater and its settings are untouched."
]]></INLINE>
</FILE>

</PLUGIN>
""" % (version, version, NEW_URL)
(root / 'docker.autoupdate.plg').write_text(stub)
print('built docker.autoupdate.plg (migration stub)', version)
