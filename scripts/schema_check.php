<?php
// ============================================================
// scripts/schema_check.php - STATIC Option B schema checker (B0)
//
// Compares SQL written in the application's PHP files with a schema MODEL
// built from SQL files (the target reference plus explicitly selected
// migration drafts). It is static only:
//   - it never connects to MySQL/MariaDB (no PDO, no mysqli, no sockets);
//   - it never executes SQL; SQL files are parsed as text;
//   - it never includes or runs application code or config (no .env read);
//   - it writes nothing unless --out is given, and refuses an --out path
//     inside the repository.
// CLI only. See docs/B0_schema_check.md for usage.
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SC_REPO_ROOT = __DIR__ . '/..';
const SC_DEFAULT_SCHEMA = 'db/reference/mulawin_fleetops_new.sql';
const SC_DEFAULT_PATHS = ['ajax', 'pages', 'includes', 'auth', 'login.php'];

// Recommended application order of every draft (Option B plan, B0).
const SC_DRAFT_ORDER = [
    '20261010_batch1a_security_support_tables.sql',
    '20261010_batch1b_login_attempts.sql',
    '20261010_p21_roles_system_key.sql',
    '20261010_s36_employee_fields.sql',
    '20261014_tE1_employee_compat_columns.sql',
    '20261011_p21_rbac_seed_review_only.sql',
    '20261014_tS1_support_tables.sql',
    '20261014_tK1_truck_fuel_efficiency.sql',
    '20261014_tA1_approval_workflow_steps.sql',
    '20261014_tT1_trip_second_driver.sql',
    '20261014_tT2_trip_completion_reports.sql',
    '20261014_tC1_client_booking_dispatch_fields.sql',
    '20261014_tI1_trip_incident_resolution.sql',
    '20261014_tD1_announcement_audience.sql',
    '20261014_tP1_trip_pay_records_guard.sql',
    '20261014_tF1_expense_categories.sql',
    '20261014_tB1_budgets.sql',
    '20261014_tM1_maintenance_next_due.sql',
    '20261014_tM2_maintenance_planning.sql',
    '20261014_tN1_inspection_templates_seed.sql',
    '20261010_p21_attendance_records.sql',
];

const SC_SQL_KEYWORDS = [
    'select','from','where','and','or','not','null','is','in','like','between','exists','as','on','join',
    'left','right','inner','outer','cross','straight_join','natural','using','group','order','by','having',
    'limit','offset','union','all','distinct','insert','into','values','value','update','set','delete',
    'replace','ignore','duplicate','key','for','share','lock','mode','case','when','then','else','end',
    'asc','desc','with','interval','day','month','year','hour','minute','second','week','true','false',
    'default','if','ifnull','coalesce','count','sum','min','max','avg','now','curdate','date','date_sub',
    'date_add','datediff','timestampdiff','concat','concat_ws','trim','nullif','lower','upper','length',
    'substring','substring_index','cast','unsigned','signed','char','decimal','round','floor','ceil',
    'abs','greatest','least','field','find_in_set','json_extract','json_unquote','last_insert_id',
    'row_count','found_rows','get_lock','release_lock','sha2','md5','uuid','database','dual','regexp',
    'rlike','div','mod','xor','escape','current_timestamp','curtime','time','timestamp','datetime',
    'date_format','str_to_date','month','yearweek','dayofweek','weekday','last_day','to_days','high_priority',
    'low_priority','sql_calc_found_rows','partition','over','window','rows','range','preceding','following',
    'current','row','unbounded','nowait','skip','locked','of','any','some','char_length','format','ifnull',
    'convert','collate','binary','hex','unhex','rand','sign','power','sqrt','exp','ln','log','truncate',
    'monthname','dayname','quarter','extract','makedate','sec_to_time','time_to_sec','addtime','subtime',
    'utc_timestamp','group_concat','separator','json_object','json_array','json_arrayagg','json_objectagg',
    'lpad','rpad','repeat','reverse','instr','locate','position','left','right','mid','ascii','ord',
];

// ---------------------------------------------------------------- options
function scParseArgs(array $argv): array {
    $o = ['schema' => SC_DEFAULT_SCHEMA, 'drafts' => 'none', 'paths' => SC_DEFAULT_PATHS, 'format' => 'text',
          'out' => null, 'lint' => false, 'scan' => true, 'self_test' => false, 'fail_on_confirmed' => false,
          'show_uncertain' => true, 'only' => null, 'help' => false];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--help' || $a === '-h') $o['help'] = true;
        elseif ($a === '--lint-drafts') $o['lint'] = true;
        elseif ($a === '--no-scan') $o['scan'] = false;
        elseif ($a === '--self-test') $o['self_test'] = true;
        elseif ($a === '--fail-on-confirmed') $o['fail_on_confirmed'] = true;
        elseif ($a === '--confirmed-only') $o['show_uncertain'] = false;
        elseif (preg_match('/^--schema=(.+)$/', $a, $m)) $o['schema'] = $m[1];
        elseif (preg_match('/^--drafts=(.*)$/', $a, $m)) $o['drafts'] = $m[1];
        elseif (preg_match('/^--paths=(.+)$/', $a, $m)) $o['paths'] = array_filter(array_map('trim', explode(',', $m[1])));
        elseif (preg_match('/^--only=(.+)$/', $a, $m)) $o['only'] = array_filter(array_map('trim', explode(',', $m[1])));
        elseif (preg_match('/^--format=(text|csv|json)$/', $a, $m)) $o['format'] = $m[1];
        elseif (preg_match('/^--out=(.+)$/', $a, $m)) $o['out'] = $m[1];
        else { fwrite(STDERR, "Unknown option: $a (see --help)\n"); exit(2); }
    }
    return $o;
}

function scUsage(): string {
    return <<<TXT
Usage: php scripts/schema_check.php [options]
  --schema=FILE          Target schema SQL (default: db/reference/mulawin_fleetops_new.sql)
  --drafts=SPEC          Drafts applied on top of the schema, in order:
                           none (default) | all | existing | t-series |
                           comma list of file names from db/target_migrations/
                           (applied in the recommended order, not list order)
  --lint-drafts          Check the selected drafts (duplicates, ordering, FK types,
                           forbidden statements). With --drafts=none, lints all.
  --no-scan              Skip the application SQL scan.
  --paths=a,b            Application paths to scan (default: ajax,pages,includes,auth,login.php)
  --only=f1,f2           Scan only these files (repo-relative).
  --confirmed-only       Hide UNCERTAIN and DYNAMIC findings.
  --format=text|csv|json Output format (default text).
  --out=FILE             Write the report to FILE (must be OUTSIDE the repository).
  --fail-on-confirmed    Exit 1 if any CONFIRMED finding or lint error exists.
  --self-test            Run built-in parser tests (no files besides this one).
Static only: no database connection, no SQL execution, no application code loaded.

TXT;
}

// ---------------------------------------------------------------- SQL text helpers
function scStripSqlComments(string $sql): string {
    $out = ''; $n = strlen($sql); $q = null;
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== null) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $n) { $out .= $sql[++$i]; continue; }
            if ($c === $q) {
                if ($i + 1 < $n && $sql[$i + 1] === $q) { $out .= $sql[++$i]; continue; }
                $q = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $out .= $c; continue; }
        if ($c === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) {
            while ($i < $n && $sql[$i] !== "\n") $i++;
            $out .= "\n"; continue;
        }
        if ($c === '#') { while ($i < $n && $sql[$i] !== "\n") $i++; $out .= "\n"; continue; }
        if ($c === '/' && substr($sql, $i, 2) === '/*') { $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $n : $e + 1; $out .= ' '; continue; }
        $out .= $c;
    }
    return $out;
}

// Splits on a separator at depth 0 outside quotes.
function scSplitTop(string $s, string $sep): array {
    $parts = []; $cur = ''; $depth = 0; $q = null; $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($q !== null) {
            $cur .= $c;
            if ($c === '\\' && $i + 1 < $n) { $cur .= $s[++$i]; continue; }
            if ($c === $q) $q = null;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $cur .= $c; continue; }
        if ($c === '(') $depth++;
        if ($c === ')') $depth--;
        if ($c === $sep && $depth === 0) { $parts[] = $cur; $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') $parts[] = $cur;
    return $parts;
}

// Replaces quoted literals with '#n' and returns [text, literals].
function scMaskLiterals(string $sql): array {
    $lits = [];
    $text = preg_replace_callback("/'((?:[^'\\\\]|\\\\.|'')*)'/s", function ($m) use (&$lits) {
        $lits[] = str_replace("''", "'", $m[1]);
        return "'#" . (count($lits) - 1) . "'";
    }, $sql);
    return [$text, $lits];
}

function scIdent(string $s): string { return strtolower(trim($s, " `\t\r\n")); }

// ---------------------------------------------------------------- schema model
function scNewModel(): array { return ['tables' => [], 'constraints' => [], 'issues' => []]; }

function scParseColumnDef(string $def): array {
    $d = preg_replace('/\s+/', ' ', trim($def));
    preg_match('/^`?(\w+)`?\s+(\w+)(\s*\((?:[^()]|\([^()]*\))*\))?(.*)$/s', $d, $m);
    $type = strtolower($m[2] ?? '');
    $args = $m[3] ?? '';
    $rest = $m[4] ?? '';
    $attrs = [
        'type' => $type,
        'unsigned' => (bool)preg_match('/\bUNSIGNED\b/i', $rest),
        'not_null' => (bool)preg_match('/\bNOT\s+NULL\b/i', $rest) || (bool)preg_match('/\bPRIMARY\s+KEY\b/i', $rest),
        'has_default' => (bool)preg_match('/\bDEFAULT\b/i', $rest),
        'auto_increment' => (bool)preg_match('/\bAUTO_INCREMENT\b/i', $rest),
        'generated' => (bool)preg_match('/\bGENERATED\s+ALWAYS\b|\bAS\s*\(/i', $rest),
        'enum' => null,
        'definition' => $d,
    ];
    if (in_array($type, ['enum', 'set'], true) && preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $args, $vals)) {
        $attrs['enum'] = array_map(fn($v) => str_replace("''", "'", $v), $vals[1]);
    }
    $attrs['inline_pk'] = (bool)preg_match('/\bPRIMARY\s+KEY\b/i', $rest);
    $attrs['inline_unique'] = (bool)preg_match('/\bUNIQUE\b/i', $rest);
    return [scIdent($m[1] ?? ''), $attrs];
}

function scIssue(array &$model, string $level, string $src, string $msg): void {
    $model['issues'][] = ['level' => $level, 'source' => $src, 'message' => $msg];
}

function scColsList(string $s): array {
    return array_values(array_filter(array_map(fn($c) => scIdent(preg_replace('/\(\d+\)|\s+(ASC|DESC)$/i', '', trim($c))), explode(',', $s))));
}

function scAddConstraintName(array &$model, string $name, string $table, string $src): void {
    $key = strtolower($name);
    if (isset($model['constraints'][$key])) {
        scIssue($model, 'ERROR', $src, "constraint name '$name' on $table already used by {$model['constraints'][$key]} (FK/CHECK names must be unique per database in MySQL)");
    } else {
        $model['constraints'][$key] = "$table ($src)";
    }
}

function scAddTableElement(array &$model, string $t, string $el, string $src, bool $isAlter): void {
    $e = trim(preg_replace('/\s+/', ' ', $el));
    $tbl = &$model['tables'][$t];
    if (preg_match('/^(?:CONSTRAINT\s+`?(\w+)`?\s+)?PRIMARY\s+KEY\s*\(([^)]*)\)/i', $e, $m)) {
        $tbl['indexes']['primary'] = ['unique' => true, 'cols' => scColsList($m[2])];
        return;
    }
    if (preg_match('/^(?:CONSTRAINT\s+`?(\w+)`?\s+)?UNIQUE(?:\s+(?:KEY|INDEX))?\s*(?:`?(\w+)`?\s*)?\(([^)]*)\)/i', $e, $m)) {
        $name = strtolower($m[2] !== '' ? $m[2] : ($m[1] !== '' ? $m[1] : 'unique_' . count($tbl['indexes'])));
        if (isset($tbl['indexes'][$name])) scIssue($model, 'ERROR', $src, "duplicate index name '$name' on table $t");
        $tbl['indexes'][$name] = ['unique' => true, 'cols' => scColsList($m[3])];
        return;
    }
    if (preg_match('/^(?:CONSTRAINT\s+`?(\w+)`?\s+)?FOREIGN\s+KEY\s*(?:`?\w+`?\s*)?\(([^)]*)\)\s*REFERENCES\s+`?(\w+)`?\s*\(([^)]*)\)/i', $e, $m)) {
        $name = $m[1] !== '' ? $m[1] : 'fk_unnamed_' . $t . '_' . count($tbl['fks']);
        if ($m[1] !== '') scAddConstraintName($model, $name, $t, $src);
        $tbl['fks'][strtolower($name)] = ['cols' => scColsList($m[2]), 'ref_table' => scIdent($m[3]), 'ref_cols' => scColsList($m[4]), 'source' => $src];
        if (!isset($tbl['indexes']['fk:' . strtolower($name)])) $tbl['indexes']['fk:' . strtolower($name)] = ['unique' => false, 'cols' => scColsList($m[2]), 'implicit' => true];
        return;
    }
    if (preg_match('/^(?:CONSTRAINT\s+`?(\w+)`?\s+)?CHECK\s*\(/i', $e, $m)) {
        if (($m[1] ?? '') !== '') { scAddConstraintName($model, $m[1], $t, $src); $tbl['checks'][strtolower($m[1])] = true; }
        return;
    }
    if (preg_match('/^(?:KEY|INDEX|FULLTEXT(?:\s+(?:KEY|INDEX))?|SPATIAL(?:\s+(?:KEY|INDEX))?)\s+(?:`?(\w+)`?\s*)?\(([^)]*)\)/i', $e, $m)) {
        $name = strtolower($m[1] !== '' ? $m[1] : 'idx_' . count($tbl['indexes']));
        if (isset($tbl['indexes'][$name])) scIssue($model, 'ERROR', $src, "duplicate index name '$name' on table $t");
        $tbl['indexes'][$name] = ['unique' => false, 'cols' => scColsList($m[2])];
        return;
    }
    // column definition
    [$col, $attrs] = scParseColumnDef($e);
    if ($col === '' || $attrs['type'] === '') { scIssue($model, 'WARN', $src, "could not parse element of $t: " . substr($e, 0, 80)); return; }
    if (isset($tbl['cols'][$col])) { scIssue($model, 'ERROR', $src, "duplicate column $t.$col"); return; }
    $attrs['source'] = $src;
    $tbl['cols'][$col] = $attrs;
    if ($attrs['inline_pk']) $tbl['indexes']['primary'] = ['unique' => true, 'cols' => [$col]];
    elseif ($attrs['inline_unique']) $tbl['indexes']['uq_inline_' . $col] = ['unique' => true, 'cols' => [$col]];
    if (preg_match('/\bAFTER\s+`?(\w+)`?/i', $e, $am) && $isAlter && !isset($tbl['cols'][scIdent($am[1])])) {
        scIssue($model, 'ERROR', $src, "ADD COLUMN $t.$col AFTER missing column " . scIdent($am[1]));
    }
}

// Applies one SQL file to the model. Returns the DML statements found.
function scApplySqlFile(array &$model, string $path, string $label): array {
    $raw = file_get_contents($path);
    if ($raw === false) { scIssue($model, 'ERROR', $label, "cannot read $path"); return []; }
    $sql = scStripSqlComments($raw);
    $dml = [];
    foreach (scSplitTop($sql, ';') as $stmt) {
        $st = trim($stmt);
        if ($st === '') continue;
        if (preg_match('/^CREATE\s+DATABASE\b/i', $st)) { $model['meta']['create_database'][] = [$label, $st]; continue; }
        if (preg_match('/^USE\s+`?(\w+)`?/i', $st, $m)) { $model['meta']['use'][] = [$label, $m[1]]; continue; }
        if (preg_match('/^(SET|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\b/i', $st)) continue;
        if (preg_match('/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*)\)([^)]*)$/is', $st, $m)) {
            $t = scIdent($m[2]);
            if (isset($model['tables'][$t])) {
                scIssue($model, $m[1] !== '' ? 'WARN' : 'ERROR', $label, "CREATE TABLE $t: table already exists (from {$model['tables'][$t]['source']})" . ($m[1] !== '' ? ' - IF NOT EXISTS would silently skip it' : ''));
                continue;
            }
            $model['tables'][$t] = ['cols' => [], 'indexes' => [], 'fks' => [], 'checks' => [], 'source' => $label];
            foreach (scSplitTop($m[3], ',') as $el) scAddTableElement($model, $t, $el, $label, false);
            continue;
        }
        if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $st, $m)) {
            $t = scIdent($m[1]);
            if (!isset($model['tables'][$t])) { scIssue($model, 'ERROR', $label, "ALTER TABLE $t: table does not exist at this point in the order"); continue; }
            foreach (scSplitTop($m[2], ',') as $clause) scApplyAlterClause($model, $t, trim($clause), $label);
            continue;
        }
        if (preg_match('/^DROP\s+(TABLE|DATABASE|INDEX|VIEW)\b/i', $st)) { scIssue($model, 'ERROR', $label, 'executable DROP statement in a draft: ' . substr(preg_replace('/\s+/', ' ', $st), 0, 80)); continue; }
        if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|SELECT|WITH)\b/i', $st)) { $dml[] = $st; continue; }
        if (preg_match('/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?VIEW\s+`?(\w+)`?\s+AS\s+(.*)$/is', $st, $m)) {
            if (!empty($model['is_schema_file'])) {
                // Views of the target reference: modelled as read-only objects; their SELECT is checked.
                $model['views'][scIdent($m[1])] = $label;
                $dml[] = $m[2];
            } else {
                scIssue($model, 'ERROR', $label, 'CREATE VIEW in a draft (not allowed in B0 drafts): ' . scIdent($m[1]));
            }
            continue;
        }
        if (preg_match('/^(TRUNCATE|RENAME|GRANT|REVOKE|CREATE\s+(USER|PROCEDURE|FUNCTION|TRIGGER|EVENT|VIEW))\b/i', $st)) { scIssue($model, 'ERROR', $label, 'forbidden statement: ' . substr($st, 0, 60)); continue; }
        scIssue($model, 'WARN', $label, 'unrecognized statement (not modelled): ' . substr(preg_replace('/\s+/', ' ', $st), 0, 80));
    }
    return $dml;
}

function scApplyAlterClause(array &$model, string $t, string $c, string $src): void {
    $tbl = &$model['tables'][$t];
    if (preg_match('/^ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\b/i', $c)) scIssue($model, 'WARN', $src, "ADD COLUMN IF NOT EXISTS on $t is MariaDB-only (MySQL 8 rejects it)");
    if (preg_match('/^ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?((?:CONSTRAINT\b|PRIMARY\b|UNIQUE\b|KEY\b|INDEX\b|FOREIGN\b|CHECK\b|FULLTEXT\b|SPATIAL\b).*)$/is', $c, $m)) {
        scAddTableElement($model, $t, $m[1], $src, true); return;
    }
    if (preg_match('/^ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?(.*)$/is', $c, $m)) { scAddTableElement($model, $t, $m[1], $src, true); return; }
    if (preg_match('/^MODIFY\s+(?:COLUMN\s+)?(.*)$/is', $c, $m)) {
        [$col, $attrs] = scParseColumnDef($m[1]);
        if (!isset($tbl['cols'][$col])) { scIssue($model, 'ERROR', $src, "MODIFY $t.$col: column does not exist"); return; }
        $attrs['source'] = $src; $tbl['cols'][$col] = $attrs; return;
    }
    if (preg_match('/^CHANGE\s+(?:COLUMN\s+)?`?(\w+)`?\s+(.*)$/is', $c, $m)) {
        $old = scIdent($m[1]); [$new, $attrs] = scParseColumnDef($m[2]);
        if (!isset($tbl['cols'][$old])) { scIssue($model, 'ERROR', $src, "CHANGE $t.$old: column does not exist"); return; }
        unset($tbl['cols'][$old]); $attrs['source'] = $src; $tbl['cols'][$new] = $attrs; return;
    }
    if (preg_match('/^RENAME\s+COLUMN\s+`?(\w+)`?\s+TO\s+`?(\w+)`?/i', $c, $m)) {
        $old = scIdent($m[1]); $new = scIdent($m[2]);
        if (!isset($tbl['cols'][$old])) { scIssue($model, 'ERROR', $src, "RENAME COLUMN $t.$old: column does not exist"); return; }
        $tbl['cols'][$new] = $tbl['cols'][$old]; unset($tbl['cols'][$old]);
        scIssue($model, 'WARN', $src, "RENAME COLUMN needs MariaDB 10.5+ (10.4 needs CHANGE COLUMN)"); return;
    }
    if (preg_match('/^DROP\s+(?:COLUMN\s+)?`?(\w+)`?\s*$/i', $c, $m) && !preg_match('/^DROP\s+(INDEX|KEY|FOREIGN|PRIMARY|CHECK|CONSTRAINT)\b/i', $c)) {
        scIssue($model, 'ERROR', $src, "executable DROP COLUMN $t." . scIdent($m[1]) . ' in a draft'); return;
    }
    if (preg_match('/^DROP\b/i', $c)) { scIssue($model, 'ERROR', $src, "executable DROP clause on $t in a draft: " . substr($c, 0, 60)); return; }
    scIssue($model, 'WARN', $src, "unmodelled ALTER clause on $t: " . substr(preg_replace('/\s+/', ' ', $c), 0, 70));
}

// FK target/type checks over the whole model.
function scCheckForeignKeys(array &$model, ?array $onlySources = null): void {
    foreach ($model['tables'] as $t => $tbl) {
        foreach ($tbl['fks'] as $name => $fk) {
            if ($onlySources !== null && !in_array($fk['source'], $onlySources, true)) continue;
            $rt = $fk['ref_table'];
            if (!isset($model['tables'][$rt])) { scIssue($model, 'ERROR', $fk['source'], "FK $name on $t references missing table $rt"); continue; }
            foreach ($fk['cols'] as $i => $col) {
                $rc = $fk['ref_cols'][$i] ?? null;
                $a = $tbl['cols'][$col] ?? null; $b = $model['tables'][$rt]['cols'][$rc] ?? null;
                if ($a === null) { scIssue($model, 'ERROR', $fk['source'], "FK $name: column $t.$col does not exist"); continue; }
                if ($b === null) { scIssue($model, 'ERROR', $fk['source'], "FK $name: referenced column $rt.$rc does not exist"); continue; }
                if ($a['type'] !== $b['type'] || $a['unsigned'] !== $b['unsigned']) {
                    scIssue($model, 'ERROR', $fk['source'], "FK $name type mismatch: $t.$col {$a['type']}" . ($a['unsigned'] ? ' unsigned' : '') . " vs $rt.$rc {$b['type']}" . ($b['unsigned'] ? ' unsigned' : ''));
                }
            }
            $indexed = false;
            foreach ($model['tables'][$rt]['indexes'] as $ix) if (($ix['cols'][0] ?? null) === ($fk['ref_cols'][0] ?? '')) $indexed = true;
            if (!$indexed) scIssue($model, 'ERROR', $fk['source'], "FK $name: referenced $rt(" . implode(',', $fk['ref_cols']) . ') is not indexed');
        }
    }
}

// ---------------------------------------------------------------- SQL reference analysis
// Analyzes one SQL statement against the model. Returns findings:
// [certainty, kind, table, column, detail]
function scAnalyzeSql(string $sql, array $model, bool $dynamic): array {
    [$text, $lits] = scMaskLiterals($sql);
    $find = [];
    $kw = array_flip(SC_SQL_KEYWORDS);
    $aliases = []; $aliasConflict = []; $tables = []; $derived = [];
    $selectAliases = [];
    if (preg_match_all('/\bAS\s+`?([a-z_]\w*)`?/i', $text, $am)) foreach ($am[1] as $a) $selectAliases[strtolower($a)] = true;
    if (preg_match_all('/\)\s+(?:AS\s+)?`?([a-z_]\w*)`?/i', $text, $dm)) foreach ($dm[1] as $a) if (!isset($kw[strtolower($a)])) $derived[strtolower($a)] = true;
    // "ON DUPLICATE KEY UPDATE col = ..." is not a table reference.
    preg_match_all('/(?<!\bKEY\s)\b(FROM|JOIN|UPDATE|INTO)\s+(`?[a-z_][\w$]*`?(?:\.`?[a-z_]\w*`?)?|__DYN__)(?:\s+(?:AS\s+)?`?([a-z_]\w*)`?)?/i', $text, $tm, PREG_SET_ORDER);
    foreach ($tm as $m) {
        $name = scIdent($m[2]);
        if ($name === '__dyn__') { $find[] = ['DYNAMIC', 'dynamic-table', '', '', 'table name built at runtime']; continue; }
        if (str_contains($name, '.')) { [$schema] = explode('.', $name); if (in_array($schema, ['information_schema', 'mysql', 'performance_schema'], true)) continue; }
        if (isset($kw[$name]) || str_starts_with($name, '@')) continue;
        $tables[$name] = true;
        $alias = isset($m[3]) && $m[3] !== '' && !isset($kw[strtolower($m[3])]) ? strtolower($m[3]) : null;
        foreach (array_filter([$name, $alias]) as $a) {
            if (isset($aliases[$a]) && $aliases[$a] !== $name) $aliasConflict[$a] = true;
            $aliases[$a] = $name;
        }
        if (isset($model['views'][$name])) { unset($tables[$name]); $derived[$name] = true; if ($alias !== null) $derived[$alias] = true; continue; }
        if (!isset($model['tables'][$name])) $find[] = ['CONFIRMED', 'missing-table', $name, '', 'table or view not in the selected schema model'];
    }
    // A statement with a nested SELECT has several scopes; single-table
    // inference is then only a heuristic.
    $hasSubquery = preg_match_all('/\bSELECT\b/i', $text) > 1;
    // qualified alias.column
    if (preg_match_all('/(?<![\w.])`?([a-z_]\w*)`?\.`?([a-z_]\w*|\*)`?/i', $text, $qm, PREG_SET_ORDER)) {
        foreach ($qm as $m) {
            $a = strtolower($m[1]); $c = strtolower($m[2]);
            if ($c === '*' || !isset($aliases[$a]) || isset($derived[$a])) continue;
            $t = $aliases[$a];
            if (!isset($model['tables'][$t])) continue;
            if (!isset($model['tables'][$t]['cols'][$c])) {
                $find[] = [isset($aliasConflict[$a]) ? 'UNCERTAIN' : 'CONFIRMED', 'missing-column', $t, $c, isset($aliasConflict[$a]) ? "alias '$a' is reused for different tables in this statement" : "via $a.$c"];
            }
        }
    }
    // INSERT INTO t (cols)
    if (preg_match_all('/\b(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^)]*)\)/i', $text, $im, PREG_SET_ORDER)) {
        foreach ($im as $m) {
            $t = scIdent($m[1]);
            if (!isset($model['tables'][$t])) continue;
            $cols = array_filter(array_map('scIdent', explode(',', $m[2])), fn($c) => $c !== '' && $c !== '__dyn__');
            $hasDyn = str_contains($m[2], '__DYN__');
            foreach ($cols as $c) if (!isset($model['tables'][$t]['cols'][$c])) $find[] = ['CONFIRMED', 'missing-column', $t, $c, 'INSERT column list'];
            if (!$hasDyn) {
                foreach ($model['tables'][$t]['cols'] as $c => $attr) {
                    if ($attr['not_null'] && !$attr['has_default'] && !$attr['auto_increment'] && !$attr['generated'] && !in_array($c, $cols, true) && !in_array($attr['type'], ['timestamp'], true)) {
                        $find[] = [$dynamic ? 'UNCERTAIN' : 'CONFIRMED', 'insert-missing-required', $t, $c, 'NOT NULL column without DEFAULT is not supplied'];
                    }
                }
            }
        }
    }
    // single-table unqualified columns
    if (count($tables) === 1) {
        $t = array_key_first($tables);
        if (isset($model['tables'][$t])) {
            $tcols = $model['tables'][$t]['cols'];
            $check = function (string $c, string $certainty, string $where) use (&$find, $t, $tcols, $kw, $selectAliases, $aliases) {
                $c = strtolower($c);
                if (isset($kw[$c]) || isset($selectAliases[$c]) || isset($aliases[$c]) || $c === '__dyn__' || is_numeric($c)) return;
                if (!isset($tcols[$c])) $find[] = [$certainty, 'missing-column', $t, $c, $where];
            };
            // UPDATE t SET a = ..., b = ...
            if (preg_match('/^\s*UPDATE\s+`?\w+`?\s+(?:AS\s+\w+\s+)?SET\s+(.*?)(?:\bWHERE\b|$)/is', $text, $sm)) {
                foreach (scSplitTop($sm[1], ',') as $asg) if (preg_match('/^\s*`?([a-z_]\w*)`?\s*=/i', $asg, $x)) $check($x[1], 'CONFIRMED', 'UPDATE SET');
            }
            // WHERE cannot reference SELECT aliases, so in a literal single-table
            // statement without subqueries an unqualified WHERE column must be
            // a column of that table (CONFIRMED); otherwise it stays a heuristic.
            $single = (!$hasSubquery && !$dynamic) ? 'CONFIRMED' : 'UNCERTAIN';
            if (preg_match('/\bWHERE\b(.*?)(?:\bGROUP\s+BY\b|\bORDER\s+BY\b|\bLIMIT\b|\bFOR\s+UPDATE\b|$)/is', $text, $wm)) {
                if (preg_match_all('/(?<![\w.`])`?([a-z_]\w*)`?\s*(?:=|<>|!=|<=|>=|<|>|\bIN\b|\bIS\b|\bLIKE\b|\bBETWEEN\b)/i', $wm[1], $x)) foreach ($x[1] as $c) $check($c, $single, 'WHERE (single-table statement)');
            }
            if (preg_match('/^\s*SELECT\s+(.*?)\s+FROM\b/is', $text, $slm)) {
                foreach (scSplitTop($slm[1], ',') as $piece) if (preg_match('/^\s*(?:DISTINCT\s+)?`?([a-z_]\w*)`?(?:\s+(?:AS\s+)?\w+)?\s*$/i', $piece, $x)) $check($x[1], $single, 'SELECT list (single-table statement)');
            }
        }
    }
    // ENUM literal checks: [alias.]col = 'x' / col IN ('x','y')
    if (preg_match_all("/(?<![\w.`])(?:`?([a-z_]\w*)`?\.)?`?([a-z_]\w*)`?\s*(=|<>|!=|\bIN\s*\()\s*((?:'#\d+'\s*,?\s*)+)/i", $text, $em, PREG_SET_ORDER)) {
        foreach ($em as $m) {
            $col = strtolower($m[2]); $t = null;
            if ($m[1] !== '') { $a = strtolower($m[1]); $t = $aliases[$a] ?? null; }
            elseif (count($tables) === 1) $t = array_key_first($tables);
            if ($t === null || !isset($model['tables'][$t]['cols'][$col])) continue;
            $enum = $model['tables'][$t]['cols'][$col]['enum'];
            if ($enum === null) continue;
            preg_match_all("/'#(\d+)'/", $m[4], $ids);
            foreach ($ids[1] as $id) {
                $v = $lits[(int)$id] ?? '';
                if (!in_array($v, $enum, true)) {
                    $inSet = (bool)preg_match('/\bSET\b[^;]*' . preg_quote($m[0], '/') . '/is', $text) && !preg_match('/\bWHERE\b[^;]*' . preg_quote($m[0], '/') . '/is', $text);
                    $find[] = [$inSet ? 'CONFIRMED' : 'UNCERTAIN', 'enum-value', $t, $col, "value '$v' not in ENUM(" . implode(',', $enum) . ')' . ($inSet ? ' (assignment)' : ' (comparison never matches)')];
                }
            }
        }
    }
    if ($dynamic) $find[] = ['DYNAMIC', 'dynamic-sql', implode(',', array_keys($tables)), '', 'statement built at runtime; only its literal parts were checked'];
    return $find;
}

// ---------------------------------------------------------------- PHP scanning
// Extracts SQL "string expressions" from PHP source: literal pieces joined by
// '.', with non-literal operands and interpolations replaced by __DYN__.
// Returns [line, sql, dynamic, fragmentOnly].
function scExtractSqlFromPhp(string $code): array {
    $tokens = token_get_all($code);
    $n = count($tokens); $out = [];
    $isStr = fn($t) => is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING], true);
    $sig = function (int $i, int $dir) use ($tokens, $n) {
        for ($j = $i + $dir; $j >= 0 && $j < $n; $j += $dir) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            return $j;
        }
        return -1;
    };
    $i = 0;
    while ($i < $n) {
        $t = $tokens[$i];
        $startsInterp = ($t === '"') || (is_array($t) && $t[0] === T_START_HEREDOC);
        if (!$isStr($t) && !$startsInterp) { $i++; continue; }
        // start of a string expression
        $line = is_array($t) ? $t[2] : 0;
        if ($line === 0) { for ($k = $i; $k < $n; $k++) if (is_array($tokens[$k])) { $line = $tokens[$k][2]; break; } }
        $text = ''; $dyn = false;
        $prev = $sig($i, -1);
        $concatAssign = $prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_CONCAT_EQUAL;
        if ($prev >= 0 && $tokens[$prev] === '.') { $dyn = true; $text .= ' __DYN__ '; }
        while ($i < $n) {
            $t = $tokens[$i];
            if ($isStr($t)) {
                $s = $t[1]; $q = $s[0];
                $body = substr($s, 1, -1);
                $text .= $q === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
                $i++;
            } elseif ($t === '"' || (is_array($t) && $t[0] === T_START_HEREDOC)) {
                $end = $t === '"' ? '"' : T_END_HEREDOC;
                $i++;
                $depth = 0;
                while ($i < $n) {
                    $u = $tokens[$i];
                    if ($depth === 0 && (($end === '"' && $u === '"') || (is_array($u) && $u[0] === $end))) { $i++; break; }
                    if (is_array($u) && $u[0] === T_ENCAPSED_AND_WHITESPACE && $depth === 0) $text .= stripcslashes($u[1]);
                    elseif (is_array($u) && in_array($u[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) { $depth++; if ($depth === 1) { $text .= '__DYN__'; $dyn = true; } }
                    elseif ($u === '}' && $depth > 0) $depth--;
                    elseif (is_array($u) && $u[0] === T_VARIABLE && $depth === 0) { $text .= '__DYN__'; $dyn = true; }
                    $i++;
                }
            } else break;
            // continuation?
            $nx = $sig($i - 1, 1);
            if ($nx < 0 || $tokens[$nx] !== '.') break;
            $after = $sig($nx, 1);
            if ($after >= 0 && ($isStr($tokens[$after]) || $tokens[$after] === '"' || (is_array($tokens[$after]) && $tokens[$after][0] === T_START_HEREDOC))) { $i = $after; continue; }
            // non-literal operand: skip it to the next '.' at depth 0 or the end of the expression
            $dyn = true; $text .= ' __DYN__ ';
            $j = $after; $depth = 0;
            while ($j < $n) {
                $u = $tokens[$j];
                if ($u === '(' || $u === '[' || $u === '{') $depth++;
                elseif ($u === ')' || $u === ']' || $u === '}') { if ($depth === 0) break; $depth--; }
                elseif ($depth === 0 && ($u === ',' || $u === ';')) break;
                elseif ($depth === 0 && $u === '.') {
                    $a2 = $sig($j, 1);
                    if ($a2 >= 0 && ($isStr($tokens[$a2]) || $tokens[$a2] === '"' || (is_array($tokens[$a2]) && $tokens[$a2][0] === T_START_HEREDOC))) { $j = $a2; break; }
                }
                $j++;
            }
            if ($j < $n && ($isStr($tokens[$j]) || $tokens[$j] === '"' || (is_array($tokens[$j]) && $tokens[$j][0] === T_START_HEREDOC))) { $i = $j; continue; }
            $i = $j;
            break;
        }
        $trim = ltrim($text, " \t\r\n(");
        // SQL is recognised by an UPPER-CASE leading verb plus its basic shape,
        // so UI strings such as "Update vehicle status" are not treated as SQL.
        if (preg_match('/^(?:SELECT\b|WITH\b|INSERT\s+(?:IGNORE\s+)?INTO\b|REPLACE\s+INTO\b|DELETE\b.*\bFROM\b|UPDATE\s+\S+.*\bSET\b)/s', $trim)) {
            $out[] = [$line, $text, $dyn, false];
        } elseif (($concatAssign || $dyn) && preg_match('/^\s*(?:__DYN__\s*)?(AND|OR|WHERE|JOIN|LEFT\s+JOIN|INNER\s+JOIN|ORDER\s+BY|GROUP\s+BY|SET|LIMIT|HAVING)\b/i', $trim)) {
            $out[] = [$line, $text, true, true];
        }
    }
    return $out;
}

function scListPhpFiles(array $paths, ?array $only): array {
    $files = [];
    if ($only !== null) {
        foreach ($only as $f) if (is_file(SC_REPO_ROOT . '/' . $f)) $files[] = str_replace('\\', '/', $f);
        return $files;
    }
    foreach ($paths as $p) {
        $abs = SC_REPO_ROOT . '/' . $p;
        if (is_file($abs) && str_ends_with($abs, '.php')) { $files[] = $p; continue; }
        if (!is_dir($abs)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if (str_ends_with($f->getFilename(), '.php')) $files[] = str_replace('\\', '/', substr($f->getPathname(), strlen(SC_REPO_ROOT) + 1));
    }
    sort($files);
    return array_values(array_unique($files));
}

// ---------------------------------------------------------------- draft selection
function scSelectDrafts(string $spec): array {
    $dir = SC_REPO_ROOT . '/db/target_migrations';
    $present = array_map('basename', glob($dir . '/*.sql') ?: []);
    $ordered = array_values(array_filter(SC_DRAFT_ORDER, fn($f) => in_array($f, $present, true)));
    $unknown = array_values(array_diff($present, SC_DRAFT_ORDER));
    if ($spec === 'none' || $spec === '') return [[], $unknown];
    if ($spec === 'all') return [$ordered, $unknown];
    if ($spec === 'existing') return [array_values(array_filter($ordered, fn($f) => !str_starts_with($f, '20261014_t'))), $unknown];
    if ($spec === 't-series') return [array_values(array_filter($ordered, fn($f) => str_starts_with($f, '20261014_t'))), $unknown];
    $want = array_map('trim', explode(',', $spec));
    foreach ($want as $w) if (!in_array(basename($w), $present, true)) { fwrite(STDERR, "Draft not found: $w\n"); exit(2); }
    return [array_values(array_filter($ordered, fn($f) => in_array($f, array_map('basename', $want), true))), $unknown];
}

function scBuildModel(string $schema, array $drafts): array {
    $model = scNewModel();
    $path = SC_REPO_ROOT . '/' . $schema;
    if (!is_file($path)) { fwrite(STDERR, "Schema file not found: $schema\n"); exit(2); }
    $model['is_schema_file'] = true;
    $model['dml'][$schema] = scApplySqlFile($model, $path, $schema);
    $model['is_schema_file'] = false;
    // The schema's own view queries must reference existing tables/columns.
    foreach ($model['dml'][$schema] as $st) {
        foreach (scAnalyzeSql($st, $model, false) as $f) {
            if ($f[0] === 'CONFIRMED') scIssue($model, 'ERROR', $schema, "view/DML reference: {$f[1]} {$f[2]}" . ($f[3] !== '' ? ".{$f[3]}" : '') . " ({$f[4]})");
        }
    }
    foreach ($drafts as $d) {
        $raw = (string)file_get_contents(SC_REPO_ROOT . '/db/target_migrations/' . $d);
        $model['dml'][$d] = scApplySqlFile($model, SC_REPO_ROOT . '/db/target_migrations/' . $d, $d);
        $model['draft_raw'][$d] = $raw;
        // DML in the draft is checked against the model as it stands right after this draft.
        foreach ($model['dml'][$d] as $st) {
            foreach (scAnalyzeSql($st, $model, false) as $f) {
                if ($f[0] === 'CONFIRMED') scIssue($model, 'ERROR', $d, "DML reference: {$f[1]} {$f[2]}" . ($f[3] !== '' ? ".{$f[3]}" : '') . " ({$f[4]}) - check draft ORDER/dependencies");
            }
        }
    }
    return $model;
}

function scLintDraftText(array &$model, string $d, string $raw): void {
    if (!preg_match('/NOT\s+EXECUTED|NOT\s+approval\s+to\s+(?:execute|run)/i', $raw)) scIssue($model, 'WARN', $d, 'header does not state that the draft is not executed / not approved');
    if (!preg_match('/ROLLBACK/i', $raw)) scIssue($model, 'WARN', $d, 'header has no ROLLBACK section');
    if (str_contains($raw, "\r\n")) scIssue($model, 'INFO', $d, 'CRLF line endings (existing drafts use LF)');
    foreach ($model['meta']['use'] ?? [] as [$src, $db]) if ($src === $d) scIssue($model, 'ERROR', $d, "contains USE $db - drafts must not select a database");
    foreach ($model['meta']['create_database'] ?? [] as [$src]) if ($src === $d) scIssue($model, 'ERROR', $d, 'contains CREATE DATABASE');
    $code = scStripSqlComments($raw);
    if (preg_match('/\bGENERATED\s+ALWAYS\b.*?\b(STORED|VIRTUAL|PERSISTENT)\b/is', $code, $gm)) scIssue($model, 'INFO', $d, strtoupper($gm[1]) . ' generated column: needs MySQL 8 / MariaDB 10.2+ (indexed generated columns); confirm the server version (Hostinger not verified)');
    if (preg_match('/\bCHECK\s*\(/i', $code)) scIssue($model, 'INFO', $d, 'CHECK constraint: enforced on MySQL 8.0.16+ and MariaDB 10.2+; rollback uses DROP CHECK (MySQL) or DROP CONSTRAINT (MariaDB)');
    if (preg_match('/^\s*(UPDATE|INSERT)\b/im', scStripSqlComments($raw))) scIssue($model, 'INFO', $d, 'contains data-changing DML (INSERT/UPDATE)');
}

// ---------------------------------------------------------------- self-test
function scSelfTest(): int {
    $pass = 0; $fail = 0;
    $ok = function (string $name, bool $c) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; };
    $model = scNewModel();
    $tmp = tempnam(sys_get_temp_dir(), 'sc');
    file_put_contents($tmp, "-- comment; with semicolon\nCREATE TABLE users (user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(80) NOT NULL, email VARCHAR(150) NOT NULL, role_id INT UNSIGNED NOT NULL, status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active', UNIQUE KEY uq_users_username (username)) ENGINE=InnoDB;\nCREATE TABLE trips (trip_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, driver_id INT UNSIGNED NULL, CONSTRAINT fk_trips_driver FOREIGN KEY (driver_id) REFERENCES users(user_id)) ENGINE=InnoDB;\n");
    scApplySqlFile($model, $tmp, 'base');
    $ok('parser: two tables, comment ignored', count($model['tables']) === 2 && !$model['issues']);
    $ok('parser: column attributes', $model['tables']['users']['cols']['email']['not_null'] && !$model['tables']['users']['cols']['email']['has_default'] && $model['tables']['users']['cols']['user_id']['auto_increment']);
    $ok('parser: ENUM values', $model['tables']['users']['cols']['status']['enum'] === ['Active', 'Inactive']);
    file_put_contents($tmp, "ALTER TABLE trips ADD COLUMN helper_id INT NULL AFTER driver_id, ADD CONSTRAINT fk_trips_driver FOREIGN KEY (helper_id) REFERENCES users(user_id);\nALTER TABLE trips ADD COLUMN driver_id INT NULL;\nALTER TABLE nope ADD COLUMN x INT NULL;\nUSE otherdb;\nDROP TABLE trips;\n");
    $m2 = $model; scApplySqlFile($m2, $tmp, 'draft'); scCheckForeignKeys($m2); $m2['meta'] = $m2['meta'] ?? [];
    $msgs = implode("\n", array_column($m2['issues'], 'message'));
    $ok('lint: duplicate constraint name detected', str_contains($msgs, "constraint name 'fk_trips_driver'"));
    $ok('lint: duplicate column detected', str_contains($msgs, 'duplicate column trips.driver_id'));
    $ok('lint: ALTER on missing table detected', str_contains($msgs, 'ALTER TABLE nope'));
    $ok('lint: executable DROP detected', str_contains($msgs, 'executable DROP statement'));
    $ok('lint: FK signedness mismatch detected', str_contains($msgs, 'type mismatch: trips.helper_id int vs users.user_id int unsigned'));
    $ok('lint: USE recorded', ($m2['meta']['use'][0][1] ?? '') === 'otherdb');
    $f = scAnalyzeSql("SELECT u.full_name, t.trip_id FROM users u JOIN trips t ON t.driver_id = u.user_id JOIN ghost g ON g.id = u.user_id", $model, false);
    $kinds = array_map(fn($x) => $x[0] . ':' . $x[1] . ':' . $x[2] . '.' . $x[3], $f);
    $ok('scan: missing column via alias is CONFIRMED', in_array('CONFIRMED:missing-column:users.full_name', $kinds, true));
    $ok('scan: missing table is CONFIRMED', in_array('CONFIRMED:missing-table:ghost.', $kinds, true));
    $ok('scan: existing column not reported', !in_array('CONFIRMED:missing-column:trips.trip_id', $kinds, true));
    $f = scAnalyzeSql("INSERT INTO users (username, role_id) VALUES (?, ?)", $model, false);
    $ok('scan: INSERT missing NOT NULL column (email) CONFIRMED', (bool)array_filter($f, fn($x) => $x[0] === 'CONFIRMED' && $x[1] === 'insert-missing-required' && $x[3] === 'email'));
    $f = scAnalyzeSql("UPDATE users SET status = 'Deleted' WHERE user_id = ?", $model, false);
    $ok('scan: ENUM assignment outside values CONFIRMED', (bool)array_filter($f, fn($x) => $x[0] === 'CONFIRMED' && $x[1] === 'enum-value'));
    $f = scAnalyzeSql("SELECT username FROM users WHERE nickname = ?", $model, false);
    $ok('scan: literal single-table WHERE column is CONFIRMED', (bool)array_filter($f, fn($x) => $x[0] === 'CONFIRMED' && $x[3] === 'nickname'));
    $f = scAnalyzeSql("SELECT username FROM users WHERE __DYN__ AND nickname = ?", $model, true);
    $ok('scan: dynamic single-table WHERE column is UNCERTAIN', (bool)array_filter($f, fn($x) => $x[0] === 'UNCERTAIN' && $x[3] === 'nickname'));
    $f = scAnalyzeSql("INSERT INTO users (username, email, role_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = 'Active'", $model, false);
    $ok('scan: ON DUPLICATE KEY UPDATE col is not a table', !array_filter($f, fn($x) => $x[1] === 'missing-table'));
    $f = scAnalyzeSql("SELECT username FROM users WHERE nickname = ? AND user_id IN (SELECT driver_id FROM trips)", $model, false);
    $ok('scan: unqualified column in a multi-table statement is never CONFIRMED', !array_filter($f, fn($x) => $x[0] === 'CONFIRMED' && $x[3] === 'nickname'));
    $mv = $model; $mv['views']['v_users'] = 'base';
    $f = scAnalyzeSql("SELECT v.anything FROM v_users v", $mv, false);
    $ok('scan: known view accepted, its columns not guessed', !$f);
    $ok('extract: UI text "Update vehicle status" is not SQL', scExtractSqlFromPhp('<?php $l = ["Update vehicle status", "Delete from trip list"];') === []);
    $php = '<?php $a = $pdo->prepare("SELECT * FROM users WHERE user_id = ?"); $b = $pdo->prepare("SELECT * FROM {$table} WHERE x = 1"); $c = $pdo->prepare("SELECT id FROM users WHERE " . $where); $sql .= " AND status = ?"; jsonFail("Could not update the record from here");';
    $ex = scExtractSqlFromPhp($php);
    $ok('extract: 3 SQL statements + 1 fragment, error message ignored', count($ex) === 4 && $ex[3][3] === true);
    $ok('extract: interpolated table marked dynamic', $ex[1][2] === true && str_contains($ex[1][1], '__DYN__'));
    $ok('extract: concatenated operand marked dynamic', $ex[2][2] === true);
    $ok('extract: plain literal not dynamic', $ex[0][2] === false);
    @unlink($tmp);
    echo "self-test: passed $pass, failed $fail\n";
    return $fail === 0 ? 0 : 1;
}

// ---------------------------------------------------------------- main
$opt = scParseArgs($argv);
if ($opt['help']) { echo scUsage(); exit(0); }
if ($opt['self_test']) exit(scSelfTest());

if ($opt['out'] !== null) {
    $outDir = realpath(dirname($opt['out']));
    $repo = realpath(SC_REPO_ROOT);
    if ($outDir === false) { fwrite(STDERR, "Output directory does not exist: " . dirname($opt['out']) . "\n"); exit(2); }
    if (stripos(str_replace('\\', '/', $outDir) . '/', str_replace('\\', '/', $repo) . '/') === 0) { fwrite(STDERR, "Refusing to write inside the repository: {$opt['out']}\n"); exit(2); }
}

$lintAll = $opt['lint'] && $opt['drafts'] === 'none';
[$drafts, $unknownDrafts] = scSelectDrafts($lintAll ? 'all' : $opt['drafts']);
$model = scBuildModel($opt['schema'], $drafts);
scCheckForeignKeys($model, $opt['lint'] ? $drafts : []);
if ($opt['lint']) {
    foreach ($drafts as $d) scLintDraftText($model, $d, $model['draft_raw'][$d]);
    foreach ($unknownDrafts as $u) scIssue($model, 'WARN', $u, 'draft is not in the recommended order list (not checked)');
    foreach ($model['meta']['use'] ?? [] as [$src, $db]) if ($src === $opt['schema']) scIssue($model, 'INFO', $src, "schema file contains USE $db (and CREATE DATABASE): importing it unmodified targets database '$db'");
}

$findings = [];
$fragments = 0; $files = [];
if ($opt['scan']) {
    $files = scListPhpFiles($opt['paths'], $opt['only']);
    foreach ($files as $file) {
        foreach (scExtractSqlFromPhp((string)file_get_contents(SC_REPO_ROOT . '/' . $file)) as [$line, $sql, $dyn, $frag]) {
            if ($frag) { $fragments++; $findings[] = ['DYNAMIC', 'sql-fragment', '', '', 'SQL fragment appended at runtime (not checked)', "$file:$line"]; continue; }
            foreach (scAnalyzeSql($sql, $model, $dyn) as $f) { $f[] = "$file:$line"; $findings[] = $f; }
        }
    }
}

// de-duplicate identical findings at the same location
$uniq = [];
foreach ($findings as $f) $uniq[implode('|', $f)] = $f;
$findings = array_values($uniq);
if (!$opt['show_uncertain']) $findings = array_values(array_filter($findings, fn($f) => $f[0] === 'CONFIRMED'));
usort($findings, fn($a, $b) => [$a[0], $a[5], $a[2], $a[3]] <=> [$b[0], $b[5], $b[2], $b[3]]);

$counts = ['CONFIRMED' => 0, 'UNCERTAIN' => 0, 'DYNAMIC' => 0];
foreach ($findings as $f) $counts[$f[0]]++;
$lintCounts = ['ERROR' => 0, 'WARN' => 0, 'INFO' => 0];
foreach ($model['issues'] as $i) $lintCounts[$i['level']]++;

$report = '';
if ($opt['format'] === 'json') {
    $report = json_encode(['schema' => $opt['schema'], 'drafts' => $drafts, 'files_scanned' => count($files), 'counts' => $counts,
        'lint' => $opt['lint'] ? ['counts' => $lintCounts, 'issues' => $model['issues']] : null,
        'findings' => array_map(fn($f) => array_combine(['certainty', 'kind', 'table', 'column', 'detail', 'location'], $f), $findings)], JSON_PRETTY_PRINT) . "\n";
} elseif ($opt['format'] === 'csv') {
    $h = fopen('php://temp', 'w+');
    fputcsv($h, ['certainty', 'kind', 'table', 'column', 'detail', 'location']);
    foreach ($findings as $f) fputcsv($h, $f);
    if ($opt['lint']) foreach ($model['issues'] as $i) fputcsv($h, ['LINT-' . $i['level'], 'draft-lint', '', '', $i['message'], $i['source']]);
    rewind($h); $report = stream_get_contents($h); fclose($h);
} else {
    $report .= "STATIC SCHEMA CHECK (no database connection, no SQL executed)\n";
    $report .= "Schema model: {$opt['schema']}" . ($drafts ? ' + ' . count($drafts) . ' draft(s): ' . implode(', ', $drafts) : ' (no drafts)') . "\n";
    $report .= 'Model: ' . count($model['tables']) . " tables\n";
    if ($opt['lint']) {
        $report .= "\nDRAFT LINT: {$lintCounts['ERROR']} error(s), {$lintCounts['WARN']} warning(s), {$lintCounts['INFO']} info\n";
        foreach (['ERROR', 'WARN', 'INFO'] as $lvl) foreach ($model['issues'] as $i) if ($i['level'] === $lvl) $report .= "  [$lvl] {$i['source']}: {$i['message']}\n";
    }
    if ($opt['scan']) {
        $report .= "\nAPPLICATION SQL SCAN: " . count($files) . " file(s); CONFIRMED {$counts['CONFIRMED']}, UNCERTAIN {$counts['UNCERTAIN']}, DYNAMIC {$counts['DYNAMIC']}\n";
        $report .= "  CONFIRMED = definite mismatch with the model; UNCERTAIN = heuristic, verify by hand;\n  DYNAMIC = SQL built at runtime, only literal parts checked.\n";
        foreach (['CONFIRMED', 'UNCERTAIN', 'DYNAMIC'] as $lvl) {
            $rows = array_filter($findings, fn($f) => $f[0] === $lvl);
            if (!$rows) continue;
            $report .= "\n== $lvl ==\n";
            foreach ($rows as $f) $report .= sprintf("  %-26s %-22s %-28s %s  [%s]\n", $f[5], $f[1], $f[2] . ($f[3] !== '' ? '.' . $f[3] : ''), $f[4], $lvl);
        }
    }
}

if ($opt['out'] !== null) { file_put_contents($opt['out'], $report); echo "Report written to {$opt['out']}\n"; echo "CONFIRMED {$counts['CONFIRMED']}, UNCERTAIN {$counts['UNCERTAIN']}, DYNAMIC {$counts['DYNAMIC']}" . ($opt['lint'] ? "; lint errors {$lintCounts['ERROR']}, warnings {$lintCounts['WARN']}" : '') . "\n"; }
else echo $report;

if ($opt['fail_on_confirmed'] && ($counts['CONFIRMED'] > 0 || $lintCounts['ERROR'] > 0)) exit(1);
exit(0);
