<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$query = isset($_GET['query']) ? $_GET['query'] : '';
$target = isset($_GET['target']) ? $_GET['target'] : 'loc';

if (empty($query)) {
    echo json_encode(["status" => "error", "message" => "لم يتم إرسال كلمة بحث"]);
    exit;
}

$z3950_servers = [
    'loc' => 'lx2.loc.gov:210/lcdb',
    'bl'  => 'z3950.bl.uk:210/BNB03U'
];

$server = isset($z3950_servers[$target]) ? $z3950_servers[$target] : $z3950_servers['loc'];

$id = yaz_connect($server);
if (!$id) {
    echo json_encode(["status" => "error", "message" => "فشل الاتصال بسيرفر $server"]);
    exit;
}

yaz_syntax($id, "usmarc");
$cql_query = '@attr 1=4 "' . $query . '"';
yaz_search($id, "rpn", $cql_query);
yaz_wait();

$error = yaz_error($id);
if (!empty($error)) {
    echo json_encode(["status" => "error", "message" => "خطأ Z39.50: " . $error]);
    exit;
}

$hits = yaz_hits($id);
$records = [];

if ($hits > 0) {
    $max = min($hits, 15);
    for ($i = 1; $i <= $max; $i++) {
        $xml_raw = yaz_record($id, $i, "xml");
        if (empty($xml_raw)) continue;

        $xml_clean = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $xml_raw);
        $xml_clean = str_replace(['marc:', 'zs:', 'z:'], '', $xml_clean);
        
        $xmlObj = @simplexml_load_string($xml_clean);
        if (!$xmlObj) continue;

        $marc_fields = [];
        $title = "بدون عنوان";
        $author = "غير معروف";
        $bibNum = "N/A";

        // حقول التحكم (لا تحتوي على مؤشرات أو رموز فرعية)
        if (isset($xmlObj->controlfield)) {
            foreach ($xmlObj->controlfield as $cf) {
                $tag = (string)$cf['tag'];
                $val = (string)$cf;
                $marc_fields[] = [
                    "tag" => $tag,
                    "ind1" => "",
                    "ind2" => "",
                    "subfields" => [ ["code" => "", "value" => $val] ]
                ];
                if ($tag == '001') $bibNum = $val;
            }
        }

        // حقول البيانات (تحتوي على مؤشرات ورموز فرعية)
        if (isset($xmlObj->datafield)) {
            foreach ($xmlObj->datafield as $df) {
                $tag = (string)$df['tag'];
                $ind1 = (string)$df['ind1'];
                $ind2 = (string)$df['ind2'];
                
                // تحويل الفراغ إلى رمز الشباك المكتبي #
                $ind1 = ($ind1 == ' ' || $ind1 == '') ? '#' : $ind1;
                $ind2 = ($ind2 == ' ' || $ind2 == '') ? '#' : $ind2;

                $subfields = [];
                if (isset($df->subfield)) {
                    foreach ($df->subfield as $sub) {
                        $code = (string)$sub['code'];
                        $val = (string)$sub;
                        $subfields[] = ["code" => $code, "value" => $val];

                        if ($tag == '245' && $code == 'a') $title = trim(str_replace(['/', ':'], '', $val));
                        if ($tag == '100' && $code == 'a') $author = trim(str_replace([',', '.'], '', $val));
                    }
                }
                
                $marc_fields[] = [
                    "tag" => $tag,
                    "ind1" => $ind1,
                    "ind2" => $ind2,
                    "subfields" => $subfields
                ];
            }
        }

        $records[] = [
            "title" => $title,
            "author" => $author,
            "biblionumber" => $bibNum,
            "marc_tags" => $marc_fields
        ];
    }
}

echo json_encode(["status" => "success", "total_hits" => $hits, "records" => $records]);
?>
