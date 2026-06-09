<?php
/**
 * Searchbox — hook handler.
 *
 * Detects the current list page, checks whether the admin has enabled
 * autocomplete for it, then injects the CSS + JS into the page footer.
 */
class ActionsSearchbox
{
    public $db;
    public $errors    = [];
    public $resprints = '';

    // Keyed by type string.
    // input_sel  — CSS selector for the input to attach autocomplete to.
    // fill_param — GET param name written into the filter URL on selection.
    private static $PAGE_TYPES = [
        'products' => [
            'const'      => 'SEARCHBOX_ENABLE_PRODUCTS',
            'path'       => '/product/list.php',
            'input_sel'  => 'input[name="search_label"]',
            'fill_param' => 'search_ref',   // filter by ref so natural_search doesn't mangle the label
        ],
        'societe' => [
            'const'      => 'SEARCHBOX_ENABLE_SOCIETE',
            'path'       => '/societe/list.php',
            'input_sel'  => 'input[name="search_nom"]',
            'fill_param' => 'search_nom',
        ],
        'contact' => [
            'const'      => 'SEARCHBOX_ENABLE_CONTACT',
            'path'       => '/contact/list.php',
            'input_sel'  => 'input[name="search_lastname"]',
            'fill_param' => 'search_lastname',
        ],
        'propal' => [
            'const'      => 'SEARCHBOX_ENABLE_PROPAL',
            'path'       => '/comm/propal/list.php',
            'input_sel'  => 'input[name="search_ref"]',
            'fill_param' => 'search_ref',
        ],
        'commande' => [
            'const'      => 'SEARCHBOX_ENABLE_COMMANDE',
            'path'       => '/commande/list.php',
            'input_sel'  => 'input[name="search_ref"]',
            'fill_param' => 'search_ref',
        ],
        'facture' => [
            'const'      => 'SEARCHBOX_ENABLE_FACTURE',
            'path'       => '/compta/facture/list.php',
            'input_sel'  => 'input[name="search_ref"]',
            'fill_param' => 'search_ref',
        ],
        'fourn_commande' => [
            'const'      => 'SEARCHBOX_ENABLE_FOURN_COMMANDE',
            'path'       => '/fourn/commande/list.php',
            'input_sel'  => 'input[name="search_ref"]',
            'fill_param' => 'search_ref',
        ],
        'fourn_facture' => [
            'const'      => 'SEARCHBOX_ENABLE_FOURN_FACTURE',
            'path'       => '/fourn/facture/list.php',
            'input_sel'  => 'input[name="search_ref"]',
            'fill_param' => 'search_ref',
        ],
        'projet' => [
            'const'      => 'SEARCHBOX_ENABLE_PROJET',
            'path'       => '/projet/list.php',
            'input_sel'  => 'input[name="search_ref"]',
            'fill_param' => 'search_ref',
        ],
    ];

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
    {
        global $user;

        if (empty($user->id)) return 0;

        $self = $_SERVER['PHP_SELF'] ?? '';

        $matched_type = null;
        $matched_cfg  = null;
        foreach (self::$PAGE_TYPES as $type => $cfg) {
            if (substr($self, -strlen($cfg['path'])) === $cfg['path']) {
                if (getDolGlobalString($cfg['const'])) {
                    $matched_type = $type;
                    $matched_cfg  = $cfg;
                    break;
                }
            }
        }

        if (!$matched_cfg) return 0;

        $ajax_url   = dol_escape_js(DOL_URL_ROOT . '/custom/searchbox/ajax.php');
        $css_url    = dol_escape_htmltag(DOL_URL_ROOT . '/custom/searchbox/css/searchbox.css');
        $type_j     = dol_escape_js($matched_type);
        $input_sel  = dol_escape_js($matched_cfg['input_sel']);
        $fill_param = dol_escape_js($matched_cfg['fill_param']);

        ob_start();
        echo '<link rel="stylesheet" href="' . $css_url . '">' . "\n";
        $this->outputJs($ajax_url, $type_j, $input_sel, $fill_param);
        $this->resprints = ob_get_clean();

        return 0;
    }

    private function outputJs(
        string $ajax_url,
        string $type,
        string $input_sel,
        string $fill_param
    ): void {
        echo <<<JS
<script>
(function () {
    var SB_AJAX       = '$ajax_url';
    var SB_TYPE       = '$type';
    var SB_SEL        = '$input_sel';
    var SB_FILL_PARAM = '$fill_param';

    var input = document.querySelector(SB_SEL);
    if (!input) return;

    // Suppress the browser's own autocomplete popup
    input.setAttribute('autocomplete', 'off');

    var dropdown = null;
    var items    = [];
    var active   = -1;
    var timer    = null;

    function getRect() {
        var r = input.getBoundingClientRect();
        return {
            top:   r.bottom + window.scrollY,
            left:  r.left   + window.scrollX,
            width: r.width,
        };
    }

    function showDropdown(results) {
        closeDropdown();
        if (!results.length) return;
        var pos = getRect();
        dropdown = document.createElement('ul');
        dropdown.className = 'sb-dropdown';
        dropdown.style.top   = pos.top  + 'px';
        dropdown.style.left  = pos.left + 'px';
        dropdown.style.minWidth = Math.max(220, pos.width) + 'px';
        items  = results;
        active = -1;
        results.forEach(function (r, i) {
            var li = document.createElement('li');
            li.innerHTML = r.html;
            li.addEventListener('mousedown', function (e) { e.preventDefault(); });
            li.addEventListener('click', function () { selectItem(i); });
            dropdown.appendChild(li);
        });
        document.body.appendChild(dropdown);
    }

    function closeDropdown() {
        if (dropdown) { dropdown.remove(); dropdown = null; }
        items  = [];
        active = -1;
    }

    function setActive(idx) {
        if (!dropdown) return;
        var lis = dropdown.querySelectorAll('li');
        lis.forEach(function (li) { li.classList.remove('sb-active'); });
        active = Math.max(-1, Math.min(idx, lis.length - 1));
        if (active >= 0) lis[active].classList.add('sb-active');
    }

    function selectItem(idx) {
        var r = items[idx];
        if (!r) return;
        closeDropdown();
        // Preserve all existing URL params (sortfield, type, contextpage, etc.)
        // and just replace the filter field + reset pagination
        var params = new URLSearchParams(window.location.search);
        params.set(SB_FILL_PARAM, r.fill);
        params.set('button_search', '1');
        params.delete('page');
        window.location.href = window.location.pathname + '?' + params.toString();
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { closeDropdown(); return; }
        timer = setTimeout(function () {
            fetch(SB_AJAX + '?type=' + SB_TYPE + '&q=' + encodeURIComponent(q), {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (d) { d.results ? showDropdown(d.results) : closeDropdown(); })
                .catch(closeDropdown);
        }, 300);
    });

    input.addEventListener('keydown', function (e) {
        if (!dropdown) return;
        if      (e.key === 'ArrowDown')              { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp')                { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Enter' && active >= 0)   { e.preventDefault(); selectItem(active); }
        else if (e.key === 'Escape')                 { closeDropdown(); }
    });

    // Re-show dropdown on focus if input already has text
    input.addEventListener('focus', function () {
        if (input.value.trim().length >= 2 && !dropdown) {
            input.dispatchEvent(new Event('input'));
        }
    });

    document.addEventListener('click', function (e) {
        if (dropdown && !dropdown.contains(e.target) && e.target !== input) {
            closeDropdown();
        }
    });

    window.addEventListener('scroll', closeDropdown, {passive: true});
    window.addEventListener('resize', closeDropdown, {passive: true});
}());
</script>
JS;
    }
}
