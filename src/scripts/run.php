<?php
/*
 * Unraid AutoUpdater - command line runner.
 *   --tick                    cron dispatcher: runs whatever is due (called every 5 minutes)
 *   --check                   refresh Docker/plugin/OS update information, update nothing
 *   --update-docker=a,b       update these containers now (manual: policy, delay and window are bypassed)
 *   --allow-major=a,b         with --update-docker: also allow a major-version jump for these containers
 *   --channel=stable|beta     install the plugin from that branch now (--force reinstalls the branch it is already on)
 *   --rollback=name           go back to the image before the container's last update
 *   --update-plugins=a.plg,b  update these plugins now (after the usual safety validation)
 *   --os-check                check for an Unraid OS update (notify only)
 *   --os-install[-reboot]     install the available Unraid OS update now (manual; optionally reboot afterwards); --pre-plugins=0|1 --pre-docker=0|1 override the pre-OS steps
 *   --os-confirm              continue an OS update that is waiting for its confirmation
 *   --install / --uninstall   boot-time setup / removal
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once '/usr/local/emhttp/plugins/unraid-autoupdater/include/lib.php';
set_time_limit(0);
ini_set('display_errors', '0');

$o = getopt('', ['tick', 'check', 'update-docker:', 'allow-major:', 'rollback:', 'update-plugins:', 'channel:', 'force', 'os-check', 'os-install', 'os-install-reboot', 'os-confirm', 'pre-plugins:', 'pre-docker:', 'install', 'uninstall']);
$list = function ($k) use ($o) { return isset($o[$k]) ? array_values(array_filter(array_map('trim', explode(',', (string)$o[$k])))) : []; };

if (isset($o['install']))   { dau_install(); exit(0); }
if (isset($o['uninstall'])) { dau_uninstall(); exit(0); }
if (isset($o['tick']))      exit(dau_job_main('tick'));
if (isset($o['check']))     exit(dau_job_main('check'));
if (isset($o['os-check']))  exit(dau_job_main('os'));
$flag = function ($k) use ($o) { return isset($o[$k]) ? ($o[$k] === '1') : null; };     // absent = use the setting
if (isset($o['os-confirm']))        exit(dau_job_main('os_confirm'));
if (isset($o['os-install']))        exit(dau_job_main('os_install', ['reboot' => false, 'pre_plugins' => $flag('pre-plugins'), 'pre_docker' => $flag('pre-docker')]));
if (isset($o['os-install-reboot'])) exit(dau_job_main('os_install', ['reboot' => true, 'pre_plugins' => $flag('pre-plugins'), 'pre_docker' => $flag('pre-docker')]));
if (isset($o['channel']))        { $r = dau_channel_install((string)$o['channel'], isset($o['force'])); exit(!empty($r['ok']) ? 0 : 1); }
if (isset($o['rollback']))       exit(dau_job_main('rollback', ['name' => dau_valid_name((string)$o['rollback']) ? (string)$o['rollback'] : '']));
if (isset($o['update-docker']))  exit(dau_job_main('docker',  ['names' => array_values(array_filter($list('update-docker'), 'dau_valid_name')), 'allow_major' => array_values(array_filter($list('allow-major'), 'dau_valid_name'))]));
if (isset($o['update-plugins'])) exit(dau_job_main('plugins', ['names' => array_values(array_filter($list('update-plugins'), 'dau_valid_plg'))]));
fwrite(STDERR, "usage: run.php --tick | --check | --update-docker=a,b | --update-plugins=a.plg | --os-check | --os-install | --os-install-reboot | --install | --uninstall\n");
exit(64);
