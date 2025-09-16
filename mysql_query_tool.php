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
    $label = trim($_POST['cred_label'] ?? '');
    if ($host === '' || $user === '' || $label === '') {
        $loginError = 'Please provide host, user and a label for these credentials.';
    } else {
        $_SESSION[$sessionKey] = [
            'host' => $host,
            'user' => $user,
            'pass' => $pass,
            'database' => $database,
            'label' => $label,
            'created' => time()
        ];
        // Redirect to avoid reposts
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    unset($_SESSION[$sessionKey]);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Ensure saved file exists
if (!file_exists($savedFile)) {
    file_put_contents($savedFile, json_encode([]));
}

// Robust: if a GET load is requested, read the saved file directly and populate the editor
if (isset($_GET['load'])) {
    $loadId = $_GET['load'];
    $raw = @file_get_contents($savedFile);
    $all = json_decode($raw, true) ?: [];
    if ($loadId && isset($all[$loadId])) {
        $_POST['sql'] = $all[$loadId]['sql'];
        $currentSql = $all[$loadId]['sql'];
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

$savedQueries = load_saved_queries($savedFile);

$messages = [];
$results = null;
$error = null;
$databases = [];
$selectedDb = '';
$sep = $_POST['sep'] ?? $_GET['sep'] ?? ',';

// (GET load now handled more robustly earlier)

// Block main actions unless credentials are present and valid
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // actions: run, save, delete, load
    $action = $_POST['action'] ?? '';
    if ($action === 'run' || $action === 'save' || $action === 'export') {
        $sql = $_POST['sql'] ?? '';
        $name = trim($_POST['name'] ?? '');
        if (!credentials_valid()) {
            $error = 'Database credentials are missing or expired. Please log in.';
        }
        if ($action === 'save') {
            if ($name === '') {
                $messages[] = "Please provide a name to save the query.";
            } else {
                // store
                $id = uniqid();
                $savedQueries[$id] = [
                    'id' => $id,
                    'name' => $name,
                    'sql' => $sql,
                    'created' => date('c')
                ];
                save_saved_queries($savedFile, $savedQueries);
                $messages[] = "Saved query '{$name}'.";
            }
        }

        if ($action === 'run') {
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
                        if ($res = $mysqli->store_result()) {
                            $rows = $res->fetch_all(MYSQLI_ASSOC);
                            $results[] = ['type' => 'result', 'rows' => $rows];
                            $res->free();
                        } else {
                            $results[] = ['type' => 'info', 'info' => $mysqli->affected_rows];
                        }
                    } while ($mysqli->more_results() && $mysqli->next_result());
                } else {
                    throw new Exception('Query error: ' . $mysqli->error);
                }
                $mysqli->close();
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
        if ($action === 'export') {
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
                        fputcsv($out, [ (string)$mysqli->affected_rows ], $sep, '"');
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
        $id = $_POST['id'] ?? '';
        if ($id && isset($savedQueries[$id])) {
            $name = $savedQueries[$id]['name'];
            unset($savedQueries[$id]);
            save_saved_queries($savedFile, $savedQueries);
            $messages[] = "Deleted query '{$name}'.";
        }
    } elseif ($action === 'load') {
        $id = $_POST['id'] ?? '';
        if ($id && isset($savedQueries[$id])) {
            $loadedSql = $savedQueries[$id]['sql'];
            // set POST sql for UI
            $_POST['sql'] = $loadedSql;
            $messages[] = "Loaded query '{$savedQueries[$id]['name']}'.";
        }
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
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>MySQL Query Tool</title>
    <style>
        body {
            font-family: system-ui, Segoe UI, Roboto, Arial;
            margin: 20px
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            gap: 16px;
        }

        .sidebar {
            width: 220px;
            border: 1px solid #ddd;
            padding: 8px;
            height: calc(100vh - 80px);
            overflow: auto;
        }

        .main {
            flex: 1 1 auto;
        }

        textarea {
            width: 100%;
            height: 200px;
            font-family: monospace
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px
        }

        td,
        th {
            border: 1px solid #ddd;
            padding: 6px
        }

        .saved-list {
            margin-top: 10px
        }

        .message {
            padding: 8px;
            background: #eef;
            margin: 6px 0
        }

        .error {
            padding: 8px;
            background: #fee;
            color: #900;
            margin: 6px 0
        }
    </style>
</head>

<body>
        <div class="container">
        <div class="sidebar">
            <h3>Saved Queries</h3>
            <div class="saved-list">
                <?php if (empty($savedQueries)): ?>
                    <div>No saved queries yet.</div>
                <?php else: ?>
                    <ul style="list-style:none;padding:0;margin:0">
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
        </div>

        <div class="main">
        <h1>MySQL Query Tool</h1>
        <?php if (credentials_valid()):
            $c = $_SESSION[$sessionKey];
        ?>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <p style="margin:0">Logged in as <strong><?php echo htmlspecialchars($c['user']); ?></strong> @ <strong><?php echo htmlspecialchars($c['host']); ?></strong>
                (label: <?php echo htmlspecialchars($c['label']); ?>).</p>
                <form method="post" style="display:inline"><input type="hidden" name="action" value="logout"><button type="submit">Logout</button></form>
                <?php if (!empty($databases)): ?>
                    <form method="get" style="margin:0">
                        <label for="selected_db">Database:</label>
                        <select id="selected_db" name="selected_db" onchange="this.form.submit()">
                            <option value="">(none)</option>
                            <?php foreach ($databases as $db): ?>
                                <option value="<?php echo htmlspecialchars($db); ?>" <?php echo ($db === $selectedDb ? 'selected' : ''); ?>><?php echo htmlspecialchars($db); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit">Select</button></noscript>
                    </form>
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
                    <input name="cred_label" placeholder="label for these creds (e.g. 'dev-db')" required>
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
                <input type="text" name="name" placeholder="Save name" required>
                <input type="hidden" name="sql" id="saveSql">
                <button type="submit" onclick="document.getElementById('saveSql').value=(window.getEditorSQL?window.getEditorSQL():document.querySelector('textarea[name=sql]').value)">Save</button>
                <button type="button" onclick="document.getElementById('saveBox').style.display='none'">Cancel</button>
            </form>
        </div>

        <!-- saved queries moved to sidebar -->

        <?php if ($results !== null): ?>
            <h2>Results</h2>
            <?php foreach ($results as $i => $set): ?>
                <h3>Result Set <?php echo $i + 1; ?></h3>
                <?php if ($set['type'] === 'result'): ?>
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
                    <div>Affected rows: <?php echo htmlspecialchars((string)$set['info']); ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ace.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ext-language_tools.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/mode-sql.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/theme-textmate.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        (function () {
            var textarea = document.querySelector('textarea[name=sql]');
            var editorDiv = document.getElementById('editor');
            if (!textarea || !editorDiv || !window.ace) return;
            var editor = ace.edit(editorDiv);
            window.sqlEditor = editor;
            editor.setTheme('ace/theme/textmate');
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
                runForm.addEventListener('submit', function () {
                    textarea.value = editor.getValue();
                });
            }
            // Helper for other actions (e.g., Save Query)
            window.getEditorSQL = function () { return editor.getValue(); };
        })();
    </script>
</body>

</html>