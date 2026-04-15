<?php
// السماح لتطبيق الفلاتر بالاتصال بدون مشاكل CORS
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// استلام الكلمة والمكتبة من تطبيق الفلاتر
$query = isset($_GET['query']) ? $_GET['query'] : '';
$target = isset($_GET['target']) ? $_GET['target'] : 'loc';

if (empty($query)) {
    echo json_encode(["status" => "error", "message" => "لم يتم إرسال كلمة بحث"]);
    exit;
}

// عناوين سيرفرات Z39.50 العالمية
$z3950_servers = [
    'loc' => 'lx2.loc.gov:210/lcdb',        // مكتبة الكونغرس
    'bl'  => 'z3950.bl.uk:210/BNB03U'       // المكتبة البريطانية
];

$server = isset($z3950_servers[$target]) ? $z3950_servers[$target] : $z3950_servers['loc'];

// 1. فتح الاتصال ببروتوكول Z39.50
$id = yaz_connect($server);
if (!$id) {
    echo json_encode(["status" => "error", "message" => "فشل الاتصال بسيرفر $server"]);
    exit;
}

// 2. ضبط الصيغة وتجهيز البحث (البحث في العنوان والمؤلف)
yaz_syntax($id, "usmarc");
$cql_query = '@attr 1=4 "' . $query . '"'; // 1=4 تعني بحث شامل
yaz_search($id, "rpn", $cql_query);

// 3. انتظار الرد من السيرفر
yaz_wait();

$error = yaz_error($id);
if (!empty($error)) {
    echo json_encode(["status" => "error", "message" => "خطأ Z39.50: " . $error]);
    exit;
}

$hits = yaz_hits($id);
$records = [];

// 4. سحب النتائج (أول 15 نتيجة للسرعة)
if ($hits > 0) {
    $max = min($hits, 15);
    for ($i = 1; $i <= $max; $i++) {
        // سحب التسجيلة بصيغة XML لسهولة فك تشفيرها
        $xml_raw = yaz_record($id, $i, "xml");
        if (empty($xml_raw)) continue;

        // تنظيف الـ XML من الشوائب (Namespaces) حتى يقرأه الـ PHP بسهولة
        $xml_clean = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xml_raw);
        $xml_clean = str_replace(['marc:', 'zs:', 'z:'], '', $xml_clean);
        
        $xmlObj = @simplexml_load_string($xml_clean);
        if (!$xmlObj) continue;

        $marc_tags = [];
        $title = "بدون عنوان";
        $author = "غير معروف";
        $bibNum = "N/A";

        // استخراج حقول التحكم (001 - 008)
        if (isset($xmlObj->controlfield)) {
            foreach ($xmlObj->controlfield as $cf) {
                $tag = (string)$cf['tag'];
                $marc_tags[$tag] = (string)$cf;
                if ($tag == '001') $bibNum = (string)$cf;
            }
        }

        // استخراج حقول البيانات (100 - 999) والحقول الفرعية ($a, $c...)
        if (isset($xmlObj->datafield)) {
            foreach ($xmlObj->datafield as $df) {
                $tag = (string)$df['tag'];
                $subfields_array = [];
                
                if (isset($df->subfield)) {
                    foreach ($df->subfield as $sub) {
                        $code = (string)$sub['code'];
                        $val = (string)$sub;
                        $subfields_array[] = "\$$code $val";

                        // صيد العنوان والمؤلف للقائمة الرئيسية
                        if ($tag == '245' && $code == 'a') $title = trim(str_replace(['/', ':'], '', $val));
                        if ($tag == '100' && $code == 'a') $author = trim(str_replace([',', '.'], '', $val));
                    }
                }
                
                $dataStr = implode(" ", $subfields_array);

                if (isset($marc_tags[$tag])) {
                    if (!is_array($marc_tags[$tag])) $marc_tags[$tag] = [$marc_tags[$tag]];
                    $marc_tags[$tag][] = $dataStr;
                } else {
                    $marc_tags[$tag] = $dataStr;
                }
            }
        }

        $records[] = [
            "title" => $title,
            "author" => $author,
            "biblionumber" => $bibNum,
            "marc_tags" => $marc_tags
        ];
    }
}

// 5. إرسال البيانات الصافية لتطبيق الفلاتر
echo json_encode(["status" => "success", "total_hits" => $hits, "records" => $records]);
?>
