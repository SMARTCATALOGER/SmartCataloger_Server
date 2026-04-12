<?php
// كود الأشعة السينية (X-Ray) لمعرفة ماذا يرى السيرفر بالضبط
header('Access-Control-Allow-Origin: *');
header('Content-Type: text/plain; charset=utf-8'); // نص عادي حتى نشوف الكود الخام

$search_url = "https://centrallibrary.uomosul.edu.iq/cgi-bin/koha/opac-search.pl?idx=&q=" . urlencode("تاريخ") . "&limit=&weight_search=1";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $search_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP CODE: " . $http_code . "\n\n";
echo "========= السيرفر مالتك (Render) ديشوف هذا الرد من موقع الجامعة =========\n\n";
echo $html;
?>
