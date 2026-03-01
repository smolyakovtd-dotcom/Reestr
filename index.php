<?php
declare(strict_types=1);

/**
 * index.php
 * Вся конфигурация (DB/SMTP/ADMIN_HASH/session/csrf/types + smtp_send_text_mail) вынесена в config.php
 */
require_once __DIR__ . '/config.php';

/* =======================
   HELPERS / VALIDATION
======================= */
function validateDocDate(string $doc_date): bool {
    $doc_date = trim($doc_date);
    return (bool)preg_match('/^\d{2}\.\d{2}\.(\d{2}|\d{4})$/', $doc_date);
}
function convertDateToYMD(string $dateStr): ?string {
    $dateStr = trim($dateStr);
    if ($dateStr === '') return null;

    if (preg_match('/^\d{2}\.\d{2}\.\d{2}$/', $dateStr)) {
        [$d, $m, $y] = explode('.', $dateStr);
        return '20' . $y . '-' . $m . '-' . $d;
    }
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $dateStr)) {
        [$d, $m, $y] = explode('.', $dateStr);
        return $y . '-' . $m . '-' . $d;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
        return $dateStr;
    }
    return null;
}
function clip1000(string $s): string {
    $s = trim($s);
    if (mb_strlen($s) > 1000) $s = mb_substr($s, 0, 1000);
    return $s;
}

/* =======================
   DB HELPERS
======================= */
function isDayFrozen(PDO $db, string $date): bool {
    $stmt = $db->prepare("SELECT MAX(frozen) FROM registry WHERE date = ? AND registry_number > 0");
    $stmt->execute([$date]);
    return (int)($stmt->fetchColumn() ?? 0) === 1;
}
function getDayRegistryInfo(PDO $db, string $date): ?array {
    $stmt = $db->prepare("SELECT registry_number, print_date, MAX(frozen) AS frozen
                          FROM registry
                          WHERE date = ? AND registry_number > 0
                          GROUP BY registry_number, print_date
                          LIMIT 1");
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getContractors(PDO $db): array {
    return $db->query("SELECT * FROM contractors ORDER BY name")->fetchAll();
}
function getDocumentTypes(PDO $db): array {
    return $db->query("SELECT * FROM document_types ORDER BY name")->fetchAll();
}

function addContractor(PDO $db, string $name, string $inn): void {
    $stmt = $db->prepare("INSERT IGNORE INTO contractors (name, inn) VALUES (?, ?)");
    $stmt->execute([trim($name), trim($inn)]);
}
function updateContractor(PDO $db, int $id, string $name, string $inn): void {
    $stmt = $db->prepare("UPDATE contractors SET name = ?, inn = ? WHERE id = ?");
    $stmt->execute([trim($name), trim($inn), $id]);
}
function deleteContractor(PDO $db, int $id): void {
    $stmt = $db->prepare("DELETE FROM contractors WHERE id = ?");
    $stmt->execute([$id]);
}

function addDocumentType(PDO $db, string $name): void {
    $stmt = $db->prepare("INSERT IGNORE INTO document_types (name) VALUES (?)");
    $stmt->execute([trim($name)]);
}
function updateDocumentType(PDO $db, int $id, string $name): void {
    $stmt = $db->prepare("UPDATE document_types SET name = ? WHERE id = ?");
    $stmt->execute([trim($name), $id]);
}
function deleteDocumentType(PDO $db, int $id): void {
    $stmt = $db->prepare("DELETE FROM document_types WHERE id = ?");
    $stmt->execute([$id]);
}

function addToRegistry(
    PDO $db,
    string $date,
    string $type,
    string $doc_date,
    string $contractor,
    string $inn,
    string $doc_name,
    string $doc_num,
    string $tk_num
): array {
    $doc_date = trim($doc_date);
    $contractor = trim($contractor);
    $inn = trim($inn);
    $doc_name = trim($doc_name);
    $doc_num = trim($doc_num);
    $tk_num = trim($tk_num);

    if ($doc_num === '' || $contractor === '' || $inn === '' || $doc_name === '' || $doc_date === '') {
        return ['success' => false, 'message' => 'Заполните все обязательные поля!'];
    }
    if (!validateDocDate($doc_date)) {
        return ['success' => false, 'message' => 'Неверный формат даты документа! Используйте ДД.ММ.ГГ или ДД.ММ.ГГГГ'];
    }
    if (!preg_match('/^\d{10}(\d{2})?$/', $inn)) {
        return ['success' => false, 'message' => 'ИНН должен содержать 10 или 12 цифр'];
    }

    try {
        $stmt = $db->prepare("INSERT INTO registry (date, type, doc_date, contractor, inn, doc_name, doc_num, tk_num)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$date, $type, $doc_date, $contractor, $inn, $doc_name, $doc_num, $tk_num]);
        return ['success' => true, 'message' => 'Добавлено!'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Ошибка базы данных: ' . $e->getMessage()];
    }
}

function getRegistryForDate(PDO $db, string $date): array {
    $stmt = $db->prepare("SELECT * FROM registry WHERE date = ? ORDER BY type, id");
    $stmt->execute([$date]);
    return $stmt->fetchAll();
}
function getRegistryForRange(PDO $db, string $from, string $to): array {
    $stmt = $db->prepare("SELECT * FROM registry WHERE date BETWEEN ? AND ? ORDER BY date, type, id");
    $stmt->execute([$from, $to]);
    return $stmt->fetchAll();
}
function getRegistryNumberForDate(PDO $db, string $date): ?array {
    $stmt = $db->prepare("SELECT registry_number, print_date, frozen
                          FROM registry
                          WHERE date = ? AND registry_number > 0
                          LIMIT 1");
    $stmt->execute([$date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function assignRegistryNumber(PDO $db, array $dates): array {
    $lockName = 'registry_assign_lock';
    $stmtLock = $db->prepare("SELECT GET_LOCK(?, 10)");
    $stmtLock->execute([$lockName]);
    $got = (int)$stmtLock->fetchColumn();
    if ($got !== 1) {
        return ['success' => false, 'message' => 'Не удалось получить блокировку. Попробуйте ещё раз.'];
    }

    try {
        $db->beginTransaction();

        $occupied = [];
        foreach ($dates as $date) {
            $existing = getRegistryNumberForDate($db, $date);
            if ($existing) $occupied[$date] = (int)$existing['registry_number'];
        }
        if (!empty($occupied)) {
            $db->rollBack();
            return ['success' => false, 'occupied' => $occupied];
        }

        $stmt = $db->query("SELECT MAX(registry_number) AS max_num FROM registry FOR UPDATE");
        $max = (int)($stmt->fetch()['max_num'] ?? 0);
        $registry_number = $max + 1;
        $print_date = date('d.m.y');

        $stmtUp = $db->prepare("UPDATE registry SET registry_number = ?, print_date = ? WHERE date = ?");
        $updatedTotal = 0;
        foreach ($dates as $date) {
            $stmtUp->execute([$registry_number, $print_date, $date]);
            $updatedTotal += $stmtUp->rowCount();
        }
        if ($updatedTotal === 0) {
            $db->rollBack();
            return ['success' => false, 'message' => 'В выбранном периоде нет записей. Печать невозможна.'];
        }

        $db->commit();
        return ['success' => true, 'registry_number' => $registry_number, 'print_date' => $print_date];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => 'Ошибка присвоения номера: ' . $e->getMessage()];
    } finally {
        $stmtUnlock = $db->prepare("SELECT RELEASE_LOCK(?)");
        $stmtUnlock->execute([$lockName]);
    }
}

function clearRegistryNumber(PDO $db, int $registry_number): void {
    $stmt = $db->prepare("UPDATE registry SET registry_number = 0, print_date = '', frozen = 0 WHERE registry_number = ?");
    $stmt->execute([$registry_number]);
}
function freezeReestr(PDO $db, int $registry_number): void {
    $stmt = $db->prepare("UPDATE registry SET frozen = 1 WHERE registry_number = ?");
    $stmt->execute([$registry_number]);
}
function unfreezeReestr(PDO $db, int $registry_number): void {
    $stmt = $db->prepare("UPDATE registry SET frozen = 0 WHERE registry_number = ?");
    $stmt->execute([$registry_number]);
}

function getReestrsList(PDO $db): array {
    $stmt = $db->query("SELECT registry_number, print_date,
                               GROUP_CONCAT(DISTINCT date) as dates,
                               COUNT(*) as count,
                               MAX(frozen) AS is_frozen
                        FROM registry
                        WHERE registry_number > 0
                        GROUP BY registry_number
                        ORDER BY registry_number DESC");
    return $stmt->fetchAll();
}

function deleteFromRegistry(PDO $db, int $id): bool {
    $stmt = $db->prepare("SELECT date FROM registry WHERE id = ?");
    $stmt->execute([$id]);
    $date = (string)($stmt->fetchColumn() ?? '');
    if ($date && isDayFrozen($db, $date)) return false;

    $stmt = $db->prepare("DELETE FROM registry WHERE id = ?");
    $stmt->execute([$id]);
    return true;
}

/**
 * UPDATE записи (редактирование из "Действия")
 */
function updateRegistryRow(PDO $db, int $id, array $payload): array {
    $stmt = $db->prepare("SELECT * FROM registry WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return ['success' => false, 'message' => 'Запись не найдена'];

    $date = (string)$row['date'];
    if ($date && isDayFrozen($db, $date)) {
        return ['success' => false, 'message' => 'День заморожен. Редактирование запрещено.'];
    }

    $contractor = trim((string)($payload['contractor'] ?? ''));
    $inn        = trim((string)($payload['inn'] ?? ''));
    $doc_name   = trim((string)($payload['doc_name'] ?? ''));
    $doc_num    = trim((string)($payload['doc_num'] ?? ''));
    $doc_date   = trim((string)($payload['doc_date'] ?? ''));
    $tk_num     = trim((string)($payload['tk_num'] ?? ''));

    if ($doc_num === '' || $contractor === '' || $inn === '' || $doc_name === '' || $doc_date === '') {
        return ['success' => false, 'message' => 'Заполните все обязательные поля!'];
    }
    if (!validateDocDate($doc_date)) {
        return ['success' => false, 'message' => 'Неверный формат даты документа!'];
    }
    if (!preg_match('/^\d{10}(\d{2})?$/', $inn)) {
        return ['success' => false, 'message' => 'ИНН должен содержать 10 или 12 цифр'];
    }

    $stmtUp = $db->prepare("UPDATE registry
                            SET contractor = ?, inn = ?, doc_name = ?, doc_num = ?, doc_date = ?, tk_num = ?
                            WHERE id = ?");
    $stmtUp->execute([$contractor, $inn, $doc_name, $doc_num, $doc_date, $tk_num, $id]);

    return ['success' => true, 'message' => 'Сохранено'];
}

/**
 * UPDATE COMMENTS (всегда можно)
 * common — всем
 * sklad — только залогиненным
 */
function updateRegistryComment(PDO $db, int $id, string $field, string $value): array {
    if (!in_array($field, ['comment_public', 'comment_warehouse'], true)) {
        return ['success' => false, 'message' => 'Неверное поле'];
    }
    $value = clip1000($value);

    $stmt = $db->prepare("UPDATE registry SET {$field} = ? WHERE id = ?");
    $stmt->execute([$value, $id]);
    return ['success' => true, 'message' => 'OK', 'value' => $value];
}

function searchByContractor(PDO $db, string $contractor, ?string $from = null, ?string $to = null): array {
    $sql = "SELECT * FROM registry WHERE contractor LIKE ? ";
    $params = ['%' . $contractor . '%'];

    if ($from && $to) {
        $sql .= "AND date BETWEEN ? AND ? ";
        $params[] = $from;
        $params[] = $to;
    } elseif ($from) {
        $sql .= "AND date >= ? ";
        $params[] = $from;
    } elseif ($to) {
        $sql .= "AND date <= ? ";
        $params[] = $to;
    }

    $sql .= "ORDER BY date DESC, id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Текст для email по номеру реестра
 */
function buildReestrEmailText(PDO $db, int $registryNumber, array $types): array {
    $stmt = $db->prepare("SELECT * FROM registry WHERE registry_number = ? ORDER BY date, type, id");
    $stmt->execute([$registryNumber]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        return ['success' => false, 'message' => 'Записей по этому реестру не найдено.'];
    }

    $print_date = $rows[0]['print_date'] ?? '';
    $dates = array_values(array_unique(array_map(fn($r) => $r['date'], $rows)));
    sort($dates);
    $from = $dates[0];
    $to = $dates[count($dates)-1];

    $text = "Реестр передачи документов №{$registryNumber} от {$print_date}\n";
    $text .= "Период: " . date('d.m.y', strtotime($from)) . " — " . date('d.m.y', strtotime($to)) . "\n\n";

    $byDay = [];
    foreach ($rows as $r) $byDay[$r['date']][] = $r;

    foreach ($dates as $day) {
        $text .= "=== За " . date('d.m.y', strtotime($day)) . " ===\n";
        $dayRows = $byDay[$day] ?? [];

        foreach ($types as $typeKey => $typeName) {
            $filtered = array_values(array_filter($dayRows, fn($x) => $x['type'] === $typeKey));
            if (empty($filtered)) continue;

            $text .= "\n{$typeName}:\n";
            $i = 1;
            foreach ($filtered as $doc) {
                $line = "{$i}. {$doc['contractor']} (ИНН {$doc['inn']}) - {$doc['doc_name']} №{$doc['doc_num']} от {$doc['doc_date']}";
                if (strpos((string)$typeKey, '_tk') !== false && !empty($doc['tk_num'])) {
                    $line .= ", №ТК {$doc['tk_num']}";
                }
                $text .= $line . "\n";
                $i++;
            }
        }
        $text .= "\n";
    }

    return ['success' => true, 'subject' => "Реестр №{$registryNumber}", 'text' => $text];
}

/* =======================
   AUTH
======================= */
$isLoggedIn = !empty($_SESSION['logged_in']);

if (isset($_GET['logout'])) {
    unset($_SESSION['logged_in']);
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/* =======================
   PRINT MODE
======================= */
if (isset($_POST['print_mode']) && $_POST['print_mode'] === '1') {
    require_csrf();

    $from = convertDateToYMD($_POST['from'] ?? '');
    $to   = convertDateToYMD($_POST['to'] ?? '');
    if (!$from || !$to) {
        echo '<script>alert("Неверный формат дат"); history.back();</script>';
        exit;
    }

    $days = [];
    $cur = strtotime($from);
    $end = strtotime($to);
    while ($cur <= $end) {
        $days[] = date('Y-m-d', $cur);
        $cur = strtotime('+1 day', $cur);
    }

    $records = getRegistryForRange($db, $from, $to);
    if (empty($records)) {
        echo '<script>alert("В выбранном периоде нет записей. Печать невозможна."); history.back();</script>';
        exit;
    }

    $dayMap = [];
    foreach ($records as $r) {
        if (!isset($dayMap[$r['date']])) $dayMap[$r['date']] = 0;
        if ((int)$r['registry_number'] > 0) $dayMap[$r['date']] = (int)$r['registry_number'];
    }

    $used = [];
    $free = [];
    foreach ($days as $day) {
        if (!empty($dayMap[$day])) $used[$dayMap[$day]][] = $day;
        else $free[] = $day;
    }

    if (count($used) > 1) {
        $msg = [];
        foreach ($used as $num => $ds) {
            $msg[] = "дни " . implode(', ', array_map(fn($d) => date('d.m.y', strtotime($d)), $ds)) . " → реестр №$num";
        }
        echo '<script>alert("В диапазоне разные реестры:\\n' . implode("\\n", $msg) . '"); history.back();</script>';
        exit;
    }

    if (count($used) === 1 && count($free) > 0) {
        $num = array_key_first($used);
        $datesStr = implode(', ', array_map(fn($d) => date('d.m.y', strtotime($d)), $used[$num]));
        echo '<script>alert("Дни ' . $datesStr . ' уже принадлежат реестру №' . $num . '. Исправьте диапазон."); history.back();</script>';
        exit;
    }

    if (count($used) === 1 && count($free) === 0) {
        $registry_number = array_key_first($used);
        $print_date = getRegistryNumberForDate($db, $days[0])['print_date'] ?? date('d.m.y');
    } else {
        $assign = assignRegistryNumber($db, $days);
        if (empty($assign['success'])) {
            $m = $assign['message'] ?? 'Не удалось назначить реестр';
            echo '<script>alert("' . addslashes($m) . '"); history.back();</script>';
            exit;
        }
        $registry_number = $assign['registry_number'];
        $print_date = $assign['print_date'];
    }

    $stmt = $db->prepare("UPDATE registry SET frozen = 1 WHERE registry_number = ?");
    $stmt->execute([(int)$registry_number]);
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title>Реестр №<?= (int)$registry_number ?></title>
        <style>
            @media print { body { margin: 15mm; } .no-print { display: none; } }
            body { font-family: "Arial", sans-serif; font-size: 11pt; line-height: 1.2; }
            h1 { text-align: center; font-size: 16pt; margin-bottom: 5mm; }
            h2 { font-size: 13pt; margin: 8mm 0 2mm; border-bottom: 1px solid #000; padding-bottom: 2mm; }
            h3 { font-size: 12pt; margin: 6mm 0 1mm; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
            th, td { border: 1px solid #000; padding: 3px 5px; font-size: 10pt; }
            th { background: #f0f0f0; font-weight: bold; }
            .period { text-align: center; font-size: 12pt; margin-bottom: 8mm; }
            .empty { text-align: center; color: #666; font-style: italic; }
        </style>
    </head>
    <body>
        <div class="no-print" style="text-align:right; margin-bottom:10px;">
            <button onclick="window.print()" style="padding:8px 16px; font-size:14px;">Печать</button>
            <button onclick="history.back()" style="padding:8px 16px; font-size:14px;">Вернуться</button>
        </div>

        <h1>Реестр передачи документов</h1>
        <div class="period">
            за период <?=date('d.m.y', strtotime($from))?> — <?=date('d.m.y', strtotime($to))?>
            <br>Реестр №<?= (int)$registry_number ?> от <?= htmlspecialchars((string)$print_date) ?>
        </div>

        <?php
        $grouped = [];
        foreach ($records as $r) $grouped[$r['date']][] = $r;

        foreach ($days as $day):
        ?>
            <h2>За <?=date('d.m.y', strtotime($day))?></h2>
            <?php
            $dayRecords = $grouped[$day] ?? [];
            if (empty($dayRecords)) { echo '<p class="empty">Документов нет</p>'; continue; }

            foreach ($types as $typeKey => $typeName):
                $filtered = array_filter($dayRecords, fn($item) => $item['type'] === $typeKey);
                if (empty($filtered)) continue;
            ?>
                <h3><?= htmlspecialchars((string)$typeName) ?></h3>
                <table>
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>Контрагент</th>
                            <th>ИНН</th>
                            <th>Документ</th>
                            <th>№</th>
                            <th>Дата</th>
                            <?php if (strpos((string)$typeKey, '_tk') !== false): ?><th>ТК</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($filtered as $doc): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars((string)$doc['contractor']) ?></td>
                            <td><?= htmlspecialchars((string)$doc['inn']) ?></td>
                            <td><?= htmlspecialchars((string)$doc['doc_name']) ?></td>
                            <td><?= htmlspecialchars((string)$doc['doc_num']) ?></td>
                            <td><?= htmlspecialchars((string)$doc['doc_date']) ?></td>
                            <?php if (strpos((string)$typeKey, '_tk') !== false): ?>
                                <td><?= htmlspecialchars((string)($doc['tk_num'] ?? '')) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <script>window.print();</script>
    </body>
    </html>
    <?php
    exit;
}

/* =======================
   POST ACTIONS
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        require_csrf();
        $pass = (string)($_POST['credential'] ?? '');

        if (password_verify($pass, ADMIN_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
        } else {
            set_alert('Неверный пароль');
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // UPDATE COMMENTS (AJAX) — ВСЕГДА РАЗРЕШЕНО ДЛЯ common, а sklad только залогиненым
    if ($action === 'update_comment') {
        require_csrf();
        header('Content-Type: application/json; charset=utf-8');

        $id = (int)($_POST['id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        $value = (string)($_POST['value'] ?? '');

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверный id'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($field === 'comment_warehouse' && !$isLoggedIn) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Только для авторизованных'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $res = updateRegistryComment($db, $id, $field, $value);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // SEND REESTR EMAIL (SMTP)
    if ($action === 'send_reestr_email') {
        require_csrf();
        if (!$isLoggedIn) { http_response_code(403); die('Forbidden'); }

        header('Content-Type: application/json; charset=utf-8');

        $num = (int)($_POST['registry_number'] ?? 0);
        $toEmail = trim((string)($_POST['email'] ?? ''));

        if ($num <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверный номер реестра'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Неверный e-mail получателя'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $data = buildReestrEmailText($db, $num, $GLOBALS['types']);
        if (empty($data['success'])) {
            echo json_encode(['success' => false, 'message' => $data['message'] ?? 'Не удалось сформировать письмо'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $send = smtp_send_text_mail($toEmail, $data['subject'] ?? ("Реестр №{$num}"), $data['text'] ?? '');
        echo json_encode($send, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // UPDATE DOCUMENT (AJAX)
    if ($action === 'update_document') {
        require_csrf();
        if (!$isLoggedIn) { http_response_code(403); die('Forbidden'); }

        header('Content-Type: application/json; charset=utf-8');

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверный id'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $payload = [
            'contractor' => (string)($_POST['contractor'] ?? ''),
            'inn'        => (string)($_POST['inn'] ?? ''),
            'doc_name'   => (string)($_POST['doc_name'] ?? ''),
            'doc_num'    => (string)($_POST['doc_num'] ?? ''),
            'doc_date'   => (string)($_POST['doc_date'] ?? ''),
            'tk_num'     => (string)($_POST['tk_num'] ?? ''),
        ];

        $res = updateRegistryRow($db, $id, $payload);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // search is allowed without login
    if ($action === 'search') {
        require_csrf();
        // обработка ниже (flash)
    } else {
        if (!$isLoggedIn) { http_response_code(403); die('Forbidden'); }
        require_csrf();
    }

    if ($isLoggedIn && $action !== 'search') {
        switch ($action) {
            case 'add_document':
                header('Content-Type: application/json; charset=utf-8');

                $date = (string)($_POST['date'] ?? ymd_today());
                if (isDayFrozen($db, $date)) {
                    echo json_encode(['success' => false, 'message' => 'День заморожен в реестре. Добавление запрещено.'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $result = addToRegistry(
                    $db,
                    $date,
                    (string)($_POST['type'] ?? 'incoming'),
                    (string)($_POST['doc_date'] ?? ''),
                    (string)($_POST['contractor'] ?? ''),
                    (string)($_POST['inn'] ?? ''),
                    (string)($_POST['doc_name'] ?? ''),
                    (string)($_POST['doc_num'] ?? ''),
                    (string)($_POST['tk_num'] ?? '')
                );
                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                exit;

            case 'delete_document':
                if (!deleteFromRegistry($db, (int)($_POST['id'] ?? 0))) set_alert('Удаление запрещено: день заморожен в реестре.');
                break;

            case 'add_contractor':
                addContractor($db, (string)($_POST['name'] ?? ''), (string)($_POST['inn'] ?? ''));
                break;

            case 'update_contractor':
                updateContractor($db, (int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), (string)($_POST['inn'] ?? ''));
                break;

            case 'delete_contractor':
                deleteContractor($db, (int)($_POST['id'] ?? 0));
                break;

            case 'add_doc_type':
                addDocumentType($db, (string)($_POST['name'] ?? ''));
                break;

            case 'update_doc_type':
                updateDocumentType($db, (int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''));
                break;

            case 'delete_doc_type':
                deleteDocumentType($db, (int)($_POST['id'] ?? 0));
                break;

            case 'backup':
                set_alert('Для бэкапа используйте phpMyAdmin или mysqldump');
                break;

            case 'clear_reestr':
                clearRegistryNumber($db, (int)($_POST['registry_number'] ?? 0));
                break;

            case 'freeze_reestr':
                freezeReestr($db, (int)($_POST['registry_number'] ?? 0));
                break;

            case 'unfreeze_reestr':
                unfreezeReestr($db, (int)($_POST['registry_number'] ?? 0));
                break;
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['date']) ? '?date=' . urlencode((string)$_GET['date']) : ''));
        exit;
    }
}

/* =======================
   SEARCH (flash)
======================= */
$searchResults = [];
$searchPerformed = false;
$searchContractorDisplay = '';
$searchFromDisplay = '';
$searchToDisplay = '';

if (isset($_POST['action']) && $_POST['action'] === 'search') {
    require_csrf();

    $searchContractor = trim((string)($_POST['contractor'] ?? ''));
    $fromRaw = (string)($_POST['from'] ?? '');
    $toRaw = (string)($_POST['to'] ?? '');

    $from = ($fromRaw !== '') ? convertDateToYMD($fromRaw) : null;
    $to = ($toRaw !== '') ? convertDateToYMD($toRaw) : null;

    if ($searchContractor === '') {
        set_alert('Укажите контрагента для поиска!');
    } elseif (($fromRaw && !$from) || ($toRaw && !$to)) {
        set_alert('Неверный формат даты!');
    } else {
        $results = searchByContractor($db, $searchContractor, $from, $to);
        $_SESSION['flash_search_results'] = $results;
        $_SESSION['flash_search_contractor'] = htmlspecialchars($searchContractor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $_SESSION['flash_search_from'] = htmlspecialchars($fromRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $_SESSION['flash_search_to'] = htmlspecialchars($toRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['date']) ? '?date=' . urlencode((string)$_GET['date']) : ''));
    exit;
}

if (isset($_SESSION['flash_search_results'])) {
    $searchPerformed = true;
    $searchResults = $_SESSION['flash_search_results'];
    $searchContractorDisplay = $_SESSION['flash_search_contractor'];
    $searchFromDisplay = $_SESSION['flash_search_from'];
    $searchToDisplay = $_SESSION['flash_search_to'];

    unset($_SESSION['flash_search_results'], $_SESSION['flash_search_contractor'], $_SESSION['flash_search_from'], $_SESSION['flash_search_to']);
}

/* =======================
   PAGE DATA
======================= */
$currentDate = isset($_GET['date']) ? (string)$_GET['date'] : ymd_today();
$registry = getRegistryForDate($db, $currentDate);
$contractors = getContractors($db);
$docTypes = getDocumentTypes($db);
$reestrsList = getReestrsList($db);

$dayInfo = getDayRegistryInfo($db, $currentDate);
$isDayFrozenFlag = isDayFrozen($db, $currentDate);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Реестр передачи документов</title>
    <link rel="stylesheet" href="assets/css/style.css?v=10">
</head>
<body>

<?php if (isset($_SESSION['alert'])): ?>
    <script>
        alert(<?= json_encode($_SESSION['alert'], JSON_UNESCAPED_UNICODE) ?>);
    </script>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>

<header>
    <h1>Реестр передачи документов</h1>
    <div class="left-group">
        <button onclick="showModal('contractors')">Справочник контрагентов</button>
        <button onclick="showModal('doc_types')">Справочник видов документов</button>
        <?php if (!$isLoggedIn): ?>
            <button onclick="showModal('login')">Войти</button>
        <?php else: ?>
            <button onclick="location.href='?logout=1'">Выйти</button>
            <button onclick="backup()">Сделать бэкап</button>
            <button onclick="showModal('reestrs')">Редактирование реестров</button>
        <?php endif; ?>
    </div>
    <div class="right-group">
        <button onclick="printToday()">Печать за сегодня</button>
        <button onclick="showModal('print_range')">Печать за диапазон дат</button>
        <button onclick="showModal('open_day')">Открыть день...</button>
        <button onclick="showModal('search')">Поиск по контрагенту</button>
    </div>
</header>

<div class="container">
    <?php if ($isLoggedIn && !$isDayFrozenFlag): ?>
    <div class="tabs">
        <div class="tab active" data-type="incoming">Входящие</div>
        <div class="tab" data-type="outgoing">Исходящие</div>
        <div class="tab" data-type="incoming_tk">Входящие ТК</div>
        <div class="tab" data-type="outgoing_tk">Исходящие ТК</div>
    </div>

    <form id="add_form" onsubmit="handleAddSubmit(event)">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="add_document">
        <input type="hidden" name="type" id="type_input" value="incoming">
        <input type="hidden" name="date" value="<?= htmlspecialchars($currentDate) ?>">

        <div class="freeze-group">
            <input type="text" name="doc_date" id="doc_date" placeholder="ДД.ММ.ГГ" maxlength="10" required>
            <button type="button" id="freeze_doc_date" class="freeze-btn" onclick="toggleFreeze('doc_date')">❄</button>
        </div>

        <div class="freeze-group">
            <input list="contractors_list" name="contractor" id="contractor" placeholder="Контрагент" required>
            <button type="button" id="freeze_pair" class="freeze-btn" onclick="toggleFreezePair()">❄</button>
        </div>

        <datalist id="contractors_list">
            <?php foreach ($contractors as $c): ?>
                <option value="<?= htmlspecialchars((string)$c['name']); ?>" data-inn="<?= htmlspecialchars((string)$c['inn']); ?>">
            <?php endforeach; ?>
        </datalist>

        <div class="freeze-group">
            <input type="text" name="inn" id="inn" placeholder="ИНН" maxlength="12" pattern="\d*" required>
            <button type="button" id="freeze_inn" class="freeze-btn" onclick="toggleFreezePair()">❄</button>
        </div>

        <div class="freeze-group">
            <input list="doc_types_list" name="doc_name" id="doc_name" placeholder="Наименование документа" required>
            <button type="button" id="freeze_doc_name" class="freeze-btn" onclick="toggleFreeze('doc_name')">❄</button>
        </div>

        <datalist id="doc_types_list">
            <?php foreach ($docTypes as $d): ?>
                <option value="<?= htmlspecialchars((string)$d['name']); ?>">
            <?php endforeach; ?>
        </datalist>

        <input type="text" name="doc_num" id="doc_num" placeholder="Номер документа" required>
        <input type="text" name="tk_num" id="tk_num" placeholder="Номер ТК" style="display:none;">

        <button type="submit">Добавить в реестр</button>
    </form>
    <?php endif; ?>

    <h2 id="day_title"><?= ($currentDate === ymd_today()) ? 'Сегодня' : 'За день' ?> — <?= date('d.m.y', strtotime($currentDate)); ?></h2>

    <?php if ($isDayFrozenFlag): ?>
        <p class="warning">День заморожен. Редактирование (добавление/удаление/правка) запрещено, но комментарии доступны.</p>
    <?php endif; ?>

    <?php if (!empty($dayInfo)): ?>
        <p>- Занесён в реестр №<?= (int)$dayInfo['registry_number']; ?> от <?= htmlspecialchars((string)$dayInfo['print_date']); ?>
            <?php if ((int)$dayInfo['frozen'] === 1): ?><strong class="frozen-badge">(заморожен)</strong><?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (empty($registry)): ?>
        <p>За выбранный день документов нет.</p>
    <?php else: ?>
        <?php foreach ($types as $typeKey => $typeName):
            $filtered = array_values(array_filter($registry, fn($item) => $item['type'] === $typeKey));
            if (!empty($filtered)): ?>
                <div class="table-section" data-type="<?= htmlspecialchars((string)$typeKey); ?>">
                    <h3><?= htmlspecialchars((string)$typeName); ?></h3>
                    <table class="registry-table">
                        <thead>
                            <tr>
                                <th class="col-num">№</th>
                                <th class="col-contractor">Контрагент</th>
                                <th class="col-inn">ИНН</th>
                                <th class="col-docname">Наименование</th>
                                <th class="col-docnum">№ док.</th>
                                <th class="col-docdate">Дата док.</th>
                                <?php if (strpos((string)$typeKey, '_tk') !== false): ?><th class="col-tk">№ ТК</th><?php endif; ?>

                                <th class="col-comment">Коммент. Общий</th>
                                <?php if ($isLoggedIn): ?>
                                    <th class="col-comment">Коммент. Склад</th>
                                <?php endif; ?>

                                <?php if ($isLoggedIn): ?><th class="actions-col no-print">Действия</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $num = 1; foreach ($filtered as $doc): ?>
                                <tr class="doc-row" data-id="<?= (int)$doc['id']; ?>" data-type="<?= htmlspecialchars((string)$doc['type']); ?>">
                                    <td class="col-num"><?= $num++; ?></td>

                                    <td class="cell-view col-contractor"><?= htmlspecialchars((string)$doc['contractor']); ?></td>
                                    <td class="cell-view col-inn"><?= htmlspecialchars((string)$doc['inn']); ?></td>
                                    <td class="cell-view col-docname"><?= htmlspecialchars((string)$doc['doc_name']); ?></td>
                                    <td class="cell-view col-docnum"><?= htmlspecialchars((string)$doc['doc_num']); ?></td>
                                    <td class="cell-view col-docdate"><?= htmlspecialchars((string)$doc['doc_date']); ?></td>
                                    <?php if (strpos((string)$typeKey, '_tk') !== false): ?>
                                        <td class="cell-view col-tk"><?= htmlspecialchars((string)($doc['tk_num'] ?? '')); ?></td>
                                    <?php endif; ?>

                                    <!-- COMMENTS -->
                                    <td class="col-comment">
                                        <textarea class="comment-textarea"
                                                  data-id="<?= (int)$doc['id']; ?>"
                                                  data-field="comment_public"
                                                  maxlength="1000"
                                                  placeholder="Комментарий (общий)"><?= htmlspecialchars((string)($doc['comment_public'] ?? '')); ?></textarea>
                                        <div class="comment-status" aria-hidden="true"></div>
                                    </td>

                                    <?php if ($isLoggedIn): ?>
                                        <td class="col-comment">
                                            <textarea class="comment-textarea"
                                                      data-id="<?= (int)$doc['id']; ?>"
                                                      data-field="comment_warehouse"
                                                      maxlength="1000"
                                                      placeholder="Комментарий (склад)"><?= htmlspecialchars((string)($doc['comment_warehouse'] ?? '')); ?></textarea>
                                            <div class="comment-status" aria-hidden="true"></div>
                                        </td>
                                    <?php endif; ?>

                                    <?php if ($isLoggedIn): ?>
                                    <td class="actions-col no-print">
                                        <?php if (!$isDayFrozenFlag): ?>
                                            <button type="button" class="btn-small" onclick="startDocEdit(this)">Редакт.</button>
                                            <button type="button" class="btn-small btn-danger" onclick="deleteDoc(<?= (int)$doc['id']; ?>)">Удалить</button>
                                            <button type="button" class="btn-small btn-save" style="display:none;" onclick="saveDocEdit(this)">Сохранить</button>
                                            <button type="button" class="btn-small btn-cancel" style="display:none;" onclick="cancelDocEdit(this)">Отмена</button>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <td class="edit-cache" style="display:none;"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- MODALS -->
<div id="login_modal" class="modal">
    <div class="modal-content" onclick="event.stopPropagation()">
        <span class="close" onclick="closeModal('login')">&times;</span>
        <h3>Вход</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="login">
            <input type="password" name="credential" placeholder="Пароль" required>
            <button type="submit">Войти</button>
        </form>
    </div>
</div>

<div id="contractors_modal" class="modal" data-backdrop-close="0">
    <div class="modal-content" onclick="event.stopPropagation()">
        <span class="close" onclick="closeModal('contractors')">&times;</span>
        <h3>Справочник контрагентов</h3>

        <?php if ($isLoggedIn): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="add_contractor">
                <input type="text" name="name" placeholder="Имя" required>
                <input type="text" name="inn" placeholder="ИНН" maxlength="12" pattern="\d*" required>
                <button type="submit">Добавить</button>
            </form>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Имя</th>
                    <th>ИНН</th>
                    <?php if ($isLoggedIn): ?><th>Действия</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contractors as $c): ?>
                <tr class="editable-row" data-id="<?= (int)$c['id']; ?>">
                    <td>
                        <?php if ($isLoggedIn): ?>
                            <input class="row-input" type="text" name="name" value="<?= htmlspecialchars((string)$c['name']); ?>" disabled>
                        <?php else: ?>
                            <?= htmlspecialchars((string)$c['name']); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isLoggedIn): ?>
                            <input class="row-input" type="text" name="inn" value="<?= htmlspecialchars((string)$c['inn']); ?>" maxlength="12" pattern="\d*" disabled>
                        <?php else: ?>
                            <?= htmlspecialchars((string)$c['inn']); ?>
                        <?php endif; ?>
                    </td>

                    <?php if ($isLoggedIn): ?>
                    <td class="actions">
                        <button type="button" class="btn-edit" onclick="startRowEdit(this)">Редакт.</button>

                        <form method="post" class="row-save-form" style="display:none;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="update_contractor">
                            <input type="hidden" name="id" value="<?= (int)$c['id']; ?>">
                            <input type="hidden" name="name" value="">
                            <input type="hidden" name="inn" value="">
                            <button type="submit">Сохранить</button>
                            <button type="button" onclick="cancelRowEdit(this)">Отмена</button>
                        </form>

                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="delete_contractor">
                            <input type="hidden" name="id" value="<?= (int)$c['id']; ?>">
                            <button type="submit" onclick="return confirm('Удалить контрагента?')">Удалить</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="doc_types_modal" class="modal" data-backdrop-close="0">
    <div class="modal-content" onclick="event.stopPropagation()">
        <span class="close" onclick="closeModal('doc_types')">&times;</span>
        <h3>Справочник видов документов</h3>

        <?php if ($isLoggedIn): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="add_doc_type">
                <input type="text" name="name" placeholder="Наименование" required>
                <button type="submit">Добавить</button>
            </form>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Наименование</th>
                    <?php if ($isLoggedIn): ?><th>Действия</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($docTypes as $d): ?>
                <tr class="editable-row" data-id="<?= (int)$d['id']; ?>">
                    <td>
                        <?php if ($isLoggedIn): ?>
                            <input class="row-input" type="text" name="name" value="<?= htmlspecialchars((string)$d['name']); ?>" disabled>
                        <?php else: ?>
                            <?= htmlspecialchars((string)$d['name']); ?>
                        <?php endif; ?>
                    </td>

                    <?php if ($isLoggedIn): ?>
                    <td class="actions">
                        <button type="button" class="btn-edit" onclick="startRowEdit(this)">Редакт.</button>

                        <form method="post" class="row-save-form" style="display:none;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="update_doc_type">
                            <input type="hidden" name="id" value="<?= (int)$d['id']; ?>">
                            <input type="hidden" name="name" value="">
                            <button type="submit">Сохранить</button>
                            <button type="button" onclick="cancelRowEdit(this)">Отмена</button>
                        </form>

                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                            <input type="hidden" name="action" value="delete_doc_type">
                            <input type="hidden" name="id" value="<?= (int)$d['id']; ?>">
                            <button type="submit" onclick="return confirm('Удалить вид документа?')">Удалить</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="open_day_modal" class="modal">
    <div class="modal-content" onclick="event.stopPropagation()">
        <span class="close" onclick="closeModal('open_day')">&times;</span>
        <h3>Открыть день</h3>
        <input type="text" id="select_date" placeholder="ДД.ММ.ГГ" maxlength="10">
        <button onclick="openDate()">Открыть</button>
    </div>
</div>

<div id="print_range_modal" class="modal">
    <div class="modal-content" onclick="event.stopPropagation()">
        <span class="close" onclick="closeModal('print_range')">&times;</span>
        <h3>Печать за диапазон дат</h3>
        <input type="text" id="print_from_date" placeholder="От даты ДД.ММ.ГГ" maxlength="10" required>
        <input type="text" id="print_to_date" placeholder="До даты ДД.ММ.ГГ" maxlength="10" required>
        <button onclick="printRange()">Печать</button>
    </div>
</div>

<div id="reestrs_modal" class="modal">
    <div class="modal-content" onclick="event.stopPropagation()">
        <span class="close" onclick="closeModal('reestrs')">&times;</span>
        <h3>Редактирование реестров</h3>

        <input type="text" id="reestr_search_input" placeholder="Поиск по номеру, дате или периоду..." oninput="filterReestrs()">

        <?php if (empty($reestrsList)): ?>
            <p>Нет сформированных реестров.</p>
        <?php else: ?>
            <table id="reestrs_table">
                <thead>
                    <tr>
                        <th>Номер реестра</th>
                        <th>Дата формирования</th>
                        <th>Даты реестра</th>
                        <th>Количество записей</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reestrsList as $reestr): ?>
                    <?php
                        $datesArr = explode(',', (string)$reestr['dates']);
                        sort($datesArr);
                        $minDate = $datesArr[0] ?? '';
                        $maxDate = $datesArr[count($datesArr)-1] ?? '';
                    ?>
                    <tr>
                        <td><?= (int)$reestr['registry_number']; ?></td>
                        <td><?= htmlspecialchars((string)$reestr['print_date']); ?></td>
                        <td><?= implode(', ', array_map(fn($d) => date('d.m.y', strtotime($d)), $datesArr)); ?></td>
                        <td><?= (int)$reestr['count']; ?></td>
                        <td>
                            <?php if ((int)$reestr['is_frozen'] === 1): ?>
                                <span class="status-frozen">Заморожен</span>
                            <?php else: ?>
                                <span class="status-unfrozen">Разморожен</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button onclick="repeatPrint('<?= htmlspecialchars((string)$minDate); ?>','<?= htmlspecialchars((string)$maxDate); ?>')">Печать повторно</button>
                            <button onclick="openEmailForReestr(<?= (int)$reestr['registry_number']; ?>)">Отправить на e-mail</button>

                            <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                                <input type="hidden" name="action" value="clear_reestr">
                                <input type="hidden" name="registry_number" value="<?= (int)$reestr['registry_number']; ?>">
                                <button type="submit" onclick="return confirm('Снять реестр №<?= (int)$reestr['registry_number']; ?> с учета? (записи останутся, заморозка будет снята)')">Снять с учёта</button>
                            </form>

                            <?php if ((int)$reestr['is_frozen'] === 1): ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                                    <input type="hidden" name="action" value="unfreeze_reestr">
                                    <input type="hidden" name="registry_number" value="<?= (int)$reestr['registry_number']; ?>">
                                    <button type="submit" onclick="return confirm('Разморозить реестр №<?= (int)$reestr['registry_number']; ?>?')">Разморозить</button>
                                </form>
                            <?php else: ?>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                                    <input type="hidden" name="action" value="freeze_reestr">
                                    <input type="hidden" name="registry_number" value="<?= (int)$reestr['registry_number']; ?>">
                                    <button type="submit" onclick="return confirm('Заморозить реестр №<?= (int)$reestr['registry_number']; ?>?')">Заморозить</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div id="search_modal" class="modal">
    <div class="modal-content" onclick="event.stopPropagation()">
        <span class="close" onclick="closeModal('search')">&times;</span>
        <h3>Поиск по контрагенту</h3>

        <div class="search-row">
            <div>
                <label>Контрагент (из справочника):</label>
                <input list="contractors_search_list" id="search_contractor" placeholder="Начните вводить или выберите" value="<?= $searchContractorDisplay; ?>">
            </div>
            <div>
                <label>От даты (ДД.ММ.ГГ):</label>
                <input type="text" id="from_date" placeholder="ДД.ММ.ГГ" maxlength="10" value="<?= $searchFromDisplay; ?>">
            </div>
            <div>
                <label>До даты (ДД.ММ.ГГ):</label>
                <input type="text" id="to_date" placeholder="ДД.ММ.ГГ" maxlength="10" value="<?= $searchToDisplay; ?>">
            </div>
            <div>
                <button type="button" onclick="performSearch()">Поиск</button>
            </div>
        </div>

        <datalist id="contractors_search_list">
            <?php foreach ($contractors as $c): ?>
                <option value="<?= htmlspecialchars((string)$c['name']); ?>">
            <?php endforeach; ?>
        </datalist>

        <div id="search_results" style="overflow-x:auto; margin-top:20px;">
            <?php if ($searchPerformed): ?>
                <?php if (!empty($searchResults)): ?>
                    <h4 style="margin-top:0;">
                        Результаты поиска по "<?= $searchContractorDisplay; ?>"
                        <?php if ($searchFromDisplay || $searchToDisplay): ?>
                            за период
                            <?php if ($searchFromDisplay): ?> с <?= $searchFromDisplay; ?><?php endif; ?>
                            <?php if ($searchToDisplay): ?> по <?= $searchToDisplay; ?><?php endif; ?>
                        <?php endif; ?>
                        (найдено: <?= count($searchResults); ?>)
                    </h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Дата передачи</th>
                                <th>Тип</th>
                                <th>Контрагент</th>
                                <th>ИНН</th>
                                <th>Наименование</th>
                                <th>№ док.</th>
                                <th>Дата док.</th>
                                <th>№ ТК</th>
                                <th>Реестр</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($searchResults as $res): ?>
                            <tr>
                                <td><?= date('d.m.y', strtotime((string)$res['date'])); ?></td>
                                <td><?= htmlspecialchars((string)($types[$res['type']] ?? $res['type'])); ?></td>
                                <td><?= htmlspecialchars((string)$res['contractor']); ?></td>
                                <td><?= htmlspecialchars((string)$res['inn']); ?></td>
                                <td><?= htmlspecialchars((string)$res['doc_name']); ?></td>
                                <td><?= htmlspecialchars((string)$res['doc_num']); ?></td>
                                <td><?= htmlspecialchars((string)$res['doc_date']); ?></td>
                                <td><?= htmlspecialchars((string)($res['tk_num'] ?? '')); ?></td>
                                <td>
                                    <?php if (!empty($res['registry_number']) && (int)$res['registry_number'] > 0): ?>
                                        №<?= (int)$res['registry_number']; ?> от <?= htmlspecialchars((string)$res['print_date']); ?>
                                        <?php if (!empty($res['frozen'])): ?>
                                            <strong class="frozen-badge"> (заморожен)</strong>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <em>Не в реестре</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="empty">По вашему запросу ничего не найдено.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="email_modal" class="modal">
    <div class="modal-content" onclick="event.stopPropagation()">
        <span class="close" onclick="closeModal('email')">&times;</span>
        <h3>Отправить реестр на e-mail</h3>
        <input type="email" id="email_address" placeholder="E-mail" required>
        <button onclick="sendSelectedReestrEmail()">Отправить</button>
        <p class="empty" style="margin-top:10px;">Письмо отправляется с сервера (SMTP).</p>
    </div>
</div>

<script>
  window.__APP_CFG__ = {
    csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE); ?>,
    currentDate: <?= json_encode($currentDate, JSON_UNESCAPED_UNICODE); ?>,
    searchPerformed: <?= $searchPerformed ? 'true' : 'false'; ?>,
    isLoggedIn: <?= $isLoggedIn ? 'true' : 'false'; ?>
  };
</script>
<script src="assets/js/app.js?v=10"></script>
</body>
</html>
