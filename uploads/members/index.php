<?php
// Universal Directory Shield (Nginx, Apache, LiteSpeed, IIS)
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "403 Forbidden: Directory access is prohibited.";
exit;
