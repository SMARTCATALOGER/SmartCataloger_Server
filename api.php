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

// --- المصدر الأول: بروتوكول SRU الحديث (مكتبة الكونغرس) ---
    // نستخدم لغة الاستعلام CQL للبحث عن العنوان
    $sru_url = "http://lx2.loc.gov:210/LCDB?operation=searchRetrieve&version=1.1&query=bath.title=%22" . $title_encoded . "%22&maximumRecords=2";
    
    // سحب البيانات بصيغة XML
    $sru_response = @file_get_contents($sru_url);
    
    if ($sru_response) {
        $xml = @simplexml_load_string($sru_response);
        if ($xml) {
            // تسجيل مسارات الـ XML حتى نكدر نقرأ حقول المارك
            $xml->registerXPathNamespace('srw', 'http://www.loc.gov/zing/srw/');
            $xml->registerXPathNamespace('marc', 'http://www.loc.gov/MARC21/slim');
            
            // سحب كل التسجيلات (Records)
            $records = $xml->xpath('//srw:record/srw:recordData/marc:record');
            
            if ($records) {
                foreach ($records as $record) {
                    $marc_tags = [];
                    $book_title = "عنوان غير معروف";
                    $book_author = "مؤلف غير معروف";
                    
                    // 1. سحب الحقول الثابتة (Control Fields) مثل 001 و 008
                    foreach ($record->xpath('marc:controlfield') as $cf) {
                        $tag = (string)$cf['tag'];
                        $marc_tags[$tag] = (string)$cf;
                    }
                    
                    // 2. سحب الحقول المتغيرة (Data Fields) مثل 100, 245, 260
                    foreach ($record->xpath('marc:datafield') as $df) {
                        $tag = (string)$df['tag'];
                        $field_data = [];
                        
                        // سحب الحقول الفرعية (Subfields) مثل $a, $b, $c
                        foreach ($df->xpath('marc:subfield') as $sf) {
                            $field_data[] = (string)$sf;
                        }
                        
                        $full_text = implode(" ", $field_data);
                        $full_text = trim($full_text, " /:,.");
                        
                        // إذا الحقل مكرر (مثل 650) نسويه مصفوفة
                        if (isset($marc_tags[$tag])) {
                            if (!is_array($marc_tags[$tag])) {
                                $marc_tags[$tag] = [$marc_tags[$tag]];
                            }
                            $marc_tags[$tag][] = $full_text;
                        } else {
                            $marc_tags[$tag] = $full_text;
                        }
                        
                        // التقاط العنوان والمؤلف للعرض السريع
                        if ($tag == '245') $book_title = $full_text;
                        if ($tag == '100' || $tag == '111') $book_author = $full_text;
                    }
                    
                    $all_results[] = [
                        "title" => $book_title,
                        "author" => $book_author,
                        "source" => "مكتبة الكونغرس (SRU)",
                        "marc_tags" => $marc_tags
                    ];
                }
            }
        }
    }

    // --- المصدر الثاني: مكتبة الإسكندرية (OAI-PMH) ---
    $ba_url = "http://dar.bibalex.org/oai?verb=ListRecords&metadataPrefix=oai_dc";
    $ba_xml = @simplexml_load_file($ba_url);
    if ($ba_xml) {
        $count = 0;
        foreach ($ba_xml->ListRecords->record as $record) {
            if ($count >= 2) break;
            $metadata = $record->metadata->children('oai_dc', true)->children('dc', true);
            if (stripos($metadata->title, $title) !== false) {
                $all_results[] = [
                    "title" => (string)$metadata->title,
                    "author" => (string)$metadata->creator ?: "مؤلف غير معروف",
                    "source" => "مكتبة الإسكندرية",
                    "marc_tags" => [
                        "100" => (string)$metadata->creator,
                        "245" => (string)$metadata->title,
                        "260" => (string)$metadata->date,
                        "650" => (string)$metadata->subject
                    ]
                ];
                $count++;
            }
        }
    }

    // --- المصدر الثالث: Open Library ---
    $url_ol = "https://openlibrary.org/search.json?title=" . $title_encoded . "&limit=2";
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
                        "020" => isset($book['isbn']) ? $book['isbn'][0] : "غير متوفر",
                        "082" => isset($book['ddc']) ? $book['ddc'][0] : "غير متوفر",
                        "100" => $author,
                        "245" => $book['title'],
                        "260" => isset($book['first_publish_year']) ? $book['first_publish_year'] : "غير محدد",
                        "650" => isset($book['subject']) ? implode(" | ", array_slice($book['subject'], 0, 3)) : "غير متوفر"
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
                        "020" => isset($book['industryIdentifiers']) ? $book['industryIdentifiers'][0]['identifier'] : "غير متوفر",
                        "100" => $author,
                        "245" => isset($book['title']) ? $book['title'] : "بدون عنوان",
                        "260" => isset($book['publishedDate']) ? $book['publishedDate'] : "غير محدد",
                        "300" => isset($book['pageCount']) ? $book['pageCount'] . " p." : "غير محدد",
                        "650" => isset($book['categories']) ? implode(" | ", $book['categories']) : "غير متوفر"
                    ]
                ];
            }
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
// الدوال المساعدة لمعالجة MARC
// ==========================================

// 1. سحب **كل** الحقول من التسجيلة ووضعها في مصفوفة
function parse_all_marc_fields($raw) {
    $tags = [];
    $lines = explode("\n", $raw);
    foreach ($lines as $line) {
        if (preg_match('/^(\d{3})\s+(.*)$/', trim($line), $matches)) {
            $tag = $matches[1];
            $content = trim($matches[2]);
            
            // إذا كان الحقل مكرر (مثل 650 رؤوس الموضوعات)، نحوله إلى مصفوفة
            if (isset($tags[$tag])) {
                if (!is_array($tags[$tag])) {
                    $tags[$tag] = [$tags[$tag]];
                }
                $tags[$tag][] = $content;
            } else {
                $tags[$tag] = $content;
            }
        }
    }
    return $tags;
}

// 2. تنظيف حقل معين (للعرض السريع في الواجهة)
function clean_marc_for_display($marc_array, $tag) {
    if (!isset($marc_array[$tag])) return null;
    $val = is_array($marc_array[$tag]) ? $marc_array[$tag][0] : $marc_array[$tag];
    
    // إزالة المؤشرات والحقول الفرعية $a $c الخ
    $parts = explode('$', $val);
    if (count($parts) > 1) {
        array_shift($parts); 
        $clean = '';
        foreach($parts as $p) $clean .= substr($p, 1) . ' ';
        return trim($clean, " /:,.");
    }
    return $val;
}
?>
