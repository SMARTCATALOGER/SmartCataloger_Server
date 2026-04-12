<?php
// السماح لتطبيق الفلاتر (الموبايل) بالاتصال بدون قيود أمنية (CORS)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : 'search';

// ==========================================
// 1. قسم البحث الشامل (SRU + OAI + APIs)
// ==========================================
if ($action == 'search') {
    $title = isset($_GET['title']) ? trim($_GET['title']) : '';

    if (empty($title)) {
        echo json_encode(["status" => "error", "message" => "الرجاء إرسال عنوان الكتاب للبحث!"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $all_results = [];
    $title_encoded = urlencode($title);

    // --- المصدر الأول: مكتبة عربية (جامعة النجاح الوطنية - نظام Koha) عبر SRU ---
    $sru_url = "https://maktaba.najah.edu/cgi-bin/koha/sru?version=1.1&operation=searchRetrieve&maximumRecords=2&query=cql.anywhere=%22" . $title_encoded . "%22";

    $ch_sru = curl_init();
    curl_setopt($ch_sru, CURLOPT_URL, $sru_url);
    curl_setopt($ch_sru, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_sru, CURLOPT_SSL_VERIFYPEER, false); // لتجاوز مشاكل شهادات الأمان
    curl_setopt($ch_sru, CURLOPT_TIMEOUT, 10);
    $sru_response = curl_exec($ch_sru);
    curl_close($ch_sru);

    if ($sru_response) {
        // تنظيف ملف XML من البادئات لضمان القراءة
        $clean_xml = str_replace(['zs:', 'srw:', 'marc:'], '', $sru_response);
        $clean_xml = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $clean_xml);
        
        $xml = @simplexml_load_string($clean_xml);
        
        if ($xml && isset($xml->records->record)) {
            foreach ($xml->records->record as $rec) {
                $marc_tags = [];
                $book_title = "عنوان غير معروف";
                $book_author = "مؤلف غير معروف";
                
                $inner_record = $rec->recordData->record;
                if (!$inner_record) continue;

                // سحب الحقول الثابتة
                if (isset($inner_record->controlfield)) {
                    foreach ($inner_record->controlfield as $cf) {
                        $tag = (string)$cf['tag'];
                        $marc_tags[$tag] = (string)$cf;
                    }
                }
                
                // سحب الحقول المتغيرة (مع استرجاع رموز الـ Subfields)
                if (isset($inner_record->datafield)) {
                    foreach ($inner_record->datafield as $df) {
                        $tag = (string)$df['tag'];
                        $field_data = "";
                        
                        if (isset($df->subfield)) {
                            foreach ($df->subfield as $sf) {
                                // سحب رمز الحقل الفرعي (a, b, c) وإضافة علامة $
                                $code = (string)$sf['code'];
                                $val = (string)$sf;
                                $field_data .= "\$$code $val  ";
                            }
                        }
                        
                        $full_text = trim($field_data);
                        
                        if (isset($marc_tags[$tag])) {
                            if (!is_array($marc_tags[$tag])) {
                                $marc_tags[$tag] = [$marc_tags[$tag]];
                            }
                            $marc_tags[$tag][] = $full_text;
                        } else {
                            $marc_tags[$tag] = $full_text;
                        }
                        
                        // تنظيف العنوان والمؤلف من علامة الـ $ لأغراض العرض في واجهة الموبايل فقط
                        $clean_for_ui = trim(preg_replace('/\$[a-z0-9]\s*/', '', $full_text), " /:,.");
                        if ($tag == '245') $book_title = $clean_for_ui;
                        if ($tag == '100' || $tag == '111' || $tag == '700') $book_author = $clean_for_ui;
                    }
                }
                
                $all_results[] = [
                    "title" => $book_title,
                    "author" => $book_author,
                    "source" => "جامعة النجاح (Koha SRU)",
                    "marc_tags" => $marc_tags
                ];
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
                        "020" => isset($book['isbn']) ? '$a ' . $book['isbn'][0] : "غير متوفر",
                        "082" => isset($book['ddc']) ? '$a ' . $book['ddc'][0] : "غير متوفر",
                        "100" => '$a ' . $author,
                        "245" => '$a ' . $book['title'],
                        "260" => isset($book['first_publish_year']) ? '$c ' . $book['first_publish_year'] : "غير محدد",
                        "650" => isset($book['subject']) ? '$a ' . implode(" | \$a ", array_slice($book['subject'], 0, 3)) : "غير متوفر"
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
                        "020" => isset($book['industryIdentifiers']) ? '$a ' . $book['industryIdentifiers'][0]['identifier'] : "غير متوفر",
                        "100" => '$a ' . $author,
                        "245" => isset($book['title']) ? '$a ' . $book['title'] : "بدون عنوان",
                        "260" => isset($book['publishedDate']) ? '$c ' . $book['publishedDate'] : "غير محدد",
                        "300" => isset($book['pageCount']) ? '$a ' . $book['pageCount'] . " p." : "غير محدد",
                        "650" => isset($book['categories']) ? '$a ' . implode(" | \$a ", $book['categories']) : "غير متوفر"
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
?>
