<?php
// السماح لتطبيق الفلاتر (الموبايل) بالاتصال بدون قيود أمنية (CORS)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : 'search';

// ==========================================
// 1. قسم البحث الشامل (Z39.50 + OAI + APIs)
// ==========================================
if ($action == 'search') {
    $title = isset($_GET['title']) ? trim($_GET['title']) : '';

    if (empty($title)) {
        echo json_encode(["status" => "error", "message" => "الرجاء إرسال عنوان الكتاب للبحث!"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $all_results = [];
    $title_encoded = urlencode($title);

    // --- المصدر الأول: Z39.50 (مكتبة الكونغرس وجامعة أكسفورد) ---
    $z_sources = [
        "مكتبة الكونغرس (LOC)" => ["host" => "lx2.loc.gov:210/LCDB", "syntax" => "USMARC"],
        "جامعة أكسفورد" => ["host" => "library.ox.ac.uk:210/44OXF_INST", "syntax" => "USMARC"]
    ];

    if (function_exists('yaz_connect')) {
        foreach ($z_sources as $name => $info) {
            $conn = yaz_connect($info['host']);
            if ($conn) {
                yaz_syntax($conn, $info['syntax']);
                yaz_range($conn, 1, 2); // سحب أول نتيجتين فقط للسرعة
                yaz_search($conn, "rpn", '@attr 1=4 "' . $title . '"');
                yaz_wait();
                
                $hits = yaz_hits($conn);
                for ($i = 1; $i <= $hits && $i <= 2; $i++) {
                    $rec = yaz_record($conn, $i, "string");
                    if ($rec) {
                        $all_results[] = [
                            "title" => parse_marc($rec, '245') ?: "عنوان غير معروف",
                            "author" => parse_marc($rec, '100') ?: "مؤلف غير معروف",
                            "source" => $name,
                            "marc_tags" => [
                                "100" => parse_marc($rec, '100') ?: "مؤلف غير معروف",
                                "245" => parse_marc($rec, '245') ?: "عنوان غير معروف",
                                "260" => parse_marc($rec, '260') ?: "غير محدد"
                            ]
                        ];
                    }
                }
                yaz_close($conn);
            }
        }
    }

    // --- المصدر الثاني: مكتبة الإسكندرية (OAI-PMH) ---
    $ba_url = "http://dar.bibalex.org/oai?verb=ListRecords&metadataPrefix=oai_dc";
    $ba_xml = @simplexml_load_file($ba_url);
    if ($ba_xml) {
        $count = 0;
        foreach ($ba_xml->ListRecords->record as $record) {
            if ($count >= 2) break; // نتيجتين فقط
            $metadata = $record->metadata->children('oai_dc', true)->children('dc', true);
            if (stripos($metadata->title, $title) !== false) {
                $all_results[] = [
                    "title" => (string)$metadata->title,
                    "author" => (string)$metadata->creator ?: "مؤلف غير معروف",
                    "source" => "مكتبة الإسكندرية",
                    "marc_tags" => [
                        "100" => (string)$metadata->creator ?: "مؤلف غير معروف",
                        "245" => (string)$metadata->title,
                        "260" => (string)$metadata->date ?: "غير محدد"
                    ]
                ];
                $count++;
            }
        }
    }

    // --- المصدر الثالث: Open Library ---
    $url_ol = "https://openlibrary.org/search.json?title=" . $title_encoded . "&limit=2";
    // استخدمنا @ لتجاهل الأخطاء إذا السيرفر الخارجي تأخر
    $response_ol = @file_get_contents($url_ol);
    if ($response_ol) {
        $data_ol = json_decode($response_ol, true);
        if (isset($data_ol['docs'])) {
            foreach ($data_ol['docs'] as $book) {
                $author = isset($book['author_name']) ? implode(", ", $book['author_name']) : "مؤلف غير معروف";
                $all_results[] = [
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

    // --- المصدر الرابع: Google Books ---
    $url_gb = "https://www.googleapis.com/books/v1/volumes?q=intitle:" . $title_encoded . "&maxResults=2";
    $response_gb = @file_get_contents($url_gb);
    if ($response_gb) {
        $data_gb = json_decode($response_gb, true);
        if (isset($data_gb['items'])) {
            foreach ($data_gb['items'] as $item) {
                $book = $item['volumeInfo'];
                $author = isset($book['authors']) ? implode(" و ", $book['authors']) : "مؤلف غير معروف";
                $all_results[] = [
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

    // إرسال النتيجة المجمعة كـ JSON
    echo json_encode([
        "status" => count($all_results) > 0 ? "success" : "no_results",
        "search_query" => $title,
        "total_results" => count($all_results),
        "data" => $all_results
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// 2. قسم حفظ التسجيلات (بانتظار قاعدة البيانات)
// ==========================================
if ($action == 'save') {
    $json_input = file_get_contents('php://input');
    $book_data = json_decode($json_input, true);

    if (!$book_data) {
        echo json_encode(["status" => "error", "message" => "لم يتم استلام أي بيانات صالحة للحفظ."], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        "status" => "success",
        "message" => "تم استقبال التسجيلة بنجاح. جاهز للربط مع MySQL.",
        "received_title" => $book_data['title'] ?? 'غير معروف'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// دالة احترافية لاستخراج وتنظيف البيانات من نصوص MARC الخام (خاصة بـ Z39.50)
// ==========================================
function parse_marc($raw, $tag) {
    // نبحث عن السطر اللي يبدأ برقم التاج (مثلاً 245)
    if (preg_match('/^' . $tag . '\s+(.*)$/m', $raw, $matches)) {
        $line = $matches[1];
        // نفصل النص بناءً على علامة الحقول الفرعية $
        $parts = explode('$', $line);
        if (count($parts) > 1) {
            array_shift($parts); // نحذف الجزء الأول (المؤشرات Indicators)
            $clean_text = '';
            foreach ($parts as $part) {
                // نأخذ النص ونتجاهل أول حرف (اللي هو رمز الحقل الفرعي a, b, c)
                $clean_text .= substr($part, 1) . ' '; 
            }
            // ننظف الفوارز والنقاط الزائدة من نهاية النص
            return trim($clean_text, " /:,."); 
        }
    }
    return null;
}
?>
