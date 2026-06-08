<?php
/**
 * Sticky Notes — Dolibarr module descriptor.
 *
 * Enable at: Setup > Modules/Applications > Sticky Notes
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modStickynotes extends DolibarrModules
{
    public function __construct($db)
    {
        parent::__construct($db);

        $this->numero         = 500010;
        $this->rights_class   = 'stickynotes';
        $this->family         = 'other';
        $this->picto          = 'note';
        $this->name           = 'Stickynotes';
        $this->description    = 'Add draggable, resizable sticky notes to any Dolibarr page.';
        $this->version        = '1.0';
        $this->const_name     = 'MAIN_MODULE_STICKYNOTES';
        $this->editor_name    = 'South Side Supplies';
        $this->config_page_url = ['setup.php@stickynotes'];

        $this->module_parts = [
            'hooks' => ['main'],
        ];
    }

    public function init($options = '')
    {
        $sql = "CREATE TABLE IF NOT EXISTS `" . MAIN_DB_PREFIX . "stickynotes` (
            `rowid`       int(11)       NOT NULL AUTO_INCREMENT,
            `fk_user`     int(11)       NOT NULL,
            `page_url`    varchar(500)  NOT NULL,
            `title`       varchar(200)  NOT NULL DEFAULT '',
            `content`     text,
            `visibility`  varchar(10)   NOT NULL DEFAULT 'private',
            `pos_x`       decimal(10,2) NOT NULL DEFAULT '100.00',
            `pos_y`       decimal(10,2) NOT NULL DEFAULT '100.00',
            `width`       int(11)       NOT NULL DEFAULT '220',
            `height`      int(11)       NOT NULL DEFAULT '200',
            `date_create` datetime      NOT NULL,
            `date_modify` datetime      NOT NULL,
            PRIMARY KEY (`rowid`),
            KEY `idx_sn_page` (`page_url`(191)),
            KEY `idx_sn_user` (`fk_user`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        if ($this->db->query($sql) === false) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        return $this->_init([], $options);
    }

    public function remove($options = '')
    {
        return $this->_remove([], $options);
    }
}
