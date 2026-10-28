<?php
/*
 * Unraid Auto Updater - command line runner.
 *   --tick                    cron dispatcher: runs whatever is due (called every 5 minutes)
 *   --check                   refresh Docker/plugin/OS update information, update nothing
 *   --update-docker=a,b       update these containers now (manual: policy, delay and window are bypassed)
 *   --update-plugins=a.plg,b  update these plugins now (after the usual safety validation)
 *   --os-check                check for an Unraid OS update (notify only)
 *   --os-install[-reboot]     install the available Unraid OS update now (manual; optionally reboot afterwards)
 *   --install / --uninstall   boot-time setup / removal
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once '/usr/local/emhttp/plugins/docker.autoupdate/include/lib.php';
set_time_limit(0);
ini_set('display_errors', '0');

$o = getopt('', ['tick', 'check', 'update-docker:', 'update-plugins:', 'os-check', 'os-install', 'os-install-reboot', 'install', 'uninstall']);
$list = function ($k) use ($o) { return isset($o[$k]) ? array_values(array_filter(array_map('trim', explode(',', (string)$o[$k])))) : []; };

if (isset($o['install']))   { dau_install(); exit(0); }
if (isset($o['uninstall'])) { dau_uninstall(); exit(0); }
if (isset($o['tick']))      exit(dau_job_main('tick'));
if (isset($o['check']))     exit(dau_job_main('check'));
if (isset($o['os-check']))  exit(dau_job_main('os'));
if (isset($o['os-install']))        exit(dau_job_main('os_install', ['reboot' => false]));
if (isset($o['os-install-reboot'])) exit(dau_job_main('os_install', ['reboot' => true]));
if (isset($o['update-docker']))  exit(dau_job_main('docker',  ['names' => array_values(array_filter($list('update-docker'), 'dau_valid_name'))]));
if (isset($o['update-plugins'])) exit(dau_job_main('plugins', ['names' => array_values(array_filter($list('update-plugins'), 'dau_valid_plg'))]));
fwrite(STDERR, "usage: run.php --tick | --check | --update-docker=a,b | --update-plugins=a.plg | --os-check | --os-install | --os-install-reboot | --install | --uninstall\n");
exit(64);
