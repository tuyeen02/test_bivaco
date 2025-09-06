<?php
require 'db.php';

// Hàm lấy DS cá nhân theo tháng
function getPersonalSales($pdo, $userId, $period) {
    $stmt = $pdo->prepare("
        SELECT SUM(amount) 
        FROM orders 
        WHERE user_id = ? 
          AND DATE_FORMAT(order_date, '%Y-%m') = ?
    ");
    $stmt->execute([$userId, $period]);
    return (float) $stmt->fetchColumn();
}

// Đệ quy tính tổng doanh thu cây con
function getChildSalesAndCount($pdo, $userId, $periods) {
    $salesTotal = 0;
    $childCount = 0;

    foreach ($periods as $p) {
        $salesTotal += getPersonalSales($pdo, $userId, $p);
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_id = ?");
    $stmt->execute([$userId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $childCount += count($children);

    foreach ($children as $childId) {
        list($childSales, $childSubCount) = getChildSalesAndCount($pdo, $childId, $periods);
        $salesTotal += $childSales;
        $childCount += $childSubCount;
    }

    return [$salesTotal, $childCount];
}


// Kiểm tra NPP có đạt điều kiện thưởng đồng chia tháng $period không
// Kiểm tra nhánh đạt chuẩn (ít nhất 2 nhánh >=250 triệu trong 3 tháng liên tiếp)
function checkBranches($pdo, $userId, $months) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_id = ?");
    $stmt->execute([$userId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($children) < 2) return false;

    $okBranches = 0;
    foreach ($children as $childId) {
        $branchOk = true;
        foreach ($months as $m) {
            $sales = getChildSalesAndCount($pdo, $childId, [$m])[0];
            if ($sales < 250000000) {
                $branchOk = false;
                break;
            }
        }
        if ($branchOk) $okBranches++;
    }

    return $okBranches >= 2;
}

// Kiểm tra danh hiệu & điều kiện nhận thưởng tháng $period
function checkBonusTitle($pdo, $userId, $period) {
    // Lấy trạng thái danh hiệu hiện tại
    $stmt = $pdo->prepare("SELECT has_title, fail_months FROM bonus_status WHERE user_id = ?");
    $stmt->execute([$userId]);
    $status = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['has_title'=>0, 'fail_months'=>0];

    if ($status['has_title']) {
        // Đã có danh hiệu → check tháng hiện tại
        $monthNumber = (int)date('m', strtotime($period));
        if ($monthNumber <= 2) {
            // 2 tháng đầu năm luôn chưa đạt
            $failMonths = $status['fail_months'] + 1;
            updateBonusStatus($pdo, $userId, $failMonths, 1); // vẫn giữ danh hiệu nhưng chưa đạt thưởng
            return false;
        }

        $currentSales = getPersonalSales($pdo, $userId, $period);
        if ($currentSales >= 5000000 && checkBranches($pdo, $userId, [$period])) {
            updateBonusStatus($pdo, $userId, 0, 1);
            return true;
        } else {
            $failMonths = $status['fail_months'] + 1;
            if ($failMonths >= 5) {
                updateBonusStatus($pdo, $userId, $failMonths, 0);
            } else {
                updateBonusStatus($pdo, $userId, $failMonths, 1);
            }
            return false;
        }

    } else {
        // Chưa có danh hiệu → kiểm tra toàn bộ lịch sử để tìm chuỗi 3 tháng liên tiếp đạt
        $stmt = $pdo->prepare("
            SELECT DISTINCT DATE_FORMAT(order_date, '%Y-%m') as period
            FROM orders
            WHERE user_id = ?
            ORDER BY period
        ");
        $stmt->execute([$userId]);
        $allPeriods = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $consecutiveCount = 0;
        foreach ($allPeriods as $p) {
            $sales = getPersonalSales($pdo, $userId, $p);
            if ($sales >= 5000000 && checkBranches($pdo, $userId, [$p])) {
                $consecutiveCount++;
                if ($consecutiveCount >= 3) {
                    updateBonusStatus($pdo, $userId, 0, 1); // cấp danh hiệu
                    return ($p == $period); // chỉ tháng hiện tại nhận thưởng
                }
            } else {
                $consecutiveCount = 0; // reset nếu tháng không đạt
            }
        }

        return false; // chưa đủ chuỗi 3 tháng → không đạt
    }
}

function updateBonusStatus($pdo, $userId, $failMonths, $hasTitle) {
    // Kiểm tra xem đã có bản ghi chưa
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bonus_status WHERE user_id = ?");
    $stmt->execute([$userId]);
    $exists = $stmt->fetchColumn();

    if ($exists) {
        // Cập nhật
        $stmt = $pdo->prepare("
            UPDATE bonus_status 
            SET fail_months = ?, has_title = ?
            WHERE user_id = ?
        ");
        $stmt->execute([$failMonths, $hasTitle, $userId]);
    } else {
        // Chèn mới
        $stmt = $pdo->prepare("
            INSERT INTO bonus_status(user_id, fail_months, has_title)
            VALUES(?, ?, ?)
        ");
        $stmt->execute([$userId, $failMonths, $hasTitle]);
    }
}

function getTotalSalesAllUsers($pdo, $period) {
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE DATE_FORMAT(order_date, '%Y-%m') = ?");
    $stmt->execute([$period]);
    return (float) $stmt->fetchColumn();
}
// Tính tiền thưởng 1 NPP tháng $period
function getMonthlyBonusAmount($pdo, $period) {
    $eligible = getEligibleNPP($pdo, $period);
    if(count($eligible) == 0) return 0;
    $fund = calculateBonusFund($pdo, $period);
    return $fund / count($eligible);
}

// Hàm render NPP (chỉ DS cá nhân, chưa cộng bonus)
function renderUserAllMonths($pdo, $userId, $periods, $level = 0) {
    $indent = str_repeat("&nbsp;&nbsp;&nbsp;", $level);

    $stmt = $pdo->prepare("SELECT id, name, parent_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $totals= 0; // Tổng doanh thu của người dùng hiện tại


    echo "<tr>";
    echo "<td>{$indent}{$user['name']}</td>";
    foreach ($periods as $p) {
        $sales = getPersonalSales($pdo, $userId, $p);
        $totals += $sales;
        echo "<td align='center'>" . number_format($sales) . "</td>";
    }
    
    echo "<td align='center'><b>" . number_format($totals) . "</b></td>";
    echo "</tr>";
    // Đệ quy con
    $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_id = ?");
    $stmt->execute([$userId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($children as $childId) {
        renderUserAllMonths($pdo, $childId, $periods, $level + 1);
    }

    // --- Dòng trạng thái & thưởng từng tháng ---
    if($level == 0){
        echo "<tr style='background:#e0ffe0;'>";
        echo "<td>Trạng thái & Thưởng {$user['name']}</td>";

        foreach ($periods as $p) {
            $achieve = checkBonusTitle($pdo, $userId, $p);
            $bonus = 0;
            if($achieve){
                $bonus = getMonthlyBonusAmount($pdo, $p);
            }
            echo "<td align='center'>" 
            . ($achieve ? "Đạt - " . number_format($bonus) . " VND" : "Không đạt") 
            . "</td>";
            }

        // colspan cho Tổng + Danh hiệu
        echo "<td colspan='3'></td>";
        echo "</tr>";
    }
}

// Hàm render NPP (chỉ DS cá nhân, chưa cộng bonus)
function renderUserOneMonths($pdo, $userId, $periods, $level = 0) {
    $indent = str_repeat("&nbsp;&nbsp;&nbsp;", $level);

    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $totals = 0;
    echo "<tr>";
    echo "<td>{$indent}{$user['name']}</td>";

    foreach ($periods as $p) {
        $sales = getPersonalSales($pdo, $userId, $p);
        $totals += $sales;
        echo "<td align='center'>" . number_format($sales) . "</td>";
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE parent_id = ?");
    $stmt->execute([$userId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "</tr>";

    // Đệ quy con
    foreach ($children as $childId) {
        renderUserOneMonths($pdo, $childId, $periods, $level + 1);
    }
}

// Lấy danh sách NPP đủ điều kiện thưởng tháng $period
function getEligibleNPP($pdo, $period) {
    $stmt = $pdo->query("SELECT id FROM users");
    $allUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $eligible = [];

    foreach ($allUsers as $userId) {
        if (checkBonusTitle($pdo, $userId, $period)) {
            $eligible[] = $userId;
        }
    }
    return $eligible;
}

// Tính quỹ thưởng 1% doanh thu toàn hệ thống tháng $period
function calculateBonusFund($pdo, $period) {
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM orders WHERE DATE_FORMAT(order_date, '%Y-%m') = ?");
    $stmt->execute([$period]);
    $total = (float) $stmt->fetchColumn();
    return $total * 0.01; // 1%
}


// --- Form chọn tháng ---
?>
<form method="get">
    <label>Chọn tháng:</label>
    <input type="month" name="period" value="<?= $_GET['period'] ?? date('Y-m') ?>">
    <button type="submit">Xem</button>
    <a href="http://test_bavico.local/list_user.php" class="button-link">
        <button type="button">Xem tất cả các tháng</button>
    </a>
</form>
<hr>
<?php

// --- Nếu chọn tất cả các tháng ---
if (!isset($_GET['period'])) {
    // Lấy tất cả các tháng có dữ liệu
    $stmt = $pdo->query("
        SELECT DISTINCT DATE_FORMAT(order_date, '%Y-%m') as period 
        FROM orders ORDER BY period
    ");
    $periods = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "<h2>Bảng doanh số cá nhân của tất cả các tháng</h2>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Tên NPP</th>";

    foreach ($periods as $p) {
        echo "<th>$p</th>";
    }
    echo "<th>Doanh thu</th></tr>";

    // In từ NPP gốc
    $stmt = $pdo->query("SELECT id FROM users WHERE parent_id IS NULL");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rootId) {
        renderUserAllMonths($pdo, $rootId, $periods);
    }
    // Lấy tổng doanh số toàn hệ thống tháng $p
    echo '<tr  style="background:#ffe0e0;"><td><b>Tổng doanh thu tháng<b></td>';
    $total = 0;
    foreach ($periods as $p) {
        $totalSalesAll = getTotalSalesAllUsers($pdo, $p);
        $total += $totalSalesAll;
        echo "<td align='center'><b>".number_format($totalSalesAll)."<b></td>";
    }
    echo "<td align='center'><b>".number_format($total)."<b></td>";
    echo '</tr>';
    echo "</table>";

} else {
    // --- Xem 1 tháng ---
    $period = $_GET['period'] ?? date('Y-m');
    $month = date('m', strtotime($period));
    echo "<h2>Bảng doanh số tháng $period</h2>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Tên NPP</th><th>Doanh số cá nhân tháng ".$month."</th></tr>";


    $stmt = $pdo->query("SELECT id FROM users WHERE parent_id IS NULL");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rootId) {
        renderUserOneMonths($pdo, $rootId, [$period]);
    }

    echo "</table>";
}


?>
