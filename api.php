<?php
// السماح لتطبيق الفلاتر (الموبايل) بالاتصال
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : 'search';

if ($action == 'search') {
    $title = isset($_GET['title']) ? trim($_GET['title']) : '';

    if (empty($title)) {
        echo json_encode(["status" => "error", "message" => "الرجاء إرسال عنوان الكتاب للبحث!"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $all_results = [];
    $title_encoded = urlencode($title);
    
    // ==========================================
    // المصدر: مكتبة جامعة الموصل (البحث بالبصمة الجذرية لنظام Koha)
    // ==========================================
    $search_url = "https://centrallibrary.uomosul.edu.iq/cgi-bin/koha/opac-search.pl?idx=kw&q=" . $title_encoded;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $search_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml',
        'Accept-Language: ar,en-US;q=0.7,en;q=0.3',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html && $http_code == 200) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // الطريقة المدمرة: نبحث عن أي رابط يودي لتفاصيل كتاب (biblionumber)
        $links = $xpath->query('//a[contains(@href, "biblionumber=")]');
        
        $books_dict = []; // نستخدم مصفوفة لمنع التكرار
        
        foreach ($links as $link) {
            $book_title = trim($link->textContent);
            // نأخذ الروابط التي تحتوي على نص حقيقي (ليست صور أو أزرار فارغة)
            if (!empty($book_title) && strlen($book_title) > 3) {
                $href = $link->getAttribute('href');
                if (!isset($books_dict[$href])) {
                    $books_dict[$href] = $book_title;
                }
            }
        }

        // تحويل النتائج إلى شكل MARC للموبايل
        $count = 0;
        foreach ($books_dict as $href => $book_title) {
            if ($count >= 10) break; // ناخذ 10 نتائج

            // تعديل الرابط ليكون كامل
            $full_link = "https://centrallibrary.uomosul.edu.iq" . (strpos($href, '/') === 0 ? $href : '/' . $href);

            $all_results[] = [
                "title" => $book_title,
                "author" => "مؤلف غير معروف (بحاجة لفتح التسجيلة)",
                "source" => "مكتبة جامعة الموصل 📚",
                "marc_tags" => [
                    "245" => "\$a " . $book_title,
                    "500" => "\$a تم سحب التسجيلة بنجاح من الفهرس الآلي.",
                    "856" => "\$u " . $full_link // حقل الرابط المباشر للكتاب
                ]
            ];
            $count++;
        }
    }

    echo json_encode([
        "status" => count($all_results) > 0 ? "success" : "no_results",
        "search_query" => $title,
        "total_results" => count($all_results),
        "debug_http_code" => $http_code,
        "data" => $all_results
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// 2. قسم حفظ التسجيلات
// ==========================================
if ($action == 'save') {
    $json_input = file_get_contents('php://input');
    $book_data = json_decode($json_input, true);

    if (!$book_data) {
        echo json_encode(["status" => "error", "message" => "لم يتم استلام بيانات."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        "status" => "success",
        "message" => "تم الاستقبال.",
        "received_title" => $book_data['title'] ?? 'غير معروف'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
