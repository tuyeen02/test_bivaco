<?php
require 'db.php';

// --------- Lấy tham số tháng ----------
// $month dạng "YYYY-MM"
$options = getopt("", ["month:"]); 
$month = $options['month'] ?? date('Y-m');

// Lấy toàn bộ NPP
$users = $pdo->query("SELECT id, name FROM users")->fetchAll(PDO::FETCH_ASSOC);

// Khởi tạo trạng thái danh hiệu từ database
$bonusStatus = [];
foreach ($users as $u) {
    $stmt = $pdo->prepare("SELECT has_title, fail_months FROM bonus_status WHERE user_id = ?");
    $stmt->execute([$u['id']]);
    $status = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['has_title'=>0, 'fail_months'=>0];
    $bonusStatus[$u['id']] = $status;
}

// Tổng doanh thu & quỹ thưởng tháng được chọn
$stmt = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE DATE_FORMAT(order_date, '%Y-%m') = ?");
$stmt->execute([$month]);
$total_sales = (float)$stmt->fetchColumn();
$fund = $total_sales * 0.01;

$qualified = [];

// --------- Kiểm tra điều kiện tháng $month ----------
foreach ($users as $user) {
    $userId = $user['id'];
    $status = $bonusStatus[$userId];

    // Kiểm tra tháng hiện tại
    $achieveCurrent = checkPersonalSales($pdo, $userId, $month) && checkBranches($pdo, $userId, $month);

    if ($status['has_title']) {
        // Đã có danh hiệu
        if ($achieveCurrent) {
            // Tháng đạt → vẫn nhận thưởng, fail_months giữ nguyên
            $bonusStatus[$userId]['has_title'] = 1;
            $qualified[] = $user;
        } else {
            // Tháng không đạt → tăng fail_months
            $bonusStatus[$userId]['fail_months']++;
            if ($bonusStatus[$userId]['fail_months'] >= 5) {
                // Mất danh hiệu
                $bonusStatus[$userId]['has_title'] = 0;
            } else {
                // vẫn giữ danh hiệu nhưng tháng này không thưởng
            }
        }
    } else {
        // Chưa có danh hiệu → kiểm tra chuỗi 3 tháng liên tiếp
        $achieve3 = checkLast3Months($pdo, $userId, $month);
        if ($achieve3) {
            $bonusStatus[$userId] = ['has_title'=>1, 'fail_months'=>0];
            $qualified[] = $user;
        }
    }
}

// Tính thưởng
$bonusEach = count($qualified) > 0 ? $fund / count($qualified) : 0;

// --------- In kết quả ----------
echo "Tháng $month\n";
echo "Tổng doanh thu: " . number_format($total_sales) . " VND\n";
echo "Quỹ thưởng 1%: " . number_format($fund) . " VND\n\n";

if (count($qualified) > 0) {
    echo "NPP nhận thưởng:\n";
    foreach ($qualified as $user) {
        echo "- {$user['name']} (ID {$user['id']}): " . number_format($bonusEach) . " VND\n";
    }
} else {
    echo "Không có NPP nào đủ điều kiện trong tháng $month\n";
}

// ----------------- HÀM HỖ TRỢ -----------------
function checkPersonalSales($pdo, $userId, $month) {
    $months = getLast3Months($month);
    foreach ($months as $m) {
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE user_id=? AND DATE_FORMAT(order_date, '%Y-%m')=?");
        $stmt->execute([$userId,$m]);
        if ((float)$stmt->fetchColumn() < 5000000) return false;
    }
    return true;
}

function checkBranches($pdo, $userId, $month) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_id=?");
    $stmt->execute([$userId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if(count($children)<2) return false;

    $months = getLast3Months($month);
    $okCount=0;
    foreach($children as $childId){
        $ok=true;
        foreach($months as $m){
            if(getBranchSales($pdo,$childId,$m)<250000000){
                $ok=false;
                break;
            }
        }
        if($ok) $okCount++;
    }
    return $okCount>=2;
}

function getBranchSales($pdo,$userId,$month){
    $stmt=$pdo->prepare("SELECT id FROM users WHERE parent_id=?");
    $stmt->execute([$userId]);
    $children=$stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt=$pdo->prepare("SELECT SUM(amount) FROM orders WHERE user_id=? AND DATE_FORMAT(order_date,'%Y-%m')=?");
    $stmt->execute([$userId,$month]);
    $sales=(float)$stmt->fetchColumn();

    foreach($children as $c){
        $sales+=getBranchSales($pdo,$c,$month);
    }
    return $sales;
}

function getLast3Months($month){
    $months=[];
    $date=DateTime::createFromFormat('Y-m',$month);
    for($i=0;$i<3;$i++){
        $months[]=$date->format('Y-m');
        $date->modify('-1 month');
    }
    return $months;
}

function checkLast3Months($pdo,$userId,$month){
    $months=getLast3Months($month);
    foreach($months as $m){
        if(!(checkPersonalSales($pdo,$userId,$m) && checkBranches($pdo,$userId,$m))) return false;
    }
    return true;
}
