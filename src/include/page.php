<?php
$dau_v = '?v=' . (int)@filemtime('/usr/local/emhttp/plugins/unraid-swiss-manager/assets/app.js');
?>
<link rel="stylesheet" href="/plugins/unraid-swiss-manager/assets/app.css<?=$dau_v?>">
<div id="dau" class="dau"><div class="dau-loading">Loading</div></div>
<script>window.DAU_IMGV = '?v=' + <?=(int)@filemtime('/usr/local/emhttp/plugins/unraid-swiss-manager/images/unraid-swiss-manager.png')?>; window.DAU_CSRF = <?=json_encode($var['csrf_token'] ?? '')?>; window.DAU_THEME = <?=json_encode((string)strtok((string)($display['theme'] ?? ''), '-'))?>;</script>
<script src="/plugins/unraid-swiss-manager/assets/app.js<?=$dau_v?>"></script>
