<?php
/**
 * Searchbox — AJAX autocomplete endpoint.
 * GET ?type=products&q=agar         → single-term search
 * GET ?type=products&q=agr+deg      → AND search: both terms must match
 * Returns JSON {results:[{fill,html},...], total:N}
 * total is only present when there are more matches than the display limit.
 */

define('NOCSRFCHECK',    1);
define('NOTOKENRENEWAL', 1);
define('NOHEADER',       1);
define('NOFOOTER',       1);

$res = 0;
if (!$res && is_file('../../main.inc.php'))   { require '../../main.inc.php';   $res = 1; }
if (!$res && is_file('../../../main.inc.php')) { require '../../../main.inc.php'; $res = 1; }
if (!$res) { http_response_code(500); die('{}'); }

header('Content-Type: application/json; charset=utf-8');

if (empty($user->id)) {
    http_response_code(403);
    echo '{"error":"Not authenticated"}';
    exit;
}

$type  = GETPOST('type', 'aZ09');
$q     = trim(GETPOST('q', 'alphanohtml'));
$limit = 8;   // items shown; we query limit+1 to detect overflow

if (strlen($q) < 2 || empty($type)) {
    echo '{"results":[]}';
    exit;
}

// Split on '+' and drop any term shorter than 2 chars (still being typed)
$terms = array_values(array_filter(
    array_map('trim', explode('+', $q)),
    function ($t) { return strlen($t) >= 2; }
));

if (empty($terms)) {
    echo '{"results":[]}';
    exit;
}

$ent   = (int)$conf->entity;
$out   = [];
$total = null;

// ── Helpers ──────────────────────────────────────────────────────────────────

function sb_ref_row(string $ref, string $name): array
{
    $ref_h  = htmlspecialchars($ref,  ENT_QUOTES);
    $name_h = htmlspecialchars($name, ENT_QUOTES);
    return [
        'fill' => $ref,
        'html' => '<span class="sb-ref">' . $ref_h . '</span>'
                . '<span class="sb-sep">—</span>'
                . '<span class="sb-label">' . $name_h . '</span>',
    ];
}

/**
 * Build WHERE for multi-term search: all terms must match within the same field.
 * Result: ((f1 LIKE '%t1%' AND f1 LIKE '%t2%') OR (f2 LIKE '%t1%' AND f2 LIKE '%t2%'))
 * $fields — array of SQL column expressions e.g. ['ref','label'] or ['p.ref','s.nom']
 */
function sb_terms_where(array $terms, array $fields): string
{
    global $db;
    $field_parts = [];
    foreach ($fields as $f) {
        $and_parts = array_map(function ($term) use ($f) {
            global $db;
            $st = $db->escape($term);
            return "$f LIKE '%$st%'";
        }, $terms);
        $field_parts[] = '(' . implode(' AND ', $and_parts) . ')';
    }
    return '(' . implode(' OR ', $field_parts) . ')';
}

/** COUNT for a single-table WHERE (no JOIN). */
function sb_count(string $table, string $where): int
{
    global $db;
    $cr = $db->query("SELECT COUNT(*) as n FROM $table WHERE $where");
    if ($cr && $row = $db->fetch_object($cr)) return (int)$row->n;
    return 0;
}

// ── Queries ───────────────────────────────────────────────────────────────────

if ($type === 'products') {
    $tw    = sb_terms_where($terms, ['ref', 'label']);
    $where = "entity=$ent AND $tw";
    $res = $db->query(
        "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product"
      . " WHERE $where ORDER BY label LIMIT " . ($limit + 1)
    );
    while ($res && $row = $db->fetch_object($res)) {
        $r = sb_ref_row($row->ref, (string)$row->label);
        $r['url'] = DOL_URL_ROOT . '/product/card.php?id=' . (int)$row->rowid;
        $out[] = $r;
    }
    if (count($out) > $limit) {
        array_pop($out);
        $total = sb_count(MAIN_DB_PREFIX . 'product', $where);
    }

} elseif ($type === 'societe') {
    $tw    = sb_terms_where($terms, ['nom', 'code_client']);
    $where = "entity=$ent AND $tw";
    $res = $db->query(
        "SELECT nom, code_client FROM " . MAIN_DB_PREFIX . "societe"
      . " WHERE $where ORDER BY nom LIMIT " . ($limit + 1)
    );
    while ($res && $row = $db->fetch_object($res)) {
        $code = $row->code_client ? ' (' . htmlspecialchars($row->code_client, ENT_QUOTES) . ')' : '';
        $out[] = [
            'fill' => $row->nom,
            'html' => '<span class="sb-label">' . htmlspecialchars($row->nom, ENT_QUOTES) . '</span>'
                    . '<span class="sb-sep">' . $code . '</span>',
        ];
    }
    if (count($out) > $limit) {
        array_pop($out);
        $total = sb_count(MAIN_DB_PREFIX . 'societe', $where);
    }

} elseif ($type === 'contact') {
    $tw    = sb_terms_where($terms, ['lastname', 'firstname', 'email']);
    $where = "entity=$ent AND $tw";
    $res = $db->query(
        "SELECT firstname, lastname, email FROM " . MAIN_DB_PREFIX . "socpeople"
      . " WHERE $where ORDER BY lastname LIMIT " . ($limit + 1)
    );
    while ($res && $row = $db->fetch_object($res)) {
        $name    = trim($row->firstname . ' ' . $row->lastname);
        $email_h = $row->email ? ' <span class="sb-sep">&lt;' . htmlspecialchars($row->email, ENT_QUOTES) . '&gt;</span>' : '';
        $out[] = [
            'fill' => $row->lastname,
            'html' => '<span class="sb-label">' . htmlspecialchars($name, ENT_QUOTES) . '</span>' . $email_h,
        ];
    }
    if (count($out) > $limit) {
        array_pop($out);
        $total = sb_count(MAIN_DB_PREFIX . 'socpeople', $where);
    }

} elseif ($type === 'propal') {
    $join  = "LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = p.fk_soc";
    $tw    = sb_terms_where($terms, ['p.ref', 's.nom']);
    $where = "p.entity=$ent AND $tw";
    $res = $db->query(
        "SELECT p.ref, s.nom FROM " . MAIN_DB_PREFIX . "propal p $join"
      . " WHERE $where ORDER BY p.ref DESC LIMIT " . ($limit + 1)
    );
    while ($res && $row = $db->fetch_object($res)) { $out[] = sb_ref_row($row->ref, (string)$row->nom); }
    if (count($out) > $limit) {
        array_pop($out);
        $cr = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "propal p $join WHERE $where");
        if ($cr && $row = $db->fetch_object($cr)) $total = (int)$row->n;
    }

} elseif ($type === 'commande') {
    $join  = "LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = c.fk_soc";
    $tw    = sb_terms_where($terms, ['c.ref', 's.nom']);
    $where = "c.entity=$ent AND $tw";
    $res = $db->query(
        "SELECT c.ref, s.nom FROM " . MAIN_DB_PREFIX . "commande c $join"
      . " WHERE $where ORDER BY c.ref DESC LIMIT " . ($limit + 1)
    );
    while ($res && $row = $db->fetch_object($res)) { $out[] = sb_ref_row($row->ref, (string)$row->nom); }
    if (count($out) > $limit) {
        array_pop($out);
        $cr = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "commande c $join WHERE $where");
        if ($cr && $row = $db->fetch_object($cr)) $total = (int)$row->n;
    }

} elseif ($type === 'facture') {
    $join  = "LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc";
    $tw    = sb_terms_where($terms, ['f.ref', 's.nom']);
    $where = "f.entity=$ent AND $tw";
    $res = $db->query(
        "SELECT f.ref, s.nom FROM " . MAIN_DB_PREFIX . "facture f $join"
      . " WHERE $where ORDER BY f.ref DESC LIMIT " . ($limit + 1)
    );
    while ($res && $row = $db->fetch_object($res)) { $out[] = sb_ref_row($row->ref, (string)$row->nom); }
    if (count($out) > $limit) {
        array_pop($out);
        $cr = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "facture f $join WHERE $where");
        if ($cr && $row = $db->fetch_object($cr)) $total = (int)$row->n;
    }

} elseif ($type === 'fourn_commande') {
    $join  = "LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = c.fk_soc";
    $tw    = sb_terms_where($terms, ['c.ref', 's.nom']);
    $where = "c.entity=$ent AND $tw";
    $res = $db->query(
        "SELECT c.ref, s.nom FROM " . MAIN_DB_PREFIX . "commande_fournisseur c $join"
      . " WHERE $where ORDER BY c.ref DESC LIMIT " . ($limit + 1)
    );
    while ($res && $row = $db->fetch_object($res)) { $out[] = sb_ref_row($row->ref, (string)$row->nom); }
    if (count($out) > $limit) {
        array_pop($out);
        $cr = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "commande_fournisseur c $join WHERE $where");
        if ($cr && $row = $db->fetch_object($cr)) $total = (int)$row->n;
    }

} elseif ($type === 'fourn_facture') {
    $join  = "LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc";
    $tw    = sb_terms_where($terms, ['f.ref', 's.nom']);
    $where = "f.entity=$ent AND $tw";
    $res = $db->query(
        "SELECT f.ref, s.nom FROM " . MAIN_DB_PREFIX . "facture_fourn f $join"
      . " WHERE $where ORDER BY f.ref DESC LIMIT " . ($limit + 1)
    );
    while ($res && $row = $db->fetch_object($res)) { $out[] = sb_ref_row($row->ref, (string)$row->nom); }
    if (count($out) > $limit) {
        array_pop($out);
        $cr = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "facture_fourn f $join WHERE $where");
        if ($cr && $row = $db->fetch_object($cr)) $total = (int)$row->n;
    }

} elseif ($type === 'projet') {
    $tw    = sb_terms_where($terms, ['ref', 'title']);
    $where = "entity=$ent AND $tw";
    $res = $db->query(
        "SELECT ref, title FROM " . MAIN_DB_PREFIX . "projet"
      . " WHERE $where ORDER BY ref DESC LIMIT " . ($limit + 1)
    );
    while ($res && $row = $db->fetch_object($res)) { $out[] = sb_ref_row($row->ref, (string)$row->title); }
    if (count($out) > $limit) {
        array_pop($out);
        $total = sb_count(MAIN_DB_PREFIX . 'projet', $where);
    }
}

$response = ['results' => $out];
if ($total !== null) $response['total'] = $total;
echo json_encode($response);
