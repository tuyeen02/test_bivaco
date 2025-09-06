<?php
require 'db.php';

// --------- Lấy tham số tháng ----------
$options = getopt("", ["month:"]); 
$month = $options['month'] ?? date('Y-m');

// --------- Tính tổng doanh thu ----------
$stmt = $pdo->prepare("
    SELECT SUM(amount) FROM orders
    WHERE DATE_FORMAT(order_date, '%Y-%m') = ?
");
$stmt->execute([$month]);
$total_sales = $stmt->fetchColumn() ?: 0;
$fund = $total_sales * 0.01;

// --------- Lấy toàn bộ NPP ----------
$users = $pdo->query("SELECT id, name FROM users")->fetchAll(PDO::FETCH_ASSOC);

$qualified = [];

// --------- Kiểm tra điều kiện ----------
foreach ($users as $user) {
    $userId = $user['id'];

    if (checkPersonalSales($pdo, $userId, $month) && checkBranches($pdo, $userId, $month)) {
        $qualified[] = $user;
    }
}

// --------- In kết quả ----------
echo "Tổng doanh thu tháng $month: " . number_format($total_sales) . "\n";
echo "Quỹ thưởng (1%): " . number_format($fund) . "\n\n";

if (count($qualified) > 0) {
    $bonusEach = $fund / count($qualified);
    echo "Danh sách NPP đủ điều kiện:\n";
    foreach ($qualified as $user) {
        echo "- {$user['name']} (ID {$user['id']}): " . number_format($bonusEach) . " VND\n";
    }
} else {
    echo "Không có NPP nào đủ điều kiện trong tháng $month\n";
}

// ----------------- HÀM HỖ TRỢ -----------------

// Kiểm tra DS cá nhân ≥ 5tr trong 3 tháng liên tiếp
function checkPersonalSales($pdo, $userId, $month) {
    $months = getLast3Months($month);
    foreach ($months as $m) {
        $stmt = $pdo->prepare("
            SELECT SUM(amount) FROM orders
            WHERE user_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ?
        ");
        $stmt->execute([$userId, $m]);
        $sales = $stmt->fetchColumn() ?: 0;
        if ($sales < 5000000) return false;
    }
    return true;
}

// Kiểm tra xem NPP có ít nhất 2 chi nhánh đạt doanh số tối thiểu 3 tháng không
function checkBranches($pdo, $userId, $month) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_id = ?");
    $stmt->execute([$userId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($children) < 2) return false;

    $okCount = 0;
    foreach ($children as $childId) {
        $months = getLast3Months($month);
        $ok = true;
        foreach ($months as $m) {
            $sales = getBranchSales($pdo, $childId, $m);
            if ($sales < 250000000) { // 250 triệu
                $ok = false;
                break;
            }
        }
        if ($ok) $okCount++;
    }
    return $okCount >= 2;
}

// Tính doanh số của nhánh (bao gồm con cháu)
function getBranchSales($pdo, $userId, $month) {
    // lấy danh sách user con trực tiếp
    $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_id = ?");
    $stmt->execute([$userId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // doanh số cá nhân của node hiện tại
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE user_id = ? AND DATE_FORMAT(order_date, '%Y-%m') = ?");
    $stmt->execute([$userId, $month]);
    $sales = (int)$stmt->fetchColumn();

    // cộng thêm doanh số của con cháu
    foreach ($children as $childId) {
        $sales += getBranchSales($pdo, $childId, $month);
    }

    return $sales;
}


// Lấy 3 tháng liên tiếp: T, T-1, T-2
function getLast3Months($month) {
    $months = [];
    $date = DateTime::createFromFormat('Y-m', $month);
    for ($i = 0; $i < 3; $i++) {
        $months[] = $date->format('Y-m');
        $date->modify('-1 month');
    }
    return $months;
}
