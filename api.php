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
    // المصدر: مكتبة جامعة الموصل (الاختراق عبر الـ BiblioNumber) 🕵️‍♂️
    // ==========================================
    $search_url = "https://centrallibrary.uomosul.edu.iq/cgi-bin/koha/opac-search.pl?idx=&q=" . $title_encoded . "&limit=&weight_search=1";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $search_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // التخفي كمتصفح لابتوب حقيقي
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml',
        'Accept-Language: ar,en-US;q=0.7,en;q=0.3',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
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
        
        // الصيد الثمين: نبحث عن الـ checkbox اللي جبته إنت
        $checkboxes = $xpath->query('//input[@name="biblionumber"]');
        
        $count = 0;
        foreach ($checkboxes as $checkbox) {
            if ($count >= 10) break; // أول 10 نتائج

            // استخراج الرقم السري للكتاب
            $bib_num = $checkbox->getAttribute('value');

            // نصعد لصف الجدول (tr) اللي بي هذا الكتاب حتى نسحب معلوماته
            $row = $xpath->query('ancestor::tr', $checkbox)->item(0);
            if (!$row) continue;

            // 1. سحب العنوان (بناءً على الصورة مالتك، العنوان موجود بـ div يحمل رقم الكتاب)
            $title_node = $xpath->query('.//div[@id="title_summary_' . $bib_num . '"]//a', $row)->item(0);
            
            // خطة طوارئ: إذا ما لكى الـ div، يدور على أي رابط داخل العمود
            if (!$title_node) {
                $title_node = $xpath->query('.//td[contains(@class, "bibliocol")]//a', $row)->item(0);
            }

            if ($title_node) {
                $book_title = trim($title_node->textContent);
                if (empty($book_title)) continue; // تجاهل الروابط الفارغة

                // 2. بناء الرابط المباشر للكتاب باستخدام الـ biblionumber
                $full_link = "https://centrallibrary.uomosul.edu.iq/cgi-bin/koha/opac-detail.pl?biblionumber=" . $bib_num;

                // 3. سحب المؤلف
                $author_node = $xpath->query('.//span[contains(@class, "author")]', $row)->item(0);
                $book_author = $author_node ? trim(strip_tags($author_node->nodeValue)) : "مؤلف غير معروف";
                $book_author = trim(str_replace(['بواسطة', ':', '|', 'تأليف'], '', $book_author));

                $all_results[] = [
                    "title" => $book_title,
                    "author" => $book_author,
                    "source" => "جامعة الموصل 📚",
                    "marc_tags" => [
                        "001" => $bib_num, // حفظ رقم الكتاب كتسجيلة مارك
                        "100" => "\$a " . $book_author,
                        "245" => "\$a " . $book_title,
                        "500" => "\$a تم الاستخراج المباشر من الفهرس.",
                        "856" => "\$u " . $full_link
                    ]
                ];
                $count++;
            }
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
