<?php
// السماح لتطبيق الفلاتر (الموبايل) بالاتصال بدون قيود أمنية
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
    
    // ==========================================
    // المصدر: مكتبة جامعة الموصل المركزية (طريقة الاختراق - Web Scraping)
    // ==========================================
    // نتنكر كطالب حقيقي ونستخدم رابط البحث العادي (OPAC)
    $search_url = "http://centrallibrary.uomosul.edu.iq/cgi-bin/koha/opac-search.pl?q=" . urlencode($title);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $search_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // التنكر كمتصفح جوجل كروم لتجاوز أي حماية
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36'); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $html = curl_exec($ch);
    curl_close($ch);

    // إذا نجحنا بسحب صفحة الـ HTML من المكتبة
    if ($html && stripos($html, 'class="title"') !== false) {
        // سحب العناوين (Titles) باستخدام التعابير القياسية (Regex)
        preg_match_all('/<a class="title"[^>]*>(.*?)<\/a>/is', $html, $titles);
        
        // سحب أسماء المؤلفين (Authors)
        preg_match_all('/<span class="results_summary author">.*?<a[^>]*>(.*?)<\/a>/is', $html, $authors);
        
        // سحب سنة النشر (Publisher Date)
        preg_match_all('/<span class="publisherdate">(.*?)<\/span>/is', $html, $dates);

        // نأخذ أول 5 نتائج حتى يكون التطبيق سريع
        $limit = min(count($titles[1]), 5); 

        for ($i = 0; $i < $limit; $i++) {
            $book_title = trim(strip_tags($titles[1][$i]));
            $book_author = isset($authors[1][$i]) ? trim(strip_tags($authors[1][$i])) : "مؤلف غير معروف";
            $book_pub_date = isset($dates[1][$i]) ? trim(strip_tags($dates[1][$i])) : "غير محدد";

            // بما إننا نسحب من الشاشة، ما راح نلكى حقول المارك العميقة، فراح نصنعها برمجياً لعيون الفلاتر
            $all_results[] = [
                "title" => $book_title,
                "author" => $book_author,
                "source" => "جامعة الموصل (Scraping)",
                "marc_tags" => [
                    "100" => "\$a " . $book_author,
                    "245" => "\$a " . $book_title,
                    "260" => "\$c " . $book_pub_date,
                    "500" => "\$a تم سحب هذه التسجيلة باستخدام تقنية تجريف الويب (Web Scraping) من واجهة النظام."
                ]
            ];
        }
    }

    echo json_encode([
        "status" => count($all_results) > 0 ? "success" : "no_results",
        "search_query" => $title,
        "total_results" => count($all_results),
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
