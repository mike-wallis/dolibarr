# STP & SSP — ATO Reporting Plan

**Researched:** June 2026  
**Audience:** Michael / future Claude sessions  
**Status:** Planning — Reckon licence runs to ~May 2027; SSP transition due before then

---

## The Two-Layer Model

The ATO separates two roles:

| Role | What it does | Who |
|------|-------------|-----|
| **DSP** (Digital Service Provider) | Calculates pay, produces the PAYEVNT data, responsible for accuracy | Dolibarr Payroll Module (eventually) |
| **SSP** (Sending Service Provider) | Wraps payroll data in an SBR ebMS3 XML envelope and transmits to ATO. Handles auth, schemas, retry logic, responses. | Third-party gateway (e.g. SuperChoice) |

**Key point:** the payroll module only needs to produce *STP-compliant data* (CSV, TXT, or XML with all required PAYEVNT fields). The SSP handles all protocol complexity.

---

## Current Plan

- Continue using **Reckon** for STP submission until licence expires (~May 2027)
- During that time: select an SSP, confirm their data format, build export in the Dolibarr payroll module
- After Reckon: submit STP via chosen SSP using export from Dolibarr payroll module

---

## Data the Module Must Export (PAYEVNT Phase 2)

STP Phase 2 submissions are **YTD cumulative** — not per-payrun. Each event updates running totals for all active employees.

### Submission Header
- Employer ABN
- BMS (software) name, version, Product ID
- SSID — 10-digit Software Subscription ID issued by SSP for this employer/software combo (stored in module config)
- Pay event date

### Per-Employee (YTD cumulative)

| Field | Module status |
|-------|--------------|
| TFN | Stored encrypted in `payroll_employee.tfn_encrypted` |
| Full name, date of birth | Name in user record; DOB not stored yet |
| Employment type (full-time, part-time, casual…) | `payroll_employee.position_type` — mapped on export |
| Income type (salary & wages, WHM…) | Not stored; default SAW |
| Tax scale | `payroll_employee.tax_scale` |
| YTD gross income | Sum of `payroll_payrun_line.gross` for FY |
| YTD PAYG withheld | Sum of `payroll_payrun_line.payg` for FY |
| YTD HECS/study loan | Extracted from `payroll_payrun_line.deductions_json` (key `HECS`) |
| YTD super guarantee | Sum of `payroll_payrun_line.super_amount` for FY |
| YTD salary sacrifice | Not tracked yet |
| YTD reportable employer super | Not tracked yet |
| YTD reportable fringe benefits | Not tracked yet |
| Employment start/end dates | Not stored in payroll module |
| Address | In Dolibarr user record |

The YTD export function is at `custom/modules/payroll/stp_export.php`.

---

## SSP Integration Options

| Model | How it works | Effort |
|-------|-------------|--------|
| **File upload** | Module generates CSV/XML; Michael uploads to SSP's portal after each payrun | Low — realistic first step |
| **Direct API** | Module POSTs to SSP's API after payrun | Medium — once SSP is confirmed |
| **Intermediary-initiated** | BAS agent submits through SSP | N/A for self-lodgement |

---

## SSP Providers Researched (June 2026)

### SuperChoice — Most Relevant
**superchoiceservices.com** — ABN 78 109 509 739

- Explicitly offers STP as a gateway service *to DSPs*
- Products: ATO Gateway, STP submission, Clearing House, Payday Super (ready 1 July 2026)
- Explicitly lists "Digital Service Providers" as target market
- Contact: **sales@superchoice.com.au**

**Questions to ask SuperChoice:**
1. Do they accept third-party payroll data from small DSPs (single-business install)?
2. What file format / API do they accept from the DSP side?
3. What is the SSID issuance process?
4. What does it cost?

### Reckon GovConnect STP
Reckon's internal ATO transmission pipe — not available as a standalone SSP. Cannot be used after Reckon licence ends.

### Other (full payroll software, not SSP gateways)
- **Employment Hero / KeyPay** — full payroll DSP, not a gateway
- **CloudPayroll** — full payroll; has developer API at dev.cloudpayroll.com.au
- **Microkeeper** — full payroll; $2.25/user/month for payroll + STP (possible fallback)
- **Payroll Metrics** — full payroll, ISO 27001 certified
- **Iotas** — wrong company (resolves to wireless device testing firm)
- **Transmission / Paypa Plane** — both DNS errors; possibly defunct

### Full SSP Register
The ATO product register at `softwaredevelopers.ato.gov.au/product-register` links to a separate "Sending service providers" register listing all accredited SSPs. Browse for additional options.

---

## About the SSID

The SSP issues a unique 10-digit **SSID (Software Subscription ID)** per employer/software combination. Must be Modulo-10 valid and registered in ATO Access Manager. A settings field for this is needed in the payroll module (not yet added).

---

## Employment Basis Mapping (position_type → STP code)

| Module code | Description | STP code |
|-------------|-------------|----------|
| FT | Full Time | F |
| FTT | Full Time Temporary | F |
| PT | Part Time | P |
| CA | Casual | C |
| CAPT | Casual Part Time | C |
| AP | Apprentice | P |
| O | Other | P |

---

## Next Steps

1. Contact SuperChoice (sales@superchoice.com.au) for DSP integration details
2. Browse the ATO SSP register for additional options
3. Add missing employee fields: income type, salary sacrifice, reportable super, start/end dates
4. Build YTD export CSV in payroll module (`stp_export.php`) — **done**
5. Add SSID config field to module settings
6. File upload first; API once SSP confirmed

---

*Source: Playwright research against ATO softwaredevelopers site, ATO product register, SuperChoice, KeyPay, Employment Hero, CloudPayroll, Microkeeper, Reckon — June 2026.*
