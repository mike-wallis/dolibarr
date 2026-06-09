<?php
/**
 * Searchbox — AJAX autocomplete endpoint.
 * GET ?type=products&q=agar  → JSON {results:[{fill,html},...], total:N}
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

$sq  = $db->escape($q);
$ent = (int)$conf->entity;
$out = [];
$total = null;

// ── Helpers ──────────────────────────────────────────────────────────────────

function sb_ref_row(string $ref, string $name): array
{
    $ref_h  = htmlspecialchars($ref,  ENT_QUOTES);
    $name_h = htmlspecialchars($name, ENT_QUOTES);
    return [
        'fill' => $ref,
        'html' => '<span class="sb-ref">' . $ref_h . '</span><span class="sb-sep">—</span><span class="sb-label">' . $name_h . '</span>',
    ];
}

// Fetch count for a simple single-table WHERE (no JOIN needed).
function sb_count(string $table, string $where): int
{
    global $db;
    $cr = $db->query("SELECT COUNT(*) as n FROM $table WHERE $where");
    if ($cr && $row = $db->fetch_object($cr)) return (int)$row->n;
    return 0;
}

// ── Queries ───────────────────────────────────────────────────────────────────

if ($type === 'products') {
    $where = "entity=$ent AND (ref LIKE '%$sq%' OR label LIKE '%$sq%')";
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
    $where = "entity=$ent AND (nom LIKE '%$sq%' OR code_client LIKE '%$sq%')";
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
    $where = "entity=$ent AND (lastname LIKE '%$sq%' OR firstname LIKE '%$sq%' OR email LIKE '%$sq%')";
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
    $where = "p.entity=$ent AND (p.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')";
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
    $where = "c.entity=$ent AND (c.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')";
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
    $where = "f.entity=$ent AND (f.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')";
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
    $where = "c.entity=$ent AND (c.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')";
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
    $where = "f.entity=$ent AND (f.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')";
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
    $where = "entity=$ent AND (ref LIKE '%$sq%' OR title LIKE '%$sq%')";
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
