<?php
// السماح لتطبيق الموبايل بالاتصال (CORS)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json; charset=utf-8');

// تحديد نوع العملية (بحث أم حفظ؟)
$action = isset($_GET['action']) ? $_GET['action'] : 'search';

// ==========================================
// 1. قسم البحث الموحد (Federated Search)
// ==========================================
if ($action == 'search') {
    $title = isset($_GET['title']) ? $_GET['title'] : '';

    if (empty($title)) {
        echo json_encode(["status" => "error", "message" => "الرجاء إرسال عنوان الكتاب للبحث!"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $records = [];
    $title_encoded = urlencode($title);

    // --- المصدر الأول: Open Library ---
    $url_ol = "https://openlibrary.org/search.json?title=" . $title_encoded . "&limit=2";
    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, $url_ol);
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch1, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch1, CURLOPT_USERAGENT, 'SmartCatalogerApp/1.0');
    curl_setopt($ch1, CURLOPT_TIMEOUT, 10);
    $response_ol = curl_exec($ch1);
    curl_close($ch1);

    if ($response_ol) {
        $data_ol = json_decode($response_ol, true);
        if (isset($data_ol['docs'])) {
            foreach ($data_ol['docs'] as $book) {
                $author = isset($book['author_name']) ? implode(", ", $book['author_name']) : "مؤلف غير معروف";
                $records[] = [
                    "title" => $book['title'],
                    "author" => $author,
                    "source" => "Open Library",
                    "marc_tags" => [
                        "100" => $author,
                        "245" => $book['title'],
                        "260" => isset($book['first_publish_year']) ? $book['first_publish_year'] : "غير محدد",
                        "082" => isset($book['ddc']) ? $book['ddc'][0] : "غير متوفر"
                    ]
                ];
            }
        }
    }

    // --- المصدر الثاني: Google Books (مع تجاوز الحماية) ---
    $url_gb = "https://www.googleapis.com/books/v1/volumes?q=intitle:" . $title_encoded . "&maxResults=2";
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, $url_gb);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    $response_gb = curl_exec($ch2);
    curl_close($ch2);

    if ($response_gb) {
        $data_gb = json_decode($response_gb, true);
        if (isset($data_gb['items'])) {
            foreach ($data_gb['items'] as $item) {
                $book = $item['volumeInfo'];
                $author = isset($book['authors']) ? implode(" و ", $book['authors']) : "مؤلف غير معروف";
                $records[] = [
                    "title" => isset($book['title']) ? $book['title'] : "بدون عنوان",
                    "author" => $author,
                    "source" => "Google Books",
                    "marc_tags" => [
                        "100" => $author,
                        "245" => isset($book['title']) ? $book['title'] : "بدون عنوان",
                        "260" => isset($book['publishedDate']) ? $book['publishedDate'] : "غير محدد",
                        "300" => isset($book['pageCount']) ? $book['pageCount'] . " صفحة" : "غير محدد"
                    ]
                ];
            }
        }
    }

    // إرسال النتيجة المدمجة
    echo json_encode([
        "status" => count($records) > 0 ? "success" : "no_results",
        "search_query" => $title,
        "total_results" => count($records),
        "data" => $records
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// 2. قسم حفظ التسجيلات (سيربط بقاعدة بيانات MySQL لاحقاً)
// ==========================================
if ($action == 'save') {
    // هذا القسم سيعمل عندما يرسل تطبيق الموبايل بيانات كتاب للحفظ
    // استلام البيانات القادمة من الموبايل
    $json_input = file_get_contents('php://input');
    $book_data = json_decode($json_input, true);

    if (!$book_data) {
        echo json_encode(["status" => "error", "message" => "لم يتم استلام أي بيانات صالحة للحفظ."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // هنا سيتم كتابة كود الاتصال بقاعدة بيانات InfinityFree لإدخال التسجيلة
    // ...
    
    echo json_encode([
        "status" => "success",
        "message" => "تم استقبال التسجيلة بنجاح (سيتم تفعيل الخزن الفعلي قريباً).",
        "received_title" => $book_data['title'] ?? 'غير معروف'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// إذا تم طلب أمر غير معروف
echo json_encode(["status" => "error", "message" => "أمر غير صالح (استخدم action=search أو action=save)"], JSON_UNESCAPED_UNICODE);
?>