#!/usr/bin/env python3
"""Builds docker.autoupdate.plg from src/ (every file is inlined, as Unraid plugins require).
Usage: python3 build.py [VERSION] [BUILD]      default version = today's date, e.g. 2026.10.28b
"""
import sys, re, pathlib, datetime, hashlib, base64, textwrap

root = pathlib.Path(__file__).parent
src = root / 'src'
version = sys.argv[1] if len(sys.argv) > 1 else datetime.date.today().strftime('%Y.%m.%d')
build = sys.argv[2] if len(sys.argv) > 2 else 'v1.0.0'
files = [
    ('DockerAutoUpdate.page', '0644'),
    ('include/lib.php', '0644'), ('include/config.php', '0644'), ('include/schedule.php', '0644'), ('include/state.php', '0644'),
    ('include/discord.php', '0644'), ('include/backup.php', '0644'), ('include/docker.php', '0644'), ('include/plugins.php', '0644'),
    ('include/os.php', '0644'), ('include/workflow.php', '0644'), ('include/jobs.php', '0644'), ('include/api.php', '0644'),
    ('scripts/run.php', '0755'), ('assets/app.css', '0644'), ('assets/app.js', '0644'),
]
changes = (root / 'CHANGES.txt').read_text().strip()

out = ["""<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name    "docker.autoupdate">
<!ENTITY author  "Stiphr">
<!ENTITY version "%s">
<!ENTITY emhttp  "/usr/local/emhttp/plugins/&name;">
<!ENTITY flash   "/boot/config/plugins/&name;">
<!ENTITY pluginURL "https://raw.githubusercontent.com/Stiphr/Unraid-AutoUpdater/refs/heads/main/docker.autoupdate.plg">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" launch="Settings/DockerAutoUpdate" min="6.9.0" icon="docker.autoupdate.png">

<CHANGES>
%s
</CHANGES>
""" % (version, changes)]

for rel, mode in files:
    text = (src / rel).read_text()
    assert ']]>' not in text, rel
    if rel == 'include/lib.php':
        assert '@@VERSION@@' in text
        text = text.replace('@@VERSION@@', version).replace('@@BUILD@@', build)
    # Unraid never overwrites an existing file on update unless a hash is given and differs, so every file carries one.
    # The plugin manager writes trim(INLINE) + newline, so hash exactly that.
    written = text.strip(" \t\n\r\0\x0b") + "\n"
    sha = hashlib.sha256(written.encode()).hexdigest()
    out.append('<FILE Name="&emhttp;/%s" Mode="%s">\n<INLINE><![CDATA[%s]]></INLINE>\n<SHA256>%s</SHA256>\n</FILE>\n' % (rel, mode, text, sha))

# The icon is a binary file, so it is carried as base64 and unpacked by a script (no download needed). It is shown on the Plugins page
# (the plg "icon" attribute) and in the dashboard header. The plg is re-run at every boot, so the icon is always restored.
icon = (root / 'assets' / 'icon.png').read_bytes()
icon_b64 = '\n'.join(textwrap.wrap(base64.b64encode(icon).decode(), 76))
icon_sha = hashlib.sha256(icon).hexdigest()
out.append("""<FILE Run="/bin/bash">
<INLINE>
mkdir -p &emhttp;/images
echo '""" + icon_b64 + """' | base64 -d > /tmp/dau-icon.png 2>/dev/null
if [ "$(sha256sum /tmp/dau-icon.png | cut -d' ' -f1)" = \"""" + icon_sha + """\" ]; then mv -f /tmp/dau-icon.png &emhttp;/images/docker.autoupdate.png; chmod 644 &emhttp;/images/docker.autoupdate.png; else echo "icon check failed, skipped"; fi
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
echo " Unraid Auto Updater &version; installed"
echo " Open it from Settings > User Utilities > Unraid Auto Updater"
echo "----------------------------------------------------"
echo ""
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
/usr/bin/php &emhttp;/scripts/run.php --uninstall 2>/dev/null
rm -f &flash;/docker-autoupdate.cron
/usr/local/sbin/update_cron 2>/dev/null
rm -rf &emhttp;
rm -f /var/run/docker-autoupdate.lock /var/run/docker-autoupdate.progress /var/run/docker-autoupdate.stop
echo "Unraid Auto Updater removed. Settings, history and backups were kept in &flash;"
</INLINE>
</FILE>

</PLUGIN>
""")
(root / 'docker.autoupdate.plg').write_text('\n'.join(out))
print('built docker.autoupdate.plg', version)
