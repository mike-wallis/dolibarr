# STP & SSP Research Report

**Researched:** June 2026  
**Status:** Planning — Reckon licence runs to ~May 2027; SSP transition due before then

---

## Background

Reckon Payroll currently handles STP (Single Touch Payroll) submission to the ATO. When the Reckon licence expires (~May 2027) we need an alternative. This report covers:

- How the ATO's STP submission model works (DSP vs SSP)
- What data the Dolibarr payroll module needs to produce
- Which SSP providers exist and what they offer

---

## The Two-Layer Model

The ATO separates two roles:

| Role | What it does | Who |
|------|-------------|-----|
| **DSP** (Digital Service Provider) | Calculates pay, produces the PAYEVNT data, responsible for accuracy | Dolibarr Payroll Module |
| **SSP** (Sending Service Provider) | Wraps payroll data in an SBR ebMS3 XML envelope and transmits to ATO. Handles authentication, schemas, retry logic, response handling. | Third-party gateway (e.g. SuperChoice) |

**Key point:** the payroll module only needs to produce *STP-compliant data* — CSV, TXT, or XML with all required PAYEVNT fields. The SSP handles all the protocol complexity. You do not need to implement SBR2/ebMS3 yourself.

---

## Current Plan

1. Continue using **Reckon** for STP submission until licence expires (~May 2027)
2. During that time: select an SSP, confirm their data format, build the export in the Dolibarr payroll module
3. After Reckon: submit STP via chosen SSP using the export from the Dolibarr payroll module

---

## Data the Module Must Export (PAYEVNT Phase 2)

STP Phase 2 submissions are **YTD cumulative** — not per-payrun. Each event updates running totals for all active employees.

### Submission Header

- Employer ABN
- BMS (software) name, version, Product ID
- SSID — 10-digit Software Subscription ID issued by the SSP for this employer/software combo (stored in module config — field not yet added)
- Pay event date

### Per-Employee (YTD cumulative)

| Field | Module status |
|-------|--------------|
| TFN | Stored encrypted in `payroll_employee.tfn_encrypted` |
| Full name | In Dolibarr user record |
| Date of birth | Not stored yet |
| Employment type (full-time, part-time, casual…) | `payroll_employee.position_type` — mapped on export (F/P/C) |
| Income type (salary & wages, WHM…) | Not stored; defaults to SAW on export |
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

The YTD export page is at `custom/modules/payroll/stp_export.php` (accessible from Pay Run History → STP Export).

---

## SSP Integration Options

SSPs offer three ways to integrate with payroll software:

| Model | How it works | Effort for Dolibarr |
|-------|-------------|---------------------|
| **File upload** | Module generates a CSV/XML; Michael uploads to the SSP's web portal after each payrun | Low — realistic first step |
| **Direct API** | Module POSTs to SSP's API endpoint after payrun | Medium — once SSP is confirmed |
| **Intermediary-initiated** | Registered BAS agent submits through SSP on employer's behalf | N/A for self-lodgement |

File upload is the right starting point. Once the SSP is chosen and their format is confirmed, a direct API integration can be added to auto-submit after each payrun.

---

## About the SSID

The SSP issues a unique 10-digit **SSID (Software Subscription ID)** per employer/software combination. It must be Modulo-10 valid and registered in ATO Access Manager. Once issued it becomes a stored config value in the payroll module. A settings field for this needs to be added.

---

## Employment Basis Mapping

The payroll module uses internal position type codes. STP Phase 2 requires ATO codes:

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

## SSP Providers Researched

### SuperChoice — Most Relevant for Dolibarr

**superchoiceservices.com** — ABN 78 109 509 739

- Explicitly offers STP as a gateway service *to DSPs* — "Seamless integration to enhance your product and UX"
- Has: ATO Gateway, STP submission, Clearing House, Payday Super (ready for 1 July 2026)
- Explicitly lists "Digital Service Providers" as a target market with a DSP integration path
- Established company serving super funds, DSPs, and employers
- Contact: **sales@superchoice.com.au**

**Questions to ask SuperChoice:**

1. Do they accept third-party payroll data from small DSPs (single-business install)?
2. What file format / API do they accept from the DSP side?
3. What is the SSID issuance process?
4. What does it cost?

---

### Reckon GovConnect STP

Reckon's internal ATO transmission pipe — not available as a standalone SSP for third-party software. Cannot be used after the Reckon licence ends.

---

### Full Payroll Software Packages (DSPs, not SSP gateways)

These are registered DSPs that handle STP for their own users. They are not gateways for external payroll software.

| Provider | Notes |
|----------|-------|
| **Employment Hero / KeyPay** | Full payroll DSP; not a gateway |
| **CloudPayroll** | Full payroll; developer API at dev.cloudpayroll.com.au — for managing payroll data, not third-party STP |
| **Microkeeper** | Full payroll at $2.25/user/month for Payroll + STP; possible fallback if module approach doesn't work |
| **Payroll Metrics** | Full payroll, ISO 27001 certified, has API |
| **Iotas** | Wrong company — resolves to IoTAS Australia (wireless device testing); ignore |
| **Transmission** | DNS error (ERR_NAME_NOT_RESOLVED); possibly defunct |
| **Paypa Plane** | DNS error (ERR_NAME_NOT_RESOLVED); possibly defunct |

---

### Finding the Full SSP Register

The ATO product register at `softwaredevelopers.ato.gov.au/product-register` links to a separate **"Sending service providers"** register listing all accredited SSPs. This register was not scraped in the initial research — browse it for additional options.

---

## ATO Technical Details (from softwaredevelopers.ato.gov.au)

- All STP reports are transmitted using **SBR ebMS3** — SSP handles this entirely
- STP-compliant data (from the payroll software) can be CSV, TXT, or XML — as long as all required PAYEVNT fields are present
- The SSP wraps the payload in an SBR ebMS3 envelope — **SSP must not alter the business content**
- The originating DSP (the payroll software) **remains responsible** for accuracy and for helping users correct validation errors
- Pre-submit checks the SSP performs: product metadata matches ATO registration, SSID present and valid, ABN present, PAYEVNT version current

---

## Next Steps

1. **Contact SuperChoice** (sales@superchoice.com.au) to confirm DSP integration model, data format, SSID process, and pricing
2. **Browse the ATO SSP register** (`softwaredevelopers.ato.gov.au/product-register` → Sending service providers link) for more options
3. **Add missing employee fields** to the payroll module: income type, salary sacrifice, reportable super, employment start/end dates
4. **Build YTD export CSV** — done (`stp_export.php`)
5. **Add SSID config field** to payroll module settings
6. **File upload first**; direct API integration once SSP and format are confirmed

---

*Research method: Playwright browsing of ATO softwaredevelopers site, ATO product register, SuperChoice, KeyPay/Employment Hero, CloudPayroll, Microkeeper, Reckon, and several other providers — June 2026.*
