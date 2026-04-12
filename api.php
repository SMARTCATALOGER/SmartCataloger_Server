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
    // المصدر: مكتبة جامعة الموصل المركزية (النسخة الشبحية 👻)
    // ==========================================
    // استخدام بارامترات البحث الرسمية لنظام Koha
    $search_url = "https://centrallibrary.uomosul.edu.iq/cgi-bin/koha/opac-search.pl?idx=kw&q=" . $title_encoded;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $search_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // التخفي كطالب حقيقي يتصفح من العراق لتجاوز حماية الجامعة
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: ar,en-US;q=0.7,en;q=0.3',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Cache-Control: max-age=0'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // إذا تم جلب الصفحة بنجاح
    if ($html && $http_code == 200) {
        
        // استخدام DOMDocument لتحليل الـ HTML بطريقة احترافية
        $dom = new DOMDocument();
        // إخماد أخطاء الـ HTML غير القياسي
        libxml_use_internal_errors(true);
        // إجبار ترميز UTF-8 لقراءة اللغة العربية
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // البحث عن العناوين في نظام Koha (عادة تكون داخل a tag يحمل كلاس title أو p-title)
        $title_nodes = $xpath->query('//a[contains(@class, "title")]');
        
        // البحث عن المؤلفين
        $author_nodes = $xpath->query('//span[contains(@class, "author")]/a | //a[contains(@class, "author")]');

        $limit = min($title_nodes->length, 5); // أخذ أول 5 نتائج

        for ($i = 0; $i < $limit; $i++) {
            $book_title = trim($title_nodes->item($i)->textContent);
            $book_author = ($i < $author_nodes->length) ? trim($author_nodes->item($i)->textContent) : "مؤلف غير معروف";

            $all_results[] = [
                "title" => $book_title,
                "author" => $book_author,
                "source" => "مكتبة جامعة الموصل (Koha)",
                "marc_tags" => [
                    "100" => "\$a " . $book_author,
                    "245" => "\$a " . $book_title,
                    "500" => "\$a تم استخراج هذه التسجيلة بنجاح من الفهرس الآلي لجامعة الموصل."
                ]
            ];
        }
    }

    echo json_encode([
        "status" => count($all_results) > 0 ? "success" : "no_results",
        "search_query" => $title,
        "total_results" => count($all_results),
        "debug_http_code" => $http_code, // ضفتلك هذا حتى نعرف السيرفر شديجاوب
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
        echo json_encode(["status" => "error", "message" => "لم يتم استلام أي بيانات صالحة."], JSON_UNESCAPED_UNICODE);
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
