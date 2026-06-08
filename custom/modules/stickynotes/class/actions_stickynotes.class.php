<?php
/**
 * Sticky Notes — hook handler.
 *
 * Fires on every full-page render via the 'main' hook context.
 * Injects sticky notes + CSS + JS into the page footer.
 */
class ActionsStickynotes
{
    public $db;
    public $errors    = [];
    public $resprints = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Hook: printCommonFooter
     * Called by llxFooter() at the bottom of every full-page render.
     */
    public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
    {
        global $user;

        if (empty($user->id)) return 0;

        $self = $_SERVER['PHP_SELF'] ?? '';

        // Skip our own pages to avoid loops
        if (strpos($self, '/stickynotes/') !== false) return 0;

        // Build page key: PHP_SELF + ?id=N when present (record-specific notes)
        $page_url = $self;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) $page_url .= '?id=' . $id;

        // Colors from setup (with defaults)
        $cp = getDolGlobalString('STICKYNOTES_COLOR_PRIVATE') ?: '#fef9c3';
        $cu = getDolGlobalString('STICKYNOTES_COLOR_PUBLIC')  ?: '#bbf7d0';

        // Fetch notes visible to this user on this page
        $safe_url = $this->db->escape($page_url);
        $uid = (int)$user->id;

        $sql = "SELECT n.rowid, n.fk_user, n.title, n.content, n.visibility,"
             . " n.pos_x, n.pos_y, n.width, n.height,"
             . " CONCAT(TRIM(IFNULL(u.firstname,'')), ' ', TRIM(IFNULL(u.lastname,''))) AS author_name"
             . " FROM "  . MAIN_DB_PREFIX . "stickynotes n"
             . " LEFT JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = n.fk_user"
             . " WHERE n.page_url = '$safe_url'"
             . " AND (n.visibility = 'public' OR n.fk_user = $uid)"
             . " ORDER BY n.rowid";

        $res   = $this->db->query($sql);
        $notes = [];
        if ($res) {
            while ($row = $this->db->fetch_object($res)) {
                $notes[] = $row;
            }
        }

        ob_start();
        $this->outputCss($cp, $cu);

        echo '<div id="sn-container">';
        foreach ($notes as $note) {
            $this->outputNoteHtml($note, $uid);
        }
        echo '</div>';

        $img_url = dol_escape_htmltag(DOL_URL_ROOT . '/custom/stickynotes/img/post-it.png');
        echo '<div id="sn-add-btn" title="Add sticky note" style="display:none;">'
           . '<img src="' . $img_url . '" style="width:28px;height:28px;display:block;" alt="Add sticky note">'
           . '</div>';

        $this->outputJs($page_url);

        $this->resprints = ob_get_clean();
        return 0;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function outputCss(string $cp, string $cu): void
    {
        $cp = htmlspecialchars($cp, ENT_QUOTES);
        $cu = htmlspecialchars($cu, ENT_QUOTES);
        echo <<<CSS
<style id="sn-styles">
#sn-container{position:absolute;top:0;left:0;width:0;height:0;overflow:visible;pointer-events:none;}
.sn-note{
    position:absolute;z-index:800;
    pointer-events:auto;
    border-radius:5px;
    box-shadow:3px 4px 14px rgba(0,0,0,.22);
    display:flex;flex-direction:column;
    resize:both;overflow:hidden;
    min-width:180px;min-height:130px;
    font-size:13px;font-family:inherit;
    transform:rotate(var(--sn-rot,-1.5deg));
    transition:transform 0.18s ease, box-shadow 0.18s ease;
}
.sn-note:focus-within,
.sn-note.sn-active{
    transform:rotate(0deg);
    box-shadow:5px 6px 22px rgba(0,0,0,.38);
    z-index:900;
}
.sn-private{background:$cp;}
.sn-public{background:$cu;}
.sn-header{
    display:flex;align-items:center;
    padding:10px 10px 8px;
    cursor:move;
    border-bottom:1px solid rgba(0,0,0,.10);
    flex-shrink:0;
    min-height:32px;
}
.sn-title{
    flex:1;border:none;background:transparent;
    font-weight:bold;font-size:13px;
    outline:none;color:inherit;padding:0;min-width:0;
}
.sn-title:read-only{cursor:default;}
.sn-body{
    flex:1;width:100%;box-sizing:border-box;
    border:none;background:transparent;
    resize:none;outline:none;
    font-size:12px;line-height:1.5;
    padding:6px 10px;font-family:inherit;color:inherit;
}
.sn-body:read-only{cursor:default;}
.sn-foot{
    font-size:10px;padding:3px 8px 5px;
    flex-shrink:0;
    display:flex;align-items:center;justify-content:space-between;
    border-top:1px solid rgba(0,0,0,.08);
}
.sn-author{opacity:.55;white-space:nowrap;overflow:hidden;}
.sn-btns{
    display:flex;gap:4px;flex-shrink:0;
    opacity:0;pointer-events:none;
    transition:opacity 0.15s ease;
}
.sn-note:focus-within .sn-btns,
.sn-note.sn-active .sn-btns{
    opacity:1;pointer-events:auto;
}
.sn-btn{
    cursor:pointer;font-size:14px;
    line-height:1;padding:2px 5px;background:none;
    border:none;color:inherit;opacity:.55;
    border-radius:3px;
}
.sn-btn:hover{opacity:1;background:rgba(0,0,0,.08);}
#sn-add-btn{cursor:pointer;user-select:none;opacity:.85;}
#sn-add-btn:hover{opacity:1;}
</style>
CSS;
    }

    public function outputNoteHtml(object $note, int $current_uid): void
    {
        $id     = (int)$note->rowid;
        $vis    = ($note->visibility === 'public') ? 'public' : 'private';
        $x      = (int)round((float)$note->pos_x);
        $y      = (int)round((float)$note->pos_y);
        $w      = max(180, (int)$note->width);
        $h      = max(130, (int)$note->height);
        $title  = htmlspecialchars((string)$note->title,   ENT_QUOTES);
        $body   = htmlspecialchars((string)($note->content ?? ''), ENT_QUOTES);
        $author = htmlspecialchars(trim((string)$note->author_name));
        $isOwn  = ((int)$note->fk_user === $current_uid);
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

        echo '<div class="sn-note sn-' . $vis . '" id="sn-note-' . $id . '"'
           . ' data-id="' . $id . '" data-own="' . $own . '"'
           . ' style="left:' . $x . 'px;top:' . $y . 'px;width:' . $w . 'px;height:' . $h . 'px;--sn-rot:' . $rot . 'deg;">';
        echo   '<div class="sn-header">';
        echo     '<input type="text" class="sn-title"' . $ro_title
               . ' placeholder="Title\xe2\x80\xa6" value="' . $title . '">';
        echo   '</div>';
        echo   '<textarea class="sn-body"' . $ro_body . ' placeholder="Type your note\xe2\x80\xa6">'
             . $body . '</textarea>';
        echo   '<div class="sn-foot">'
             . '<span class="sn-author">' . $author . ' &bull; ' . $vis_label . '</span>'
             . '<div class="sn-btns">' . $vis_btn . $del_btn . '</div>'
             . '</div>';
        echo '</div>';
    }

    private function outputJs(string $page_url): void
    {
        $ajax_url   = dol_escape_js(DOL_URL_ROOT . '/custom/stickynotes/ajax.php');
        $page_url_j = json_encode($page_url);

        echo <<<JS
<script>
(function () {
    var SN_AJAX   = '$ajax_url';
    var snPageUrl = $page_url_j;

    function snPost(data, cb) {
        var fd = new FormData();
        for (var k in data) fd.append(k, data[k]);
        fetch(SN_AJAX, {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(cb)
            .catch(function (e) { console.error('Stickynotes AJAX error:', e); });
    }

    function snSendPos(el) {
        var fd = new FormData();
        fd.append('action', 'move');
        fd.append('id',     el.dataset.id);
        fd.append('x',      parseFloat(el.style.left) || 0);
        fd.append('y',      parseFloat(el.style.top)  || 0);
        fd.append('w',      el.offsetWidth);
        fd.append('h',      el.offsetHeight);
        // sendBeacon survives page navigation; fall back to fetch
        if (navigator.sendBeacon) {
            navigator.sendBeacon(SN_AJAX, fd);
        } else {
            fetch(SN_AJAX, {method: 'POST', body: fd, credentials: 'same-origin'});
        }
    }

    function snActivate(el) {
        document.querySelectorAll('.sn-note.sn-active').forEach(function(n) {
            if (n !== el) n.classList.remove('sn-active');
        });
        el.classList.add('sn-active');
    }

    function makeDraggable(el) {
        var hdr = el.querySelector('.sn-header');
        hdr.addEventListener('mousedown', function (e) {
            // Don't start drag if clicking a button or the title input
            if (e.target.closest('.sn-btns') || e.target.tagName === 'INPUT') return;
            var sX = e.clientX, sY = e.clientY;
            var sL = parseFloat(el.style.left) || 0;
            var sT = parseFloat(el.style.top)  || 0;
            function onMove(e) {
                el.style.left = (sL + e.clientX - sX) + 'px';
                el.style.top  = (sT + e.clientY - sY) + 'px';
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup',  onUp);
                snSendPos(el); // immediate — no debounce needed for a single mouseup event
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup',  onUp);
            e.preventDefault();
        });
    }

    var saveTimers = {};

    function snSaveContent(noteEl) {
        var id = noteEl.dataset.id;
        clearTimeout(saveTimers[id]);
        saveTimers[id] = setTimeout(function () {
            snPost({
                action:  'update',
                id:      id,
                title:   noteEl.querySelector('.sn-title').value,
                content: noteEl.querySelector('.sn-body').value
            }, function () {});
        }, 800);
    }

    function initNote(el) {
        var isOwn = (el.dataset.own === '1');

        el.addEventListener('mousedown', function() { snActivate(el); });

        // Everyone can reposition notes
        makeDraggable(el);
        if (window.ResizeObserver) {
            var roTimer;
            new ResizeObserver(function () {
                clearTimeout(roTimer);
                roTimer = setTimeout(function () { snSendPos(el); }, 600);
            }).observe(el);
        }

        if (!isOwn) return;

        var titleEl = el.querySelector('.sn-title');
        var bodyEl  = el.querySelector('.sn-body');

        titleEl.addEventListener('input', function () { snSaveContent(el); });
        titleEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); bodyEl.focus(); }
        });
        bodyEl.addEventListener('input', function () { snSaveContent(el); });

        var delBtn = el.querySelector('.sn-del-btn');
        if (delBtn) {
            delBtn.addEventListener('click', function () {
                if (!confirm('Delete this note?')) return;
                snPost({action: 'delete', id: el.dataset.id}, function (r) {
                    if (r.ok) el.remove();
                });
            });
        }

        var visBtn = el.querySelector('.sn-vis-btn');
        if (visBtn) {
            visBtn.addEventListener('click', function () {
                snPost({action: 'toggle_vis', id: el.dataset.id}, function (r) {
                    if (!r.ok) return;
                    el.classList.toggle('sn-private', r.visibility === 'private');
                    el.classList.toggle('sn-public',  r.visibility === 'public');
                    var foot = el.querySelector('.sn-foot');
                    if (foot) foot.innerHTML = r.foot_html;
                    var icon = visBtn.querySelector('i');
                    if (icon) {
                        icon.className = r.visibility === 'public'
                            ? 'fas fa-globe-americas'
                            : 'fas fa-lock';
                        icon.title = r.visibility === 'public'
                            ? 'Public — visible to all users'
                            : 'Private — only you can see this';
                    }
                });
            });
        }
    }

    // Move container to body so notes are page-relative (scroll with content)
    var snContainer = document.getElementById('sn-container');
    if (snContainer && document.body) {
        document.body.appendChild(snContainer);
    }

    // Initialise notes already on the page
    document.querySelectorAll('.sn-note').forEach(initNote);

    // Deactivate all notes when clicking outside any note
    document.addEventListener('mousedown', function(e) {
        if (!e.target.closest || !e.target.closest('.sn-note')) {
            document.querySelectorAll('.sn-note.sn-active').forEach(function(n) {
                n.classList.remove('sn-active');
            });
        }
    });

    // Add-note button — inject into Dolibarr's top-right toolbar
    var addBtn = document.getElementById('sn-add-btn');
    if (addBtn) {
        // Try to place it at the left edge of the tools block (before print/help icons)
        var toolBlock = document.querySelector('.login_block_tools');
        if (toolBlock) {
            addBtn.style.display    = 'inline-block';
            addBtn.style.verticalAlign = 'middle';
            addBtn.style.padding    = '0 4px';
            toolBlock.insertBefore(addBtn, toolBlock.firstChild);
        } else {
            // Fallback: fixed bottom-right
            addBtn.style.cssText = 'display:block;position:fixed;bottom:24px;right:24px;'
                + 'z-index:9600;width:44px;height:44px;';
        }

        addBtn.addEventListener('click', function () {
            // Place near viewport centre, but offset by scroll so note is page-relative
            var x = Math.round(window.scrollX + Math.max(20, Math.min(window.innerWidth  - 240, (window.innerWidth  / 2) - 110)));
            var y = Math.round(window.scrollY + Math.max(20, Math.min(window.innerHeight - 230, (window.innerHeight / 2) - 100)));
            snPost({action: 'create', page_url: snPageUrl, x: x, y: y}, function (r) {
                if (!r.ok || !r.html) return;
                var wrap = document.createElement('div');
                wrap.innerHTML = r.html;
                var note = wrap.firstElementChild;
                document.getElementById('sn-container').appendChild(note);
                initNote(note);
                snActivate(note);
                setTimeout(function () {
                    var t = note.querySelector('.sn-title');
                    if (t) t.focus();
                }, 50);
            });
        });
    }
}());
</script>
JS;
    }
}
