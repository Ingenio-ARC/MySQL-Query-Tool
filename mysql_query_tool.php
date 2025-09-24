<?php
// mysql_query_tool.php
// Single-file PHP app to run and save MySQL queries.
// Edit the DB credentials below.


// Session-based DB credential storage (login form). If you prefer static credentials,
// uncomment and edit the block below.
// $dbHost = '127.0.0.1';
// $dbUser = 'dbuser';
// $dbPass = 'dbpass';
// $dbName = 'dbname';

// File to store saved queries
$savedFile = __DIR__ . '/saved_queries.json';

session_start();

// Session key and expiry settings (seconds)
$sessionKey = 'db_credentials';
$sessionExpirySeconds = 60 * 60 * 8; // 8 hours

// Helper to check credentials in session and expiry
function credentials_valid()
{
    global $sessionKey, $sessionExpirySeconds;
    if (empty($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) return false;
    $c = $_SESSION[$sessionKey];
    if (empty($c['host']) || empty($c['user']) || !isset($c['created'])) return false;
    if (time() - $c['created'] > $sessionExpirySeconds) return false;
    return true;
}

// Obtain credentials either from session or from POST login
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $host = trim($_POST['host'] ?? '');
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    $database = trim($_POST['database'] ?? '');
    if ($host === '' || $user === '') {
        $loginError = 'Please provide host and user.';
    } else {
        $_SESSION[$sessionKey] = [
            'host' => $host,
            'user' => $user,
            'pass' => $pass,
            'database' => $database,
            'created' => time()
        ];
        // Redirect to avoid reposts
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    unset($_SESSION[$sessionKey]);
    unset($_SESSION['last_row_impact']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Ensure saved file exists
if (!file_exists($savedFile)) {
    file_put_contents($savedFile, json_encode([]));
}

// Only allow loading saved queries when authenticated
// Robust: if a GET load is requested, read the saved file directly and populate the editor
if (credentials_valid() && isset($_GET['load'])) {
    $loadId = $_GET['load'];
    $raw = @file_get_contents($savedFile);
    $all = json_decode($raw, true) ?: [];
    if ($loadId && isset($all[$loadId])) {
        $_POST['sql'] = $all[$loadId]['sql'];
        $currentSql = $all[$loadId]['sql'];
        // Track which saved query is currently being edited
        $_SESSION['current_edit_id'] = $loadId;
        $_SESSION['current_edit_name'] = $all[$loadId]['name'];
        // create a messages array if not set yet
        if (!isset($messages)) $messages = [];
        $messages[] = "Loaded query '{$all[$loadId]['name']}' from sidebar.";
    }
}

function load_saved_queries($file)
{
    $data = json_decode(@file_get_contents($file), true);
    if (!is_array($data)) $data = [];
    return $data;
}

function save_saved_queries($file, $data)
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

$savedQueries = credentials_valid() ? load_saved_queries($savedFile) : [];

$messages = [];
$results = null;
$error = null;
$rowImpact = 0; // total rows returned or affected by the last executed SQL
$databases = [];
$selectedDb = '';
$sep = $_POST['sep'] ?? $_GET['sep'] ?? ',';
$tables = [];
$tableView = null; // holds data when viewing a table
$view = $_GET['view'] ?? '';
$view = in_array($view, ['table', 'sql']) ? $view : 'sql';

// (GET load now handled more robustly earlier)

// Block main actions unless credentials are present and valid
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // actions: run, save, delete, load
    $action = $_POST['action'] ?? '';
    if ($action === 'run' || $action === 'save' || $action === 'export') {
        $sql = $_POST['sql'] ?? '';
        $name = trim($_POST['name'] ?? '');
        // Ensure editor keeps the latest edited SQL after POST
        $currentSql = $sql;
        if (!credentials_valid()) {
            $error = 'Database credentials are missing or expired. Please log in.';
        }
        if ($action === 'save' && credentials_valid()) {
            if ($name === '') {
                $messages[] = "Please provide a name to save the query.";
            } else {
                // If editing an existing saved query, override it; else create new
                $editId = $_POST['id'] ?? ($_SESSION['current_edit_id'] ?? '');
                if ($editId && isset($savedQueries[$editId])) {
                    $savedQueries[$editId]['name'] = $name;
                    $savedQueries[$editId]['sql'] = $sql;
                    $savedQueries[$editId]['updated'] = date('c');
                    save_saved_queries($savedFile, $savedQueries);
                    $_SESSION['current_edit_id'] = $editId;
                    $_SESSION['current_edit_name'] = $name;
                    $messages[] = "Updated query '{$name}'.";
                } else {
                    // store new
                    $id = uniqid();
                    $savedQueries[$id] = [
                        'id' => $id,
                        'name' => $name,
                        'sql' => $sql,
                        'created' => date('c')
                    ];
                    save_saved_queries($savedFile, $savedQueries);
                    $_SESSION['current_edit_id'] = $id;
                    $_SESSION['current_edit_name'] = $name;
                    $messages[] = "Saved query '{$name}'.";
                }
            }
        }

        if ($action === 'run' && credentials_valid()) {
            // Run query
            try {
                // pull credentials from session
                $c = $_SESSION[$sessionKey];
                $dbHost = $c['host'];
                $dbUser = $c['user'];
                $dbPass = $c['pass'];
                // prefer an explicit selected_db from the form (POST), fall back to GET or stored default
                $dbName = $_POST['selected_db'] ?? $_GET['selected_db'] ?? ($c['database'] ?? '');
                $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
                if ($mysqli->connect_errno) {
                    throw new Exception('Connect error: ' . $mysqli->connect_error);
                }
                $ok = $mysqli->multi_query($sql);
                if ($ok) {
                    $results = [];
                    do {
                        $qStart = microtime(true);
                        if ($res = $mysqli->store_result()) {
                            $rows = $res->fetch_all(MYSQLI_ASSOC);
                            $elapsed = (microtime(true) - $qStart) * 1000.0;
                            $results[] = ['type' => 'result', 'rows' => $rows, 'time_ms' => $elapsed, 'row_count' => count($rows)];
                            $rowImpact += count($rows);
                            $res->free();
                        } else {
                            $elapsed = (microtime(true) - $qStart) * 1000.0;
                            $results[] = ['type' => 'info', 'info' => $mysqli->affected_rows, 'time_ms' => $elapsed];
                            if ($mysqli->affected_rows > 0) $rowImpact += (int)$mysqli->affected_rows;
                        }
                    } while ($mysqli->more_results() && $mysqli->next_result());
                    // Remember total impact for subsequent page views (e.g., when navigating)
                    $_SESSION['last_row_impact'] = (int)$rowImpact;
                } else {
                    throw new Exception('Query error: ' . $mysqli->error);
                }
                $mysqli->close();
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
        if ($action === 'export' && credentials_valid()) {
            // Export results as CSV by re-running the SQL and streaming CSV
            $sep = ($_POST['sep'] ?? ',') === ';' ? ';' : ',';
            try {
                $c = $_SESSION[$sessionKey];
                $dbHost = $c['host'];
                $dbUser = $c['user'];
                $dbPass = $c['pass'];
                $dbName = $_POST['selected_db'] ?? $_GET['selected_db'] ?? ($c['database'] ?? '');
                $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
                if ($mysqli->connect_errno) {
                    throw new Exception('Connect error: ' . $mysqli->connect_error);
                }
                $ok = $mysqli->multi_query($sql);
                if ($ok) {
                    // find first result set with rows
                    // determine filename from saved query name or default 'query'
                    $filenameBase = 'query';
                    // try to find a saved query that matches the SQL
                    foreach ($savedQueries as $sq) {
                        if (isset($sq['sql']) && trim($sq['sql']) === trim($sql)) {
                            $filenameBase = $sq['name'];
                            break;
                        }
                    }
                    // timestamp in YYYY-MM-dd_HH:mm:ss
                    $ts = date('Y-m-d_H:i:s');
                    $rawName = $filenameBase . '_' . $ts . '.csv';
                    // sanitize filename (keep alnum, dot, dash, underscore)
                    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $rawName);
                    // clear any accidental output and send headers for download
                    if (ob_get_level()) {
                        ob_end_clean();
                    }
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $safeName . '"');
                    $out = fopen('php://output', 'w');
                    if ($res = $mysqli->store_result()) {
                        $fields = $res->fetch_fields();
                        $headers = [];
                        foreach ($fields as $f) $headers[] = $f->name;
                        // write header
                        fputcsv($out, $headers, $sep, '"');
                        while ($row = $res->fetch_assoc()) {
                            // ensure strings are enclosed by fputcsv; leave numeric as-is
                            fputcsv($out, array_values($row), $sep, '"');
                        }
                        $res->free();
                    } else {
                        // no result set (e.g. update) - output a small CSV with info
                        fputcsv($out, ['info'], $sep, '"');
                        fputcsv($out, [(string)$mysqli->affected_rows], $sep, '"');
                    }
                    fclose($out);
                    $mysqli->close();
                    exit;
                } else {
                    throw new Exception('Query error: ' . $mysqli->error);
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        if (!credentials_valid()) {
            $error = 'Please log in to delete saved queries.';
        } else {
            $id = $_POST['id'] ?? '';
            if ($id && isset($savedQueries[$id])) {
                $name = $savedQueries[$id]['name'];
                unset($savedQueries[$id]);
                save_saved_queries($savedFile, $savedQueries);
                if (!empty($_SESSION['current_edit_id']) && $_SESSION['current_edit_id'] === $id) {
                    unset($_SESSION['current_edit_id'], $_SESSION['current_edit_name']);
                }
                $messages[] = "Deleted query '{$name}'.";
            }
        }
    } elseif ($action === 'load') {
        if (!credentials_valid()) {
            $error = 'Please log in to load saved queries.';
        } else {
            $id = $_POST['id'] ?? '';
            if ($id && isset($savedQueries[$id])) {
                $loadedSql = $savedQueries[$id]['sql'];
                // set POST sql for UI
                $_POST['sql'] = $loadedSql;
                // track that we're editing this saved query
                $_SESSION['current_edit_id'] = $id;
                $_SESSION['current_edit_name'] = $savedQueries[$id]['name'];
                $messages[] = "Loaded query '{$savedQueries[$id]['name']}'.";
            }
        }
    } elseif ($action === 'set_db') {
        // AJAX endpoint: set selected database in session without reloading
        header('Content-Type: application/json');
        if (!credentials_valid()) {
            echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
            exit;
        }
        $sel = $_POST['selected_db'] ?? '';
        $_SESSION[$sessionKey]['database'] = $sel;
        // Try fetching tables for the selected database
        $tablesAjax = [];
        if ($sel !== '') {
            try {
                $c = $_SESSION[$sessionKey];
                $mysqliAjax = new mysqli($c['host'], $c['user'], $c['pass'], $sel);
                if (!$mysqliAjax->connect_errno) {
                    if ($res = $mysqliAjax->query('SHOW TABLES')) {
                        while ($row = $res->fetch_array(MYSQLI_NUM)) {
                            $tablesAjax[] = $row[0];
                        }
                        $res->free();
                    }
                }
                $mysqliAjax->close();
            } catch (Exception $e) {
                // ignore here; client will handle no tables state
            }
        }
        echo json_encode(['ok' => true, 'selected_db' => $sel, 'tables' => $tablesAjax]);
        exit;
    }
}

// For convenience, set $currentSql for the textarea (don't overwrite if already set by GET load)
if (!isset($currentSql)) {
    $currentSql = $_POST['sql'] ?? "SELECT 1;";
}

// If credentials are valid, try to fetch available databases for the select box
if (credentials_valid()) {
    try {
        $c = $_SESSION[$sessionKey];
        $dbHost = $c['host'];
        $dbUser = $c['user'];
        $dbPass = $c['pass'];
        // connect without selecting a database to list databases
        $mysqli = new mysqli($dbHost, $dbUser, $dbPass);
        if ($mysqli->connect_errno) {
            throw new Exception('Connect error: ' . $mysqli->connect_error);
        }
        $res = $mysqli->query('SHOW DATABASES');
        if ($res) {
            while ($row = $res->fetch_array(MYSQLI_NUM)) {
                $databases[] = $row[0];
            }
            $res->free();
        }
        $mysqli->close();
    } catch (Exception $e) {
        // don't stop the app; show a message
        $messages[] = 'Could not list databases: ' . $e->getMessage();
    }
    // Determine selected DB from POST, GET, or stored default
    $selectedDb = $_POST['selected_db'] ?? $_GET['selected_db'] ?? ($c['database'] ?? '');
    // persist the selected DB into the stored credentials so other POST actions keep it
    if ($selectedDb !== '') {
        $_SESSION[$sessionKey]['database'] = $selectedDb;
    }
    // Load table list when a database is selected
    if ($selectedDb !== '') {
        try {
            $mysqli2 = new mysqli($dbHost, $dbUser, $dbPass, $selectedDb);
            if ($mysqli2->connect_errno) {
                throw new Exception('Connect error: ' . $mysqli2->connect_error);
            }
            $res2 = $mysqli2->query('SHOW TABLES');
            if ($res2) {
                while ($row = $res2->fetch_array(MYSQLI_NUM)) {
                    $tables[] = $row[0];
                }
                $res2->free();
            }
            $mysqli2->close();
        } catch (Exception $e) {
            $messages[] = 'Could not list tables: ' . $e->getMessage();
        }
    }
}

// Handle Table View with pagination
if (credentials_valid() && $selectedDb !== '' && ($view === 'table')) {
    $tableName = $_GET['table'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 100;
    if ($tableName !== '' && in_array($tableName, $tables, true)) {
        try {
            $c = $_SESSION[$sessionKey];
            $mysqli = new mysqli($c['host'], $c['user'], $c['pass'], $selectedDb);
            if ($mysqli->connect_errno) throw new Exception('Connect error: ' . $mysqli->connect_error);
            $count = 0;
            $resCnt = $mysqli->query('SELECT COUNT(*) AS cnt FROM `' . $mysqli->real_escape_string($tableName) . '`');
            if ($resCnt) {
                $row = $resCnt->fetch_assoc();
                $count = intval($row['cnt'] ?? 0);
                $resCnt->free();
            }
            $offset = ($page - 1) * $perPage;
            $rows = [];
            $cols = [];
            $res = $mysqli->query('SELECT * FROM `' . $mysqli->real_escape_string($tableName) . '` LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset));
            if ($res) {
                $fields = $res->fetch_fields();
                foreach ($fields as $f) $cols[] = $f->name;
                while ($r = $res->fetch_assoc()) $rows[] = $r;
                $res->free();
            }
            $mysqli->close();
            $tableView = [
                'name' => $tableName,
                'page' => $page,
                'perPage' => $perPage,
                'total' => $count,
                'cols' => $cols,
                'rows' => $rows
            ];
            // Update row impact to reflect currently displayed rows in Table View
            $rowImpact = count($rows);
            $_SESSION['last_row_impact'] = (int)$rowImpact;
        } catch (Exception $e) {
            $error = 'Table view error: ' . $e->getMessage();
        }
    } else if ($tableName !== '') {
        $error = 'Unknown table selected.';
    }
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>MySQL Query Tool</title>
    <style>
        /* Synthwave heading fonts */
    @import url('https://fonts.googleapis.com/css2?family=Mr+Dafoe&display=swap');
    @import url('https://fonts.googleapis.com/css2?family=Exo:wght@400;500;700;900&display=swap');

        :root {
            --bg: #ffffff;
            --fg: #111111;
            --border: #dddddd;
            --panel-bg: #ffffff;
            --msg-bg: #e9f1ff;
            --err-bg: #ffe9e9;
            --link: #0645ad;
        }

        body.dark {
            --bg: #0e1117;
            --fg: #6bc9f6;
            --border: #2d333b;
            --panel-bg: #0e1117;
            --msg-bg: #13233a;
            --err-bg: #3a1b1b;
            --link: #ba35ce;
        }

        body {
            font-family: 'Exo', system-ui, Segoe UI, Roboto, Arial, sans-serif;
            margin: 20px;
            background: var(--bg);
            color: var(--fg);
        }

        /* Make form controls and common UI elements inherit the Exo font */
        button, input, select, textarea, label, summary, .theme-toggle, table, th, td {
            font: inherit;
        }

        .container {
            margin: 0 auto;
            display: flex;
            gap: 16px;
            position: relative;
            z-index: 1;
        }

        .sidebar {
            width: 220px;
            min-width: 220px;
            flex: 0 0 220px;
            border: 1px solid var(--border);
            padding: 8px;
            height: calc(100vh - 80px);
            overflow: auto;
            background: var(--panel-bg);
        }

        .main {
            flex: 1 1 auto;
        }

        textarea {
            width: 100%;
            height: 200px;
            font-family: monospace;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px;
        }

        td,
        th {
            border: 1px solid var(--border);
            padding: 2px;
            font-size: 11px;
            max-width: 300px;
        }

        .saved-list {
            margin-top: 10px;
        }

        .message {
            padding: 8px;
            background: var(--msg-bg);
            margin: 6px 0;
        }

        .error {
            padding: 8px;
            background: var(--err-bg);
            color: #ffb4b4;
            margin: 6px 0;
        }

        a {
            color: var(--link);
        }

        #starfield {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            display: none;
            pointer-events: none;
        }

        body.dark #starfield {
            display: block;
        }

        .theme-toggle {
            position: fixed;
            left: 16px;
            bottom: 16px;
            z-index: 2;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 6px;
            background: var(--panel-bg);
            color: var(--fg);
            border: 1px solid var(--border);
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        /* ---- Synthwave headings (applied to all h1, h2) ---- */
        h1 {
            font-family: 'Exo', system-ui, Segoe UI, Roboto, Arial, sans-serif;
            font-weight: 900;
            letter-spacing: 0.03em;
            margin: 4px 0 8px 0;
            line-height: 1.1;
            font-size: 22px; /* sensible default for sidebar */
        }

        .main h1 {
            font-size: 26px; /* slightly larger in main content if used */
        }

        h2, summary {
            font-family: 'Mr Dafoe', cursive;
            margin: 8px 0 10px 0;
            line-height: 1.1;
            font-size: 22px;
        }

        /* Light mode: keep it readable, subtle effects */
        body:not(.dark) h1 {
            color: var(--fg);
            text-shadow: 0 0 0.05em rgba(139, 162, 208, 0.3);
        }
        body:not(.dark) h2 {
            color: var(--fg);
            text-shadow: 0 0 0.05em rgba(254, 5, 225, 0.2);
            transform: none;
        }

        /* Dark mode: mirror the CodePen color set */
        body.dark h1 {
            background-image: linear-gradient(
                #032d50 25%,
                #00a1ef 35%,
                #ffffff 50%,
                #20125f 50%,
                #8313e7 55%,
                #ff61af 75%
            );
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
            -webkit-text-stroke: 0.03em #94a0b9;
        }

        body.dark h2, body.dark summary {
            color: #ffffff;
            text-shadow: 0 0 0.05em #ffffff, 0 0 0.2em #fe05e1, 0 0 0.3em #fe05e1;
            transform: rotate(-2deg);
        }
    </style>
</head>
<?php $isDark = (($_COOKIE['theme'] ?? '') === 'dark'); ?>

<body class="<?php echo $isDark ? 'dark' : ''; ?>">
    <canvas id="starfield"></canvas>
    <div class="container">
        <div class="sidebar">
            <h1>MySQL Query Tool</h1>
            <div style="margin-bottom:8px"><a href="?view=sql&selected_db=<?php echo urlencode($selectedDb); ?>" id="sql_command_link">SQL Command</a></div>
            <?php if (credentials_valid()): ?>
                <details open>
                    <summary style="cursor:pointer;font-weight:600">Saved Queries</summary>
                    <div class="saved-list">
                        <?php if (empty($savedQueries)): ?>
                            <div>No saved queries yet.</div>
                        <?php else: ?>
                            <ul style="list-style:none;padding:0;margin:6px 0 0 0">
                                <?php foreach ($savedQueries as $s): ?>
                                    <li style="margin:6px 0;display:flex;align-items:center;justify-content:space-between">
                                        <a style="flex:1" href="?load=<?php echo urlencode($s['id']); ?>&selected_db=<?php echo urlencode($selectedDb); ?>"><?php echo htmlspecialchars($s['name']); ?></a>
                                        <form method="post" onsubmit="return confirm('Delete query <?php echo addslashes(htmlspecialchars($s['name'])); ?>?')" style="margin:0 0 0 8px">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="selected_db" value="<?php echo htmlspecialchars($selectedDb); ?>">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($s['id']); ?>">
                                            <button type="submit" style="background:transparent;border:none;color:#900;cursor:pointer">✖</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </details>
                <details open style="margin-top:10px">
                    <summary style="cursor:pointer;font-weight:600">Tables</summary>
                    <div id="tables_container" class="saved-list">
                        <?php if (empty($selectedDb)): ?>
                            <div>Select a database to view tables.</div>
                        <?php elseif (empty($tables)): ?>
                            <div>No tables found.</div>
                        <?php else: ?>
                            <ul style="list-style:none;padding:0;margin:6px 0 0 0">
                                <?php foreach ($tables as $t): ?>
                                    <li style="margin:6px 0">
                                        <a href="?view=table&table=<?php echo urlencode($t); ?>&page=1&selected_db=<?php echo urlencode($selectedDb); ?>"><?php echo htmlspecialchars($t); ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </details>
            <?php else: ?>
                <details open>
                    <summary style="cursor:pointer;font-weight:600">Saved Queries</summary>
                    <div id="tables_container" class="saved-list">
                        <div>Please log in to view saved queries.</div>
                    </div>
                </details>
                <details open style="margin-top:10px">
                    <summary style="cursor:pointer;font-weight:600">Tables</summary>
                    <div class="saved-list">
                        <div>Please log in to view tables.</div>
                    </div>
                </details>
            <?php endif; ?>
        </div>
        <div class="main">
            <?php if (credentials_valid()):
                $c = $_SESSION[$sessionKey];
            ?>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <p style="margin:0">Logged in as <strong><?php echo htmlspecialchars($c['user']); ?></strong> @ <strong><?php echo htmlspecialchars($c['host']); ?></strong>.</p>
                    <form method="post" style="display:inline"><input type="hidden" name="action" value="logout"><button type="submit">Logout</button></form>
                    <?php if (!empty($databases)): ?>
                        <div style="display:flex;align-items:center;gap:6px">
                            <label for="selected_db">Database:</label>
                            <select id="selected_db" name="selected_db">
                                <option value="">(none)</option>
                                <?php foreach ($databases as $db): ?>
                                    <option value="<?php echo htmlspecialchars($db); ?>" <?php echo ($db === $selectedDb ? 'selected' : ''); ?>><?php echo htmlspecialchars($db); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span id="db_status" style="font-size:12px;color:#666"></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p>Please log in with your MySQL credentials (they are stored in the session for <?php echo intval($sessionExpirySeconds / 60); ?> minutes).</p>
                <div style="border:1px solid #ddd;padding:10px;margin-bottom:10px">
                    <?php if (!empty($loginError)): ?><div class="error"><?php echo htmlspecialchars($loginError); ?></div><?php endif; ?>
                    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        <input type="hidden" name="action" value="login">
                        <input name="host" placeholder="host (127.0.0.1)" required>
                        <input name="user" placeholder="user" required>
                        <input name="pass" placeholder="password" type="password">
                        <input name="database" placeholder="default database (optional)">
                        <button type="submit">Login and Store</button>
                    </form>
                </div>
            <?php endif; ?>
            <?php foreach ($messages as $m): ?>
                <div class="message"><?php echo htmlspecialchars($m); ?></div>
            <?php endforeach; ?>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($view === 'table' && $tableView): ?>
                <h2>Table: <?php echo htmlspecialchars($tableView['name']); ?></h2>
                <?php
                $totalPages = max(1, (int)ceil($tableView['total'] / $tableView['perPage']));
                $currPage = $tableView['page'];
                $base = '?view=table&table=' . urlencode($tableView['name']) . '&selected_db=' . urlencode($selectedDb) . '&page=';
                ?>
                <div style="margin-bottom:8px">
                    <a href="<?php echo $base . max(1, $currPage - 1); ?>">Prev</a>
                    <span style="margin:0 8px">Page <?php echo $currPage; ?> / <?php echo $totalPages; ?></span>
                    <a href="<?php echo $base . min($totalPages, $currPage + 1); ?>">Next</a>
                </div>
                <?php if (empty($tableView['rows'])): ?>
                    <div>No rows.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <?php foreach ($tableView['cols'] as $col): ?>
                                    <th><?php echo htmlspecialchars($col); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tableView['rows'] as $row): ?>
                                <tr>
                                    <?php foreach ($row as $v): ?>
                                        <td><?php echo htmlspecialchars((string)$v); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <div style="margin-top:8px">
                    <a href="?view=table&table=<?php echo urlencode($tableView['name']); ?>&page=1&selected_db=<?php echo urlencode($selectedDb); ?>">First 100</a>
                    <span style="margin-left:8px"><a href="?view=sql&selected_db=<?php echo urlencode($selectedDb); ?>">Back to SQL Command</a></span>
                </div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="run">
                    <input type="hidden" name="selected_db" value="<?php echo htmlspecialchars($selectedDb); ?>">
                    <div id="editor" style="width:100%;height:220px;border:1px solid #ddd"></div>
                    <textarea name="sql" style="display:none"><?php echo htmlspecialchars($currentSql); ?></textarea>
                    <div style="margin-top:8px">
                        <button type="submit" name="action" value="run">Run SQL</button>
                        <button type="button" onclick="document.getElementById('saveBox').style.display='block'">Save Query</button>
                        <label for="sep" style="margin-left:8px">CSV sep:</label>
                        <select id="sep" name="sep">
                            <option value=",">Comma (,)</option>
                            <option value=";" <?php echo ($sep ?? ',') === ';' ? 'selected' : ''; ?>>Semicolon (;)</option>
                        </select>
                        <button type="submit" name="action" value="export">Export CSV</button>
                    </div>
                </form>

                <div id="saveBox" style="display:none;margin-top:8px">
                    <form method="post">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="selected_db" value="<?php echo htmlspecialchars($selectedDb); ?>">
                        <input type="text" name="name" placeholder="Save name" value="<?php echo htmlspecialchars($_SESSION['current_edit_name'] ?? ''); ?>" required>
                        <input type="hidden" name="sql" id="saveSql">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($_SESSION['current_edit_id'] ?? ''); ?>">
                        <button type="submit" onclick="document.getElementById('saveSql').value=(window.getEditorSQL?window.getEditorSQL():document.querySelector('textarea[name=sql]').value)">Save</button>
                        <button type="button" onclick="document.getElementById('saveBox').style.display='none'">Cancel</button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- saved queries moved to sidebar -->

            <?php if ($results !== null): ?>
                <?php foreach ($results as $i => $set): ?>
                    <?php if ($set['type'] === 'result'): ?>
                        <h2>Results</h2>
                        <h3><?php echo intval($set['row_count']); ?> rows in <?php echo number_format($set['time_ms'], 2); ?> ms</h3>
                        <?php if (empty($set['rows'])): ?>
                            <div>No rows returned.</div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <?php foreach (array_keys($set['rows'][0]) as $col): ?>
                                            <th><?php echo htmlspecialchars($col); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($set['rows'] as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $v): ?>
                                                <td><?php echo htmlspecialchars((string)$v); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php else: ?>
                        <h3>Affected rows: <?php echo htmlspecialchars((string)$set['info']); ?> in <?php echo isset($set['time_ms']) ? number_format($set['time_ms'], 2) . ' ms' : ''; ?></h3>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>
    <button id="theme_toggle" class="theme-toggle">Dark Mode</button>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ace.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ext-language_tools.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/mode-sql.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/theme-textmate.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/theme-monokai.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        // Expose last query's total row impact (returned or affected) to the starfield.
        // Use current request value if present; otherwise fall back to the last value saved in session.
        window.lastRowImpact = <?php echo (int)($rowImpact ?: ($_SESSION['last_row_impact'] ?? 0)); ?>;
        (function() {
            var textarea = document.querySelector('textarea[name=sql]');
            var editorDiv = document.getElementById('editor');
            if (!textarea || !editorDiv || !window.ace) return;
            var editor = ace.edit(editorDiv);
            window.sqlEditor = editor;
            var aceTheme = document.body.classList.contains('dark') ? 'ace/theme/monokai' : 'ace/theme/textmate';
            editor.setTheme(aceTheme);
            editor.session.setMode('ace/mode/sql');
            editor.setOptions({
                enableBasicAutocompletion: true,
                enableLiveAutocompletion: true,
                fontSize: '13px',
                tabSize: 2,
                useSoftTabs: true
            });
            editor.session.setValue(textarea.value || '');
            // Keep textarea synced on form submit
            var runForm = textarea.closest('form');
            if (runForm) {
                runForm.addEventListener('submit', function() {
                    textarea.value = editor.getValue();
                });
            }
            // Helper for other actions (e.g., Save Query)
            window.getEditorSQL = function() {
                return editor.getValue();
            };
        })();
        (function() {
            // Theme toggle + cookie persistence
            function setCookie(name, value, maxAgeSeconds) {
                document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAgeSeconds;
            }

            function getCookie(name) {
                var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
                return m ? decodeURIComponent(m[1]) : null;
            }
            var btn = document.getElementById('theme_toggle');

            function applyThemeClass(theme) {
                var dark = theme === 'dark';
                document.body.classList.toggle('dark', dark);
                if (window.sqlEditor) {
                    window.sqlEditor.setTheme(dark ? 'ace/theme/monokai' : 'ace/theme/textmate');
                }
                if (btn) btn.textContent = dark ? 'Light Mode' : 'Dark Mode';
                if (dark) startStarfield();
                else stopStarfield();
            }
            var initial = getCookie('theme') || (document.body.classList.contains('dark') ? 'dark' : 'light');
            applyThemeClass(initial);
            if (btn) {
                btn.addEventListener('click', function() {
                    var nowDark = !document.body.classList.contains('dark');
                    var theme = nowDark ? 'dark' : 'light';
                    setCookie('theme', theme, 60 * 60 * 24 * 365);
                    applyThemeClass(theme);
                });
            }
            // Subtle starfield animation for dark mode only
            var canvas = document.getElementById('starfield');
            var ctx = canvas ? canvas.getContext('2d') : null;
            var animId = null;
            var stars = [];
            var lastImpact = Math.max(0, parseInt(window.lastRowImpact || 0, 10) || 0);
            // Starfield configuration for easy tweaking
            var STARFIELD = {
                maxStars: 500, // global cap
                maxAltFraction: 0.8, // max fraction of stars going alternate direction
                baseSpeed: 0.05, // primary star nominal speed (px per frame)
                speedJitter: 0.04, // +/- jitter around base speed
                altSpeed: 0.07, // alternate stars speed
                obliqueAngleDeg: 20, // base oblique angle for primary drift (degrees). 0 = purely right
                altOffsetDeg: 180, // how much to rotate for alt direction
                verticalJitter: 0.3, // factor mixing in some orthogonal component
                // slow rotation to simulate sky; radians per second
                rotationSpeed: 0.005, // gentler rotation
                enableRotation: true
            };

            function resize() {
                if (!canvas) return;
                var dpr = window.devicePixelRatio || 1;
                canvas.width = Math.floor(window.innerWidth * dpr);
                canvas.height = Math.floor(window.innerHeight * dpr);
                canvas.style.width = window.innerWidth + 'px';
                canvas.style.height = window.innerHeight + 'px';
                if (ctx) ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            }

            function initStars() {
                stars = [];
                var w = window.innerWidth,
                    h = window.innerHeight;
                // Base count scales with area but is capped globally
                var areaCount = Math.max(100, Math.floor((w * h) / 22000));
                var total = Math.min(STARFIELD.maxStars, Math.min(400, areaCount));
                // Number of alt-direction stars based on last query rows, capped by fraction and total
                var altCap = Math.floor(total * STARFIELD.maxAltFraction);
                var altCount = Math.min(altCap, lastImpact);
                var mainCount = Math.max(0, total - altCount);
                console.log('Starfield init: total', total, 'main', mainCount, 'alt', altCount, 'for impact', lastImpact);

                // Precompute base angles in radians
                var baseRad = STARFIELD.obliqueAngleDeg * Math.PI / 180;
                var altRad = (STARFIELD.obliqueAngleDeg + STARFIELD.altOffsetDeg) * Math.PI / 180;
                var sinBase = Math.sin(baseRad),
                    cosBase = Math.cos(baseRad);
                var sinAlt = Math.sin(altRad),
                    cosAlt = Math.cos(altRad);

                function makeVel(speed, sinA, cosA) {
                    // Blend the base direction with a bit of orthogonal jitter for a more natural oblique path
                    var jitter = (Math.random() - 0.5) * STARFIELD.verticalJitter;
                    var jx = -sinA * jitter; // orthogonal component
                    var jy = cosA * jitter;
                    var vx = (cosA + jx);
                    var vy = (sinA + jy);
                    // normalize
                    var mag = Math.hypot(vx, vy) || 1;
                    vx = vx / mag * speed;
                    vy = vy / mag * speed;
                    return {
                        vx: vx,
                        vy: vy
                    };
                }

                // Primary stars
                for (var i = 0; i < mainCount; i++) {
                    var speed = STARFIELD.baseSpeed + (Math.random() - 0.5) * STARFIELD.speedJitter;
                    var v = makeVel(speed, sinBase, cosBase);
                    stars.push({
                        x: Math.random() * w,
                        y: Math.random() * h,
                        r: Math.random() * 1.4 + 0.3,
                        a: 0.45 + Math.random() * 0.55,
                        vx: v.vx,
                        vy: v.vy,
                        color: '#ffffff'
                    });
                }
                // Alternate stars (deeper blue as requested)
                for (var j = 0; j < altCount; j++) {
                    var sp2 = STARFIELD.altSpeed + (Math.random() - 0.5) * (STARFIELD.speedJitter * 0.8);
                    var v2 = makeVel(sp2, sinAlt, cosAlt);
                    stars.push({
                        x: Math.random() * w,
                        y: Math.random() * h,
                        r: Math.random() * 1.6 + 0.3,
                        a: 0.5 + Math.random() * 0.5,
                        vx: v2.vx,
                        vy: v2.vy,
                        color: 'rgb(81, 147, 255)'
                    });
                }
            }

            function step() {
                if (!ctx || !canvas) return;
                var w = canvas.width / (window.devicePixelRatio || 1);
                var h = canvas.height / (window.devicePixelRatio || 1);
                ctx.clearRect(0, 0, w, h);

                // First pass: draw rotating layer (primary white stars only)
                if (STARFIELD.enableRotation && STARFIELD.rotationSpeed) {
                    var now = performance.now() / 1000; // seconds
                    var rot = now * STARFIELD.rotationSpeed;
                    var cx = w / 2,
                        cy = h / 2;
                    ctx.save();
                    ctx.translate(cx, cy);
                    ctx.rotate(rot);
                    ctx.translate(-cx, -cy);
                    for (var i = 0; i < stars.length; i++) {
                        var s = stars[i];
                        if (s.color && s.color !== '#ffffff') continue; // rotate only primary/white stars
                        s.x += s.vx;
                        s.y += s.vy;
                        if (s.x < -2) s.x = w + 2;
                        else if (s.x > w + 2) s.x = -2;
                        if (s.y < -2) s.y = h + 2;
                        else if (s.y > h + 2) s.y = -2;
                        ctx.globalAlpha = s.a;
                        ctx.fillStyle = s.color || '#ffffff';
                        ctx.beginPath();
                        ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
                        ctx.fill();
                    }
                    ctx.restore();
                }

                // Second pass: draw non-rotating bluish stars
                for (var j = 0; j < stars.length; j++) {
                    var sb = stars[j];
                    if (!sb.color || sb.color === '#ffffff') continue; // skip primary layer here
                    sb.x += sb.vx;
                    sb.y += sb.vy;
                    if (sb.x < -2) sb.x = w + 2;
                    else if (sb.x > w + 2) sb.x = -2;
                    if (sb.y < -2) sb.y = h + 2;
                    else if (sb.y > h + 2) sb.y = -2;
                    ctx.globalAlpha = sb.a;
                    ctx.fillStyle = sb.color;
                    ctx.beginPath();
                    ctx.arc(sb.x, sb.y, sb.r, 0, Math.PI * 2);
                    ctx.fill();
                }
                ctx.globalAlpha = 1;
                animId = window.requestAnimationFrame(step);
            }

            function startStarfield() {
                if (!canvas || !ctx) return;
                resize();
                initStars();
                if (animId) cancelAnimationFrame(animId);
                animId = requestAnimationFrame(step);
                window.addEventListener('resize', onResize);
            }

            function stopStarfield() {
                if (animId) cancelAnimationFrame(animId);
                animId = null;
                if (ctx && canvas) ctx.clearRect(0, 0, canvas.width, canvas.height);
                window.removeEventListener('resize', onResize);
            }

            function onResize() {
                resize();
                initStars();
            }
            if (document.body.classList.contains('dark')) startStarfield();
        })();
        (function() {
            var sel = document.getElementById('selected_db');
            var status = document.getElementById('db_status');
            if (!sel) return;

            function setStatus(msg, ok) {
                if (!status) return;
                status.textContent = msg || '';
                status.style.color = ok ? '#2d7' : '#c33';
                if (msg) setTimeout(function() {
                    status.textContent = '';
                }, 1500);
            }
            sel.addEventListener('change', function() {
                var v = sel.value;
                setStatus('Saving…', true);
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=' + encodeURIComponent('set_db') + '&selected_db=' + encodeURIComponent(v)
                }).then(function(r) {
                    return r.json();
                }).then(function(j) {
                    if (j && j.ok) {
                        setStatus('Saved', true);
                        // sync hidden inputs named selected_db
                        document.querySelectorAll('input[name="selected_db"]').forEach(function(inp) {
                            inp.value = v;
                        });
                        // update saved query links to include selected_db
                        document.querySelectorAll('.sidebar a[href*="?load="]').forEach(function(a) {
                            try {
                                var url = new URL(a.href, window.location.origin);
                                url.searchParams.set('selected_db', v);
                                a.href = url.toString();
                            } catch (_) {}
                        });
                        // update SQL Command link
                        var sqlLink = document.getElementById('sql_command_link');
                        if (sqlLink) {
                            try {
                                var url2 = new URL(sqlLink.href, window.location.origin);
                                url2.searchParams.set('selected_db', v);
                                url2.searchParams.set('view', 'sql');
                                sqlLink.href = url2.toString();
                            } catch (_) {}
                        }
                        // rebuild Tables list if we got it from server
                        if (j.tables && Array.isArray(j.tables)) {
                            var container = document.getElementById('tables_container');
                            if (container) {
                                if (j.tables.length === 0) {
                                    container.innerHTML = '<div>No tables found.</div>';
                                } else {
                                    var ul = document.createElement('ul');
                                    ul.style.listStyle = 'none';
                                    ul.style.padding = '0';
                                    ul.style.margin = '6px 0 0 0';
                                    j.tables.forEach(function(t) {
                                        var li = document.createElement('li');
                                        li.style.margin = '6px 0';
                                        var a = document.createElement('a');
                                        a.textContent = t;
                                        a.href = '?view=table&table=' + encodeURIComponent(t) + '&page=1&selected_db=' + encodeURIComponent(v);
                                        li.appendChild(a);
                                        ul.appendChild(li);
                                    });
                                    container.innerHTML = '';
                                    container.appendChild(ul);
                                }
                            }
                        }
                    } else {
                        setStatus('Failed to save DB', false);
                    }
                }).catch(function() {
                    setStatus('Network error', false);
                });
            });
        })();
    </script>
</body>

</html>