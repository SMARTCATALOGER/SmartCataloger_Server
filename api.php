<?php
// السماح لتطبيق الفلاتر (الموبايل) بالاتصال بدون قيود أمنية (CORS)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Content-Type: application/json; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : 'search';

// ==========================================
// 1. قسم البحث المخصص (جامعة الموصل فقط)
// ==========================================
if ($action == 'search') {
    $title = isset($_GET['title']) ? trim($_GET['title']) : '';

    if (empty($title)) {
        echo json_encode(["status" => "error", "message" => "الرجاء إرسال عنوان الكتاب للبحث!"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $all_results = [];
    $title_encoded = urlencode($title);

    // =========================================================================
    // 🔴 تنبيه هام: استبدل [MOSUL_LIBRARY_IP] بالرابط الفعلي الذي ستحصل عليه من جامعتك
    // مثال إذا أعطوك رابط:  "http://192.168.1.50/cgi-bin/koha/sru..."
    // =========================================================================
   // --- المصدر الأول: بروتوكول SRU (مكتبة جامعة الموصل المركزية) ---
    // قمنا بدمج الدومين الرسمي مع مسار SRU الخاص بنظام Koha
    $sru_url = "https://centrallibrary.uomosul.edu.iq:8080/cgi-bin/koha/sru?version=1.1&operation=searchRetrieve&maximumRecords=10&query=cql.anywhere=%22" . $title_encoded . "%22";

    $ch_sru = curl_init();
    curl_setopt($ch_sru, CURLOPT_URL, $sru_url);
    curl_setopt($ch_sru, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_sru, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch_sru, CURLOPT_TIMEOUT, 15);
    $sru_response = curl_exec($ch_sru);
    curl_close($ch_sru);

    if ($sru_response) {
        // تنظيف ملف XML من البادئات لضمان القراءة السليمة
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
                
                // سحب الحقول المتغيرة (مع الاحتفاظ بالدولارات $a, $b, $c للتفاصيل الدقيقة)
                if (isset($inner_record->datafield)) {
                    foreach ($inner_record->datafield as $df) {
                        $tag = (string)$df['tag'];
                        $field_data = "";
                        
                        if (isset($df->subfield)) {
                            foreach ($df->subfield as $sf) {
                                // سحب رمز الحقل الفرعي وإضافة علامة $
                                $code = (string)$sf['code'];
                                $val = (string)$sf;
                                $field_data .= "\$$code $val  ";
                            }
                        }
                        
                        $full_text = trim($field_data);
                        
                        // ترتيب الحقول المكررة (مثل 650)
                        if (isset($marc_tags[$tag])) {
                            if (!is_array($marc_tags[$tag])) {
                                $marc_tags[$tag] = [$marc_tags[$tag]];
                            }
                            $marc_tags[$tag][] = $full_text;
                        } else {
                            $marc_tags[$tag] = $full_text;
                        }
                        
                        // تنظيف العنوان والمؤلف للواجهة الرئيسية فقط (حذف الـ $)
                        $clean_for_ui = trim(preg_replace('/\$[a-z0-9]\s*/', '', $full_text), " /:,.");
                        if ($tag == '245') $book_title = $clean_for_ui;
                        if ($tag == '100' || $tag == '111' || $tag == '700') $book_author = $clean_for_ui;
                    }
                }
                
                $all_results[] = [
                    "title" => $book_title,
                    "author" => $book_author,
                    "source" => "مكتبة جامعة الموصل",
                    "marc_tags" => $marc_tags
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
