<?php
/**
 * Searchbox — AJAX autocomplete endpoint.
 * GET ?type=products&q=agar  → JSON {results:[{fill,html},...]}
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
$limit = 10;

if (strlen($q) < 2 || empty($type)) {
    echo '{"results":[]}';
    exit;
}

$sq  = $db->escape($q);
$ent = (int)$conf->entity;
$out = [];

// ── Helpers ──────────────────────────────────────────────────────────────────

function sb_row(string $ref, string $label): array
{
    $ref_h   = htmlspecialchars($ref,   ENT_QUOTES);
    $label_h = htmlspecialchars($label, ENT_QUOTES);
    $html = $ref_h
        ? '<span class="sb-ref">' . $ref_h . '</span><span class="sb-sep">—</span><span class="sb-label">' . $label_h . '</span>'
        : '<span class="sb-label">' . $label_h . '</span>';
    return ['fill' => $label ?: $ref, 'html' => $html];
}

function sb_ref_row(string $ref, string $name): array
{
    $ref_h  = htmlspecialchars($ref,  ENT_QUOTES);
    $name_h = htmlspecialchars($name, ENT_QUOTES);
    return [
        'fill' => $ref,
        'html' => '<span class="sb-ref">' . $ref_h . '</span><span class="sb-sep">—</span><span class="sb-label">' . $name_h . '</span>',
    ];
}

// ── Queries ───────────────────────────────────────────────────────────────────

if ($type === 'products') {
    $res = $db->query(
        "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product"
      . " WHERE entity=$ent AND (ref LIKE '%$sq%' OR label LIKE '%$sq%')"
      . " ORDER BY label LIMIT $limit"
    );
    while ($res && $row = $db->fetch_object($res)) {
        $r = sb_ref_row($row->ref, (string)$row->label);
        $r['url'] = DOL_URL_ROOT . '/product/card.php?id=' . (int)$row->rowid;
        $out[] = $r;
    }

} elseif ($type === 'societe') {
    $res = $db->query(
        "SELECT nom, code_client FROM " . MAIN_DB_PREFIX . "societe"
      . " WHERE entity=$ent AND (nom LIKE '%$sq%' OR code_client LIKE '%$sq%')"
      . " ORDER BY nom LIMIT $limit"
    );
    while ($res && $row = $db->fetch_object($res)) {
        $code = $row->code_client ? ' (' . htmlspecialchars($row->code_client, ENT_QUOTES) . ')' : '';
        $out[] = [
            'fill' => $row->nom,
            'html' => '<span class="sb-label">' . htmlspecialchars($row->nom, ENT_QUOTES) . '</span>'
                    . '<span class="sb-sep">' . $code . '</span>',
        ];
    }

} elseif ($type === 'contact') {
    $res = $db->query(
        "SELECT firstname, lastname, email FROM " . MAIN_DB_PREFIX . "socpeople"
      . " WHERE entity=$ent AND (lastname LIKE '%$sq%' OR firstname LIKE '%$sq%' OR email LIKE '%$sq%')"
      . " ORDER BY lastname LIMIT $limit"
    );
    while ($res && $row = $db->fetch_object($res)) {
        $name = trim($row->firstname . ' ' . $row->lastname);
        $email_h = $row->email ? ' <span class="sb-sep">&lt;' . htmlspecialchars($row->email, ENT_QUOTES) . '&gt;</span>' : '';
        $out[] = [
            'fill' => $row->lastname,
            'html' => '<span class="sb-label">' . htmlspecialchars($name, ENT_QUOTES) . '</span>' . $email_h,
        ];
    }

} elseif ($type === 'propal') {
    $res = $db->query(
        "SELECT p.ref, s.nom FROM " . MAIN_DB_PREFIX . "propal p"
      . " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = p.fk_soc"
      . " WHERE p.entity=$ent AND (p.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')"
      . " ORDER BY p.ref DESC LIMIT $limit"
    );
    while ($res && $row = $db->fetch_object($res)) {
        $out[] = sb_ref_row($row->ref, (string)$row->nom);
    }

} elseif ($type === 'commande') {
    $res = $db->query(
        "SELECT c.ref, s.nom FROM " . MAIN_DB_PREFIX . "commande c"
      . " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = c.fk_soc"
      . " WHERE c.entity=$ent AND (c.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')"
      . " ORDER BY c.ref DESC LIMIT $limit"
    );
    while ($res && $row = $db->fetch_object($res)) {
        $out[] = sb_ref_row($row->ref, (string)$row->nom);
    }

} elseif ($type === 'facture') {
    $res = $db->query(
        "SELECT f.ref, s.nom FROM " . MAIN_DB_PREFIX . "facture f"
      . " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc"
      . " WHERE f.entity=$ent AND (f.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')"
      . " ORDER BY f.ref DESC LIMIT $limit"
    );
    while ($res && $row = $db->fetch_object($res)) {
        $out[] = sb_ref_row($row->ref, (string)$row->nom);
    }

} elseif ($type === 'fourn_commande') {
    $res = $db->query(
        "SELECT c.ref, s.nom FROM " . MAIN_DB_PREFIX . "commande_fournisseur c"
      . " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = c.fk_soc"
      . " WHERE c.entity=$ent AND (c.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')"
      . " ORDER BY c.ref DESC LIMIT $limit"
    );
    while ($res && $row = $db->fetch_object($res)) {
        $out[] = sb_ref_row($row->ref, (string)$row->nom);
    }

} elseif ($type === 'fourn_facture') {
    $res = $db->query(
        "SELECT f.ref, s.nom FROM " . MAIN_DB_PREFIX . "facture_fourn f"
      . " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc"
      . " WHERE f.entity=$ent AND (f.ref LIKE '%$sq%' OR s.nom LIKE '%$sq%')"
      . " ORDER BY f.ref DESC LIMIT $limit"
    );
    while ($res && $row = $db->fetch_object($res)) {
        $out[] = sb_ref_row($row->ref, (string)$row->nom);
    }

} elseif ($type === 'projet') {
    $res = $db->query(
        "SELECT ref, title FROM " . MAIN_DB_PREFIX . "projet"
      . " WHERE entity=$ent AND (ref LIKE '%$sq%' OR title LIKE '%$sq%')"
      . " ORDER BY ref DESC LIMIT $limit"
    );
    while ($res && $row = $db->fetch_object($res)) {
        $out[] = sb_ref_row($row->ref, (string)$row->title);
    }
}

echo json_encode(['results' => $out]);
