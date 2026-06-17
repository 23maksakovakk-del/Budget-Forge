<?php
// budget_manager.php - Бюджетный менеджер на PHP (CLI + веб)
// CLI: php budget_manager.php --cmd=add --type=income --category=Salary --amount=1500 --date=2024-01-15

$dataFile = 'budget_data.json';

function loadData() {
    global $dataFile;
    if (!file_exists($dataFile)) {
        return ['transactions' => [], 'budgets' => [], 'next_id' => 1];
    }
    $json = file_get_contents($dataFile);
    $data = json_decode($json, true);
    if (!$data) $data = ['transactions' => [], 'budgets' => [], 'next_id' => 1];
    return $data;
}

function saveData($data) {
    global $dataFile;
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function addTransaction(&$data, $type, $category, $amount, $date, $description) {
    $id = $data['next_id']++;
    $tr = ['id' => $id, 'type' => $type, 'category' => $category, 'amount' => $amount, 'date' => $date, 'description' => $description];
    $data['transactions'][] = $tr;
    saveData($data);
    return $tr;
}

function setBudget(&$data, $category, $month, $amount) {
    $data['budgets'] = array_filter($data['budgets'], function($b) use ($category, $month) {
        return !($b['category'] == $category && $b['month'] == $month);
    });
    $b = ['category' => $category, 'month' => $month, 'amount' => $amount];
    $data['budgets'][] = $b;
    saveData($data);
    return $b;
}

function getBudget($data, $category, $month) {
    foreach ($data['budgets'] as $b) {
        if ($b['category'] == $category && $b['month'] == $month) return $b['amount'];
    }
    return null;
}

function getTransactions($data, $type = null, $category = null, $dateFrom = null, $dateTo = null,
                         $minAmount = null, $maxAmount = null) {
    $result = $data['transactions'];
    if ($type) $result = array_filter($result, fn($t) => $t['type'] == $type);
    if ($category) $result = array_filter($result, fn($t) => $t['category'] == $category);
    if ($dateFrom) $result = array_filter($result, fn($t) => $t['date'] >= $dateFrom);
    if ($dateTo) $result = array_filter($result, fn($t) => $t['date'] <= $dateTo);
    if ($minAmount !== null) $result = array_filter($result, fn($t) => $t['amount'] >= $minAmount);
    if ($maxAmount !== null) $result = array_filter($result, fn($t) => $t['amount'] <= $maxAmount);
    usort($result, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $result;
}

function getStatistics($data, $month = null) {
    $filtered = $month ? getTransactions($data, null, null, $month.'-01', $month.'-31') : $data['transactions'];
    $totalIncome = array_sum(array_column(array_filter($filtered, fn($t) => $t['type'] == 'income'), 'amount'));
    $totalExpense = array_sum(array_column(array_filter($filtered, fn($t) => $t['type'] == 'expense'), 'amount'));
    $byCategory = [];
    foreach (array_filter($filtered, fn($t) => $t['type'] == 'expense') as $t) {
        $byCategory[$t['category']] = ($byCategory[$t['category']] ?? 0) + $t['amount'];
    }
    $budgetProgress = [];
    foreach ($data['budgets'] as $b) {
        if ($month && $b['month'] != $month) continue;
        $spent = $byCategory[$b['category']] ?? 0;
        $budgetProgress[$b['category']] = [
            'budget' => $b['amount'],
            'spent' => $spent,
            'remaining' => $b['amount'] - $spent,
            'percent' => $b['amount'] > 0 ? ($spent / $b['amount']) * 100 : 0
        ];
    }
    return ['totalIncome' => $totalIncome, 'totalExpense' => $totalExpense, 'balance' => $totalIncome - $totalExpense,
            'byCategory' => $byCategory, 'budgetProgress' => $budgetProgress, 'count' => count($filtered)];
}

function exportCSV($data, $file) {
    $f = fopen($file, 'w');
    fputcsv($f, ['ID', 'Type', 'Category', 'Amount', 'Date', 'Description']);
    foreach ($data['transactions'] as $t) {
        fputcsv($f, [$t['id'], $t['type'], $t['category'], $t['amount'], $t['date'], $t['description']]);
    }
    fclose($f);
}

// ========== CLI ==========
if (php_sapi_name() === 'cli') {
    $options = getopt("", ["cmd:", "type:", "category:", "amount:", "date:", "description:", "month:", "from:", "to:", "min:", "max:", "output:"]);
    $cmd = $options['cmd'] ?? null;
    $data = loadData();

    switch ($cmd) {
        case 'add':
            $type = $options['type'] ?? '';
            $category = $options['category'] ?? '';
            $amount = isset($options['amount']) ? (float)$options['amount'] : 0;
            $date = $options['date'] ?? date('Y-m-d');
            $desc = $options['description'] ?? '';
            if ($type && $category && $amount > 0) {
                $tr = addTransaction($data, $type, $category, $amount, $date, $desc);
                echo "✅ Добавлена транзакция #{$tr['id']}: {$tr['type']} {$tr['amount']} {$tr['category']} ({$tr['date']})\n";
                if ($type == 'expense') {
                    $month = substr($date, 0, 7);
                    $budget = getBudget($data, $category, $month);
                    if ($budget !== null) {
                        $spent = array_sum(array_column(getTransactions($data, 'expense', $category, $month.'-01', $month.'-31'), 'amount'));
                        if ($spent > $budget) {
                            echo "⚠️ ПРЕВЫШЕНИЕ БЮДЖЕТА! Категория $category, лимит $budget, потрачено $spent\n";
                        }
                    }
                }
            } else {
                echo "Укажите --type, --category, --amount\n";
            }
            break;
        case 'set-budget':
            $category = $options['category'] ?? '';
            $month = $options['month'] ?? date('Y-m');
            $amount = isset($options['amount']) ? (float)$options['amount'] : 0;
            if ($category && $amount > 0) {
                $b = setBudget($data, $category, $month, $amount);
                echo "✅ Бюджет для {$b['category']} на {$b['month']} установлен: {$b['amount']}\n";
            } else {
                echo "Укажите --category, --amount\n";
            }
            break;
        case 'list':
            $type = $options['type'] ?? null;
            $category = $options['category'] ?? null;
            $from = $options['from'] ?? null;
            $to = $options['to'] ?? null;
            $min = isset($options['min']) ? (float)$options['min'] : null;
            $max = isset($options['max']) ? (float)$options['max'] : null;
            $list = getTransactions($data, $type, $category, $from, $to, $min, $max);
            if (empty($list)) {
                echo "Нет записей.\n";
            } else {
                printf("%-4s %-8s %-12s %-15s %-10s %s\n", "ID", "Type", "Date", "Category", "Amount", "Description");
                foreach ($list as $t) {
                    printf("%-4d %-8s %-12s %-15s %-10.2f %s\n", $t['id'], $t['type'], $t['date'], $t['category'], $t['amount'], $t['description']);
                }
            }
            break;
        case 'stats':
            $month = $options['month'] ?? null;
            $stats = getStatistics($data, $month);
            echo "📊 Статистика " . ($month ? "за $month" : "") . "\n";
            echo "Доходы: " . number_format($stats['totalIncome'], 2) . "\n";
            echo "Расходы: " . number_format($stats['totalExpense'], 2) . "\n";
            echo "Баланс: " . number_format($stats['balance'], 2) . "\n";
            if (!empty($stats['byCategory'])) {
                echo "Расходы по категориям:\n";
                arsort($stats['byCategory']);
                foreach ($stats['byCategory'] as $cat => $amount) {
                    echo "  $cat: " . number_format($amount, 2) . "\n";
                }
            }
            if (!empty($stats['budgetProgress'])) {
                echo "Прогресс бюджета:\n";
                foreach ($stats['budgetProgress'] as $cat => $prog) {
                    echo "  $cat: " . number_format($prog['spent'], 2) . " / " . number_format($prog['budget'], 2) . " (" . number_format($prog['percent'], 1) . "%)\n";
                }
            }
            break;
        case 'export':
            $output = $options['output'] ?? null;
            if ($output) {
                exportCSV($data, $output);
                echo "Экспортировано в $output\n";
            } else {
                echo "Укажите --output\n";
            }
            break;
        default:
            interactiveMode($data);
            break;
    }
    exit;
}

// ========== ИНТЕРАКТИВНЫЙ РЕЖИМ ==========
function interactiveMode(&$data) {
    while (true) {
        echo "\n💰 Бюджетный менеджер (интерактивный)\n";
        echo "1. Добавить доход\n";
        echo "2. Добавить расход\n";
        echo "3. Установить бюджет\n";
        echo "4. Показать транзакции\n";
        echo "5. Статистика\n";
        echo "6. Экспорт CSV\n";
        echo "0. Выход\n";
        echo "Выберите действие: ";
        $choice = trim(fgets(STDIN));
        switch ($choice) {
            case '0': return;
            case '1':
            case '2':
                $type = $choice == '1' ? 'income' : 'expense';
                echo "Категория: ";
                $cat = trim(fgets(STDIN));
                if (!$cat) { echo "Категория обязательна\n"; break; }
                echo "Сумма: ";
                $amt = (float)trim(fgets(STDIN));
                if ($amt <= 0) { echo "Неверная сумма\n"; break; }
                echo "Дата (ГГГГ-ММ-ДД, Enter сегодня): ";
                $date = trim(fgets(STDIN));
                if (!$date) $date = date('Y-m-d');
                echo "Описание: ";
                $desc = trim(fgets(STDIN));
                $tr = addTransaction($data, $type, $cat, $amt, $date, $desc);
                echo "✅ Добавлена транзакция #{$tr['id']}\n";
                if ($type == 'expense') {
                    $month = substr($date, 0, 7);
                    $budget = getBudget($data, $cat, $month);
                    if ($budget !== null) {
                        $spent = array_sum(array_column(getTransactions($data, 'expense', $cat, $month.'-01', $month.'-31'), 'amount'));
                        if ($spent > $budget) {
                            echo "⚠️ ПРЕВЫШЕНИЕ БЮДЖЕТА! Категория $cat, лимит $budget, потрачено $spent\n";
                        }
                    }
                }
                break;
            case '3':
                echo "Категория: ";
                $cat = trim(fgets(STDIN));
                if (!$cat) { echo "Категория обязательна\n"; break; }
                echo "Месяц (ГГГГ-ММ, Enter текущий): ";
                $month = trim(fgets(STDIN));
                if (!$month) $month = date('Y-m');
                echo "Сумма бюджета: ";
                $amt = (float)trim(fgets(STDIN));
                if ($amt <= 0) { echo "Неверная сумма\n"; break; }
                $b = setBudget($data, $cat, $month, $amt);
                echo "✅ Бюджет для {$b['category']} на {$b['month']} установлен: {$b['amount']}\n";
                break;
            case '4':
                echo "Категория (Enter пропустить): ";
                $cat = trim(fgets(STDIN));
                if ($cat === '') $cat = null;
                echo "Тип (income/expense, Enter пропустить): ";
                $type = trim(fgets(STDIN));
                if ($type === '') $type = null;
                echo "Дата от (Enter пропустить): ";
                $from = trim(fgets(STDIN));
                if ($from === '') $from = null;
                echo "Дата до (Enter пропустить): ";
                $to = trim(fgets(STDIN));
                if ($to === '') $to = null;
                $list = getTransactions($data, $type, $cat, $from, $to);
                if (empty($list)) {
                    echo "Нет записей.\n";
                } else {
                    printf("%-4s %-8s %-12s %-15s %-10s %s\n", "ID", "Type", "Date", "Category", "Amount", "Description");
                    foreach ($list as $t) {
                        printf("%-4d %-8s %-12s %-15s %-10.2f %s\n", $t['id'], $t['type'], $t['date'], $t['category'], $t['amount'], $t['description']);
                    }
                }
                break;
            case '5':
                echo "Месяц (ГГГГ-ММ, Enter все): ";
                $month = trim(fgets(STDIN));
                if ($month === '') $month = null;
                $stats = getStatistics($data, $month);
                echo "📊 Статистика " . ($month ? "за $month" : "") . "\n";
                echo "Доходы: " . number_format($stats['totalIncome'], 2) . "\n";
                echo "Расходы: " . number_format($stats['totalExpense'], 2) . "\n";
                echo "Баланс: " . number_format($stats['balance'], 2) . "\n";
                if (!empty($stats['byCategory'])) {
                    echo "Расходы по категориям:\n";
                    arsort($stats['byCategory']);
                    foreach ($stats['byCategory'] as $cat => $amount) {
                        echo "  $cat: " . number_format($amount, 2) . "\n";
                    }
                }
                if (!empty($stats['budgetProgress'])) {
                    echo "Прогресс бюджета:\n";
                    foreach ($stats['budgetProgress'] as $cat => $prog) {
                        echo "  $cat: " . number_format($prog['spent'], 2) . " / " . number_format($prog['budget'], 2) . " (" . number_format($prog['percent'], 1) . "%)\n";
                    }
                }
                break;
            case '6':
                echo "Имя файла (CSV): ";
                $file = trim(fgets(STDIN));
                if (!$file) $file = 'export.csv';
                exportCSV($data, $file);
                echo "Экспортировано в $file\n";
                break;
            default:
                echo "Неверный выбор\n";
        }
    }
}

// ========== ВЕБ-ИНТЕРФЕЙС ==========
if (php_sapi_name() !== 'cli') {
    $data = loadData();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>💰 Бюджетный менеджер (PHP)</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #f4f7fb; margin: 20px; }
            .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background: #2c3e50; color: white; }
            .form-row { margin: 8px 0; }
            .form-row label { display: inline-block; width: 100px; }
            input, select, button { padding: 6px; margin: 2px; }
            button { background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer; }
            .stats { margin-top: 20px; }
        </style>
    </head>
    <body>
    <div class="container">
        <h1>💰 Бюджетный менеджер</h1>
        <h3>Добавить транзакцию</h3>
        <form method="GET">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <label>Тип:</label>
                <select name="type">
                    <option value="income">Доход</option>
                    <option value="expense">Расход</option>
                </select>
            </div>
            <div class="form-row"><label>Категория:</label><input type="text" name="category" required></div>
            <div class="form-row"><label>Сумма:</label><input type="number" step="0.01" name="amount" required></div>
            <div class="form-row"><label>Дата:</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-row"><label>Описание:</label><input type="text" name="description"></div>
            <button type="submit">➕ Добавить</button>
        </form>

        <h3>Установить бюджет</h3>
        <form method="GET">
            <input type="hidden" name="action" value="set-budget">
            <div class="form-row"><label>Категория:</label><input type="text" name="category" required></div>
            <div class="form-row"><label>Месяц:</label><input type="month" name="month" value="<?= date('Y-m') ?>"></div>
            <div class="form-row"><label>Сумма:</label><input type="number" step="0.01" name="amount" required></div>
            <button type="submit">Установить</button>
        </form>

        <h3>Фильтр</h3>
        <form method="GET">
            <div class="form-row"><label>Категория:</label><input type="text" name="filter_cat"></div>
            <div class="form-row"><label>Тип:</label>
                <select name="filter_type"><option value="">--</option><option value="income">Доход</option><option value="expense">Расход</option></select>
            </div>
            <div class="form-row"><label>Дата от:</label><input type="date" name="filter_from"></div>
            <div class="form-row"><label>до:</label><input type="date" name="filter_to"></div>
            <button type="submit" name="action" value="list">Применить</button>
            <a href="?">Сбросить</a>
        </form>

        <?php
        $action = $_GET['action'] ?? null;
        if ($action === 'add' && isset($_GET['category']) && isset($_GET['amount'])) {
            $type = $_GET['type'] ?? 'income';
            $cat = $_GET['category'];
            $amt = (float)$_GET['amount'];
            $date = $_GET['date'] ?? date('Y-m-d');
            $desc = $_GET['description'] ?? '';
            if ($cat && $amt > 0) {
                addTransaction($data, $type, $cat, $amt, $date, $desc);
                echo "<div class='result'>✅ Добавлена транзакция</div>";
            }
        }
        if ($action === 'set-budget' && isset($_GET['category']) && isset($_GET['amount'])) {
            $cat = $_GET['category'];
            $month = $_GET['month'] ?? date('Y-m');
            $amt = (float)$_GET['amount'];
            if ($cat && $amt > 0) {
                setBudget($data, $cat, $month, $amt);
                echo "<div class='result'>✅ Бюджет установлен</div>";
            }
        }

        $filterCat = $_GET['filter_cat'] ?? null;
        $filterType = $_GET['filter_type'] ?? null;
        $filterFrom = $_GET['filter_from'] ?? null;
        $filterTo = $_GET['filter_to'] ?? null;
        if ($filterCat === '') $filterCat = null;
        if ($filterType === '') $filterType = null;
        $list = getTransactions($data, $filterType, $filterCat, $filterFrom, $filterTo);
        if (!empty($list)) {
            echo "<h3>Транзакции</h3><table><tr><th>ID</th><th>Тип</th><th>Категория</th><th>Сумма</th><th>Дата</th><th>Описание</th></tr>";
            foreach ($list as $t) {
                echo "<tr><td>{$t['id']}</td><td>{$t['type']}</td><td>{$t['category']}</td><td>" . number_format($t['amount'], 2) . "</td><td>{$t['date']}</td><td>{$t['description']}</td></tr>";
            }
            echo "</table>";
        }

        $stats = getStatistics($data);
        echo "<div class='stats'><h3>📊 Статистика</h3>";
        echo "<p>Доходы: " . number_format($stats['totalIncome'], 2) . "</p>";
        echo "<p>Расходы: " . number_format($stats['totalExpense'], 2) . "</p>";
        echo "<p>Баланс: " . number_format($stats['balance'], 2) . "</p>";
        if (!empty($stats['byCategory'])) {
            echo "<p>Расходы по категориям:</p><ul>";
            arsort($stats['byCategory']);
            foreach ($stats['byCategory'] as $cat => $amount) {
                echo "<li>$cat: " . number_format($amount, 2) . "</li>";
            }
            echo "</ul>";
        }
        if (!empty($stats['budgetProgress'])) {
            echo "<p>Прогресс бюджета:</p><ul>";
            foreach ($stats['budgetProgress'] as $cat => $prog) {
                echo "<li>$cat: " . number_format($prog['spent'], 2) . " / " . number_format($prog['budget'], 2) . " (" . number_format($prog['percent'], 1) . "%)</li>";
            }
            echo "</ul>";
        }
        echo "</div>";

        if (isset($_GET['action']) && $_GET['action'] === 'export') {
            exportCSV($data, 'export.csv');
            echo "<div class='result'>✅ Экспортировано в export.csv</div>";
        }
        ?>
        <p><a href="?action=export">📤 Экспорт CSV</a></p>
    </div>
    </body>
    </html>
    <?php
}
