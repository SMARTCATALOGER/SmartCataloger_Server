<?php
// إذا الطلب جاي لـ api.php شغله، وإذا لا طلع رسالة النجاح
if (str_contains($_SERVER['REQUEST_URI'], 'api.php')) {
    require 'api.php';
} else {
    header('Content-Type: application/json');
    echo json_encode(["status" => "success", "message" => "سيرفر المفهرس الذكي شغال 100% 🚀"]);
}
