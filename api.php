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
    // المصدر: مكتبة جامعة الموصل المركزية (البحث الدقيق بالـ XPath)
    // ==========================================
    // استخدمنا الرابط الأصلي والبارامترات اللي جبتها إنت بالضبط
    $search_url = "https://centrallibrary.uomosul.edu.iq/cgi-bin/koha/opac-search.pl?idx=&q=" . $title_encoded . "&limit=&weight_search=1";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $search_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // التخفي كمتصفح حقيقي لتجاوز الحماية
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
        // إجبار النظام على قراءة الحروف العربية (UTF-8)
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // هنا سر الاختراق: بناءً على صورتك، راح نسحب كل خلية (td) تحتوي على كتاب
        $book_nodes = $xpath->query('//td[contains(@class, "bibliocol")]');
        
        $count = 0;
        foreach ($book_nodes as $node) {
            if ($count >= 10) break; // نجيب أول 10 كتب بس للسرعة

            // 1. سحب العنوان والرابط (من داخل div اللي اسمه title_summary مثل ما بين بالصورة)
            $title_node = $xpath->query('.//div[contains(@class, "title_summary")]//a', $node)->item(0);
            if (!$title_node) continue; // إذا ماكو عنوان، اعبر على البعده

            $book_title = trim($title_node->textContent);
            $href = $title_node->getAttribute('href');
            $full_link = "https://centrallibrary.uomosul.edu.iq" . (strpos($href, '/') === 0 ? $href : '/' . $href);

            // 2. سحب المؤلف (من داخل span اللي اسمه author)
            $author_node = $xpath->query('.//span[contains(@class, "author")]', $node)->item(0);
            $book_author = $author_node ? trim(strip_tags($author_node->nodeValue)) : "مؤلف غير معروف";
            // تنظيف اسم المؤلف من كلمات مثل (بواسطة، تأليف)
            $book_author = trim(str_replace(['بواسطة', ':', '|'], '', $book_author));

            // ترتيب البيانات للـ Flutter
            $all_results[] = [
                "title" => $book_title,
                "author" => $book_author,
                "source" => "مكتبة جامعة الموصل 📚",
                "marc_tags" => [
                    "100" => "\$a " . $book_author,
                    "245" => "\$a " . $book_title,
                    "500" => "\$a تم سحب التسجيلة بنجاح من الفهرس الآلي لجامعة الموصل.",
                    "856" => "\$u " . $full_link // رابط الكتاب المباشر
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
