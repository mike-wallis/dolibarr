# Brand Constants

How brand-specific values (name, phone, email, logo, etc.) are stored and edited for multi-brand invoicing.

---

## Overview

South Side Supplies runs two brands from one Dolibarr installation:

| Brand | Invoice template | Colour |
|---|---|---|
| Bright Cleaning Solutions Pty Ltd | `brightcs` | Green |
| South Side Supplies Pty Ltd | `southside` | Blue |

Both share the same ABN, bank account, chart of accounts, customers, and products. Only the document output differs.

Brand-specific values are stored as constants in the database (`llx_const`) so they can be edited through the UI without touching any PHP files.

---

## Editing brand values

**Home → Setup → Other setup**

Each constant is a row in the list. Click the pencil icon to edit a value.

| BCS constant | SSS constant | What it controls |
|---|---|---|
| `BCS_NAME` | `SSS_NAME` | Company name on the invoice header |
| `BCS_ADDR1` | `SSS_ADDR1` | Address line 1 |
| `BCS_ADDR2` | `SSS_ADDR2` | Address line 2 |
| `BCS_PHONE` | `SSS_PHONE` | Phone number shown on invoice |
| `BCS_EMAIL` | `SSS_EMAIL` | Email shown on invoice |
| `BCS_BSB` | `SSS_BSB` | BSB in footer payment details |
| `BCS_ACC` | `SSS_ACC` | Account number in footer |
| `BCS_TAGLINE` | `SSS_TAGLINE` | Bold green text at bottom of footer |
| `BCS_OWNERSHIP` | `SSS_OWNERSHIP` | Italic ownership note in footer |
| `BCS_LOGO` | `SSS_LOGO` | Logo filename (see Logos below) |

ABN is shared — it comes from **Setup → Company/Organisation → SIREN/ABN** and is used by both templates.

---

## Initial setup SQL

Run once to seed all constants. After that, edit via the UI.

```sql
INSERT INTO llx_const (name, value, type, entity) VALUES
-- BCS
('BCS_NAME',      'Bright Cleaning Solutions Pty Ltd',                                       'chaine', 1),
('BCS_ADDR1',     '70 Brisbane Corso',                                                        'chaine', 1),
('BCS_ADDR2',     'Fairfield QLD 4103',                                                       'chaine', 1),
('BCS_PHONE',     '0401 130 096',                                                             'chaine', 1),
('BCS_EMAIL',     'accounts@brightcs.com.au',                                                'chaine', 1),
('BCS_BSB',       '182-512',                                                                  'chaine', 1),
('BCS_ACC',       '000974446429',                                                             'chaine', 1),
('BCS_TAGLINE',   'Great Products, Great Service and Really Looking after our Customers.',    'chaine', 1),
('BCS_OWNERSHIP', 'Ownership of the goods does not pass until payment is received in full.',  'chaine', 1),
-- SSS
('SSS_NAME',      'South Side Supplies Pty Ltd',                                             'chaine', 1),
('SSS_ADDR1',     '70 Brisbane Corso',                                                        'chaine', 1),
('SSS_ADDR2',     'Fairfield QLD 4103',                                                       'chaine', 1),
('SSS_PHONE',     '0431 779 857',                                                             'chaine', 1),
('SSS_EMAIL',     'southsidesupplies.yes@gmail.com',                                         'chaine', 1),
('SSS_BSB',       '182-512',                                                                  'chaine', 1),
('SSS_ACC',       '000974446429',                                                             'chaine', 1),
('SSS_TAGLINE',   'Fast local delivery',                                                      'chaine', 1),
('SSS_OWNERSHIP', 'Ownership of the goods does not pass until payment is received in full.',  'chaine', 1),
('SSS_LOGO',      'southside_logo.png',                                                       'chaine', 1)
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

Also register the southside template if not already done:

```sql
INSERT INTO llx_document_model (nom, type, entity) VALUES ('southside', 'invoice', 1);
```

---

## Logos

Logo files are stored in `documents\mycompany\logos\`.

| Brand | File | Upload method |
|---|---|---|
| BCS | Set via **Setup → Company/Organisation → Logo** (Dolibarr manages the filename) | UI upload |
| SSS | `southside_logo.png` — copied manually to `documents\mycompany\logos\` | Manual file copy |

When `BCS_LOGO` is empty (or not set), the BCS template uses whatever logo is configured in Setup → Company. The SSS template always uses `SSS_LOGO`.

To replace the SSS logo, copy the new file to `documents\mycompany\logos\` and update `SSS_LOGO` to the new filename via **Home → Setup → Other setup**.

---

## How it works in the templates

Both templates (`pdf_brightcs` and `pdf_southside`) extend the same PHP class. The only difference is:

- `pdf_brightcs` reads constants prefixed `BCS_`
- `pdf_southside` reads constants prefixed `SSS_`

The templates read from the DB first; if a constant is not set, they fall back to the hardcoded defaults in `brand_defaults()` inside the PHP file. This means the invoice will still render correctly even before the SQL above is run.

---

## Adding a third brand

1. Create `htdocs/core/modules/facture/doc/pdf_<brandname>.modules.php`:

```php
require_once DOL_DOCUMENT_ROOT . '/core/modules/facture/doc/pdf_brightcs.modules.php';

class pdf_<brandname> extends pdf_brightcs
{
    const CLR_GREEN = [R, G, B];  // brand colour

    protected $brand_prefix = 'XYZ';  // prefix for llx_const keys

    public function __construct($db)
    {
        parent::__construct($db);
        $this->name        = '<brandname>';
        $this->description = 'Brand Name invoice';
    }

    protected function brand_defaults(): array
    {
        return [
            'NAME'  => 'Brand Name Pty Ltd',
            // ... other defaults
        ];
    }
}
```

2. Register in the DB: `INSERT INTO llx_document_model (nom, type, entity) VALUES ('<brandname>', 'invoice', 1);`

3. Add `XYZ_*` constants via **Home → Setup → Other setup** or the seed SQL above.
