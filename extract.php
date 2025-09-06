<?php
include 'simple_html_dom.php';

// --- Hàm lấy toàn bộ HTML từ URL ---
function getFullPage($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    curl_setopt($ch, CURLOPT_URL, $url);
    $htmlContent = curl_exec($ch);
    curl_close($ch);
    return $htmlContent;
}

// --- Hàm loại bỏ các phần không cần thiết ---
function cleanPageContent($htmlContent, $baseUrl) {
    $dom = new simple_html_dom();
    $dom->load($htmlContent);

    // Xóa header, footer, sidebar, nav, quảng cáo
    foreach($dom->find('header, footer, aside, nav, .ads, .advertisement, .banner, .popup, .subscribe-box, script, style, iframe, noscript, meta, link, [class*="social"],[class*="breadcrumb"],[class*="date"],[class*="footer"],
    [class*="author"],[class*="list-news"],[class*="header"],[class*="action-link"],[class*="action-link"]') as $node) {
        $node->outertext = '';
    }

    // Loại bỏ script, style, iframe, noscript
    foreach($dom->find('script, style, iframe, noscript') as $node) {
        $node->outertext = '';
    }

    // Chuyển các src ảnh relative thành absolute
    foreach ($dom->find('img, video') as $media) {
        // Nếu có lazy load, ưu tiên data-src
        if (isset($media->{'data-src'}) && !empty($media->{'data-src'})) {
            $src = $media->{'data-src'};
        } elseif (isset($media->{'data-lazy'}) && !empty($media->{'data-lazy'})) {
            $src = $media->{'data-lazy'};
        } else {
            $src = $media->src ?? '';
        }

        // Chuyển relative URL sang absolute
        if ($src && strpos($src, 'http') !== 0) {
            $src = rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
        }

        $media->src = $src;

        // Xóa style
        unset($media->style);
    }


    return $dom->save();
}

// --- Xử lý form ---
$cleanHtml = '';
if (isset($_POST['url'])) {
    $url = trim($_POST['url']);
    if (!empty($url)) {
        $html = getFullPage($url);
        // Lấy domain gốc để convert ảnh relative sang absolute
        $parsedUrl = parse_url($url);
        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        $cleanHtml = cleanPageContent($html, $baseUrl);
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Trích xuất nội dung bài viết</title>
</head>
<style>
    img{
        width: 100%;
    }
</style>
<body>
    <h2>Nhập URL bài viết để trích xuất nội dung</h2>
    <form method="post">
        <input type="text" name="url" style="width: 400px;" placeholder="https://example.com/article" required>
        <button type="submit">Trích xuất</button>
    </form>

    <?php if (!empty($cleanHtml)): ?>
        <hr>
        <h3>Nội dung đã trích xuất:</h3>
        <div style="border:1px solid #ccc; padding:10px;max-width:800px;margin: 0px auto">
            <?php echo $cleanHtml; ?>
        </div>
    <?php endif; ?>
</body>
</html>
