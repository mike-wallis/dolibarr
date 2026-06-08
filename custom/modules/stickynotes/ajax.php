<?php
/**
 * Sticky Notes — AJAX endpoint.
 * Handles: create, update, move, delete, toggle_vis
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
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$uid    = (int)$user->id;
$action = GETPOST('action', 'aZ09');

// ── Helpers ──────────────────────────────────────────────────────────────────

function sn_user_name(int $uid, $db): string
{
    $res = $db->query("SELECT TRIM(CONCAT(IFNULL(firstname,''),' ',IFNULL(lastname,''))) AS n"
                    . " FROM " . MAIN_DB_PREFIX . "user WHERE rowid=" . $uid);
    if ($res && $obj = $db->fetch_object($res)) return trim((string)$obj->n);
    return '';
}

function sn_note_html(object $note, int $uid): string
{
    // Inline class unavailable here — reproduce the render logic
    $id     = (int)$note->rowid;
    $vis    = ($note->visibility === 'public') ? 'public' : 'private';
    $x      = (int)round((float)$note->pos_x);
    $y      = (int)round((float)$note->pos_y);
    $w      = max(180, (int)$note->width);
    $h      = max(130, (int)$note->height);
    $title  = htmlspecialchars((string)$note->title,           ENT_QUOTES);
    $body   = htmlspecialchars((string)($note->content ?? ''), ENT_QUOTES);
    $author = htmlspecialchars(trim((string)$note->author_name));
    $isOwn  = ((int)$note->fk_user === $uid);
    $own    = $isOwn ? '1' : '0';

    $vis_icon = $vis === 'public'
        ? '<i class="fas fa-globe-americas" title="Public — visible to all users"></i>'
        : '<i class="fas fa-lock" title="Private — only you can see this"></i>';

    $vis_btn = $isOwn
        ? '<button type="button" class="sn-btn sn-vis-btn" title="Toggle visibility">' . $vis_icon . '</button>'
        : '';
    $del_btn = $isOwn
        ? '<button type="button" class="sn-btn sn-del-btn" title="Delete note"><i class="fas fa-times"></i></button>'
        : '';

    $ro_title  = $isOwn ? '' : ' readonly';
    $ro_body   = $isOwn ? '' : ' readonly';
    $vis_label = $vis === 'public' ? 'Public' : 'Private';

    $rots = [-2.0, -1.5, -1.0, 1.0, 1.5, 2.0];
    $rot  = $rots[$id % count($rots)];

    return '<div class="sn-note sn-' . $vis . '" id="sn-note-' . $id . '"'
         . ' data-id="' . $id . '" data-own="' . $own . '"'
         . ' style="left:' . $x . 'px;top:' . $y . 'px;width:' . $w . 'px;height:' . $h . 'px;--sn-rot:' . $rot . 'deg;">'
         . '<div class="sn-header">'
         . '<input type="text" class="sn-title"' . $ro_title
         . ' placeholder="Title\xe2\x80\xa6" value="' . $title . '">'
         . '</div>'
         . '<textarea class="sn-body"' . $ro_body . ' placeholder="Type your note\xe2\x80\xa6">'
         . $body . '</textarea>'
         . '<div class="sn-foot">'
         . '<span class="sn-author">' . $author . ' &bull; ' . $vis_label . '</span>'
         . '<div class="sn-btns">' . $vis_btn . $del_btn . '</div>'
         . '</div>'
         . '</div>';
}

// ── Actions ───────────────────────────────────────────────────────────────────

if ($action === 'create') {
    $page_url = GETPOST('page_url', 'nohtml');
    $page_url = preg_replace('/[^\x20-\x7E]/', '', $page_url);
    $page_url = substr($page_url, 0, 500);

    if (empty($page_url) || $page_url[0] !== '/') {
        echo json_encode(['ok' => false, 'error' => 'Invalid page_url']);
        exit;
    }

    $x   = round((float)GETPOST('x', 'int'), 2);
    $y   = round((float)GETPOST('y', 'int'), 2);
    $now = $db->idate(dol_now());

    $sql = "INSERT INTO " . MAIN_DB_PREFIX . "stickynotes"
         . " (fk_user, page_url, title, content, visibility, pos_x, pos_y, width, height, date_create, date_modify)"
         . " VALUES ($uid, '" . $db->escape($page_url) . "', '', '', 'private',"
         . " $x, $y, 220, 200, '$now', '$now')";

    if ($db->query($sql) === false) {
        echo json_encode(['ok' => false, 'error' => $db->lasterror()]);
        exit;
    }

    $id = $db->last_insert_id(MAIN_DB_PREFIX . 'stickynotes');
    $note = (object)[
        'rowid'       => $id,
        'fk_user'     => $uid,
        'title'       => '',
        'content'     => '',
        'visibility'  => 'private',
        'pos_x'       => $x,
        'pos_y'       => $y,
        'width'       => 220,
        'height'      => 200,
        'author_name' => sn_user_name($uid, $db),
    ];

    echo json_encode(['ok' => true, 'id' => $id, 'html' => sn_note_html($note, $uid)]);
    exit;
}

if ($action === 'update') {
    $id      = (int)GETPOSTINT('id');
    $title   = substr(strip_tags(GETPOST('title',   'none')), 0, 200);
    $content = strip_tags(GETPOST('content', 'none'));
    $now     = $db->idate(dol_now());

    $sql = "UPDATE " . MAIN_DB_PREFIX . "stickynotes"
         . " SET title='"   . $db->escape($title)   . "',"
         . "     content='" . $db->escape($content) . "',"
         . "     date_modify='$now'"
         . " WHERE rowid=$id AND fk_user=$uid";

    $db->query($sql);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'move') {
    $id = (int)GETPOSTINT('id');
    $x  = round((float)GETPOST('x', 'int'), 2);
    $y  = round((float)GETPOST('y', 'int'), 2);
    $w  = max(180, (int)GETPOSTINT('w'));
    $h  = max(130, (int)GETPOSTINT('h'));

    // Anyone who can see the note can reposition it
    $sql = "UPDATE " . MAIN_DB_PREFIX . "stickynotes"
         . " SET pos_x=$x, pos_y=$y, width=$w, height=$h"
         . " WHERE rowid=$id"
         . " AND (fk_user=$uid OR visibility='public')";

    $db->query($sql);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete') {
    $id = (int)GETPOSTINT('id');
    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "stickynotes WHERE rowid=$id AND fk_user=$uid");
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'toggle_vis') {
    $id = (int)GETPOSTINT('id');

    $res = $db->query(
        "SELECT n.visibility,"
      . " TRIM(CONCAT(IFNULL(u.firstname,''),' ',IFNULL(u.lastname,''))) AS author_name"
      . " FROM " . MAIN_DB_PREFIX . "stickynotes n"
      . " LEFT JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = n.fk_user"
      . " WHERE n.rowid=$id AND n.fk_user=$uid"
    );

    if (!$res || $db->num_rows($res) === 0) {
        echo json_encode(['ok' => false, 'error' => 'Not found or not owner']);
        exit;
    }

    $row     = $db->fetch_object($res);
    $new_vis = ($row->visibility === 'public') ? 'private' : 'public';
    $now     = $db->idate(dol_now());

    $db->query("UPDATE " . MAIN_DB_PREFIX . "stickynotes"
             . " SET visibility='$new_vis', date_modify='$now'"
             . " WHERE rowid=$id AND fk_user=$uid");

    $author    = htmlspecialchars(trim((string)$row->author_name));
    $vis_label = ($new_vis === 'public') ? 'Public' : 'Private';
    $foot_html = $author . ' &bull; ' . $vis_label;

    echo json_encode(['ok' => true, 'visibility' => $new_vis, 'foot_html' => $foot_html]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
