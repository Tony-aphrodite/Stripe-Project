<?php
exec('find /var/cache/nginx -type f -delete 2>/dev/null', $o1);
exec('find /tmp/nginx -type f -delete 2>/dev/null', $o2);
exec('nginx -s reload 2>/dev/null', $o3);
echo 'Done. Cache cleared.';
?>
