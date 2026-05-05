# Troubleshooting: DOCX Template Not Being Filled

**Symptom**: Contract DOCX is generated but placeholders (${PLACEHOLDER}) are not replaced with chatbot data. The document looks blank or shows the template skeleton without actual values.

---

## Root Cause Analysis

There are 4 possible causes:

### 1. **Template Has NO BLANKS** ❌ Most Common
The owner's DOCX template doesn't have actual blank fields (underscores, dots, or ellipsis).
- If template has NO blanks → `process_owner_template()` finds nothing to map
- Result: Document copied as-is, no placeholders injected

**Check**: Does the template have actual blanks like `________` or `.......` or `…`?

### 2. **Blanks Not Detected** 
The template has blanks but they're not being found by `extract_paragraphs_with_blanks()`.
- May happen if blanks are in unusual location (headers, footers, tables, text boxes)
- May happen if blank markers use non-standard characters

**Check**: Error logs for "detected X blanks"

### 3. **Mapping Failed**
Blanks were found but couldn't be mapped to field names.
- Context rules failed to identify field
- AI mapping returned NONE confidence

**Check**: Error logs for "resolved X placeholder mappings"

### 4. **PhpWord Not Finding Variables**
Placeholders were injected but PhpWord TemplateProcessor doesn't find them.
- Placeholders might be split across XML runs (rare)
- PhpWord version issue

**Check**: Error logs for "template found X variables"

---

## Step-by-Step Diagnosis

### Step 1: Run Diagnostic Script

Use WP-CLI to run the diagnostic:

```bash
wp eval-file /path/to/DEBUG_TEMPLATE_FILL.php
```

Or check if you can access it via admin panel (if available).

This will tell you:
- ✓ Owner found
- ✓ Accommodation found  
- ✓ Template found
- ✓ Processed cache path status
- **✓ Number of blanks in original template**
- ✓ Number of placeholders in cached template

### Step 2: Check Error Logs

Look for these logs in `wp-content/debug.log`:

```bash
# Search for template processing logs
grep "Arriendo Facil process_owner_template" /path/to/debug.log | tail -20
```

Look for messages like:
```
detected X blanks in Y paragraphs
resolved Z placeholder mappings: [ARRENDATARIO,CEDULA_ARRENDATARIO,...]
```

**If you see "detected 0 blanks"** → Problem #1 (template has no blanks)

### Step 3: Check Fill Logs

```bash
# Search for fill_template logs  
grep "Arriendo Facil fill_template" /path/to/debug.log | tail -20
```

Look for:
```
starting fill for lease_id=123, payload_keys=[lease_id,guest_name,...]
template found 17 variables: [ARRENDATARIO,CEDULA_ARRENDATARIO,CANON,...]
prepared 17 values to set
vars_set=14, vars_blank=3, details=[...]
```

**If "vars_set" is very low** → Problem #4 or missing data in payload

### Step 4: Verify Template File Directly

Open the template DOCX in Word:
1. Do you see actual blank lines? Like: `____________________________`
2. Or do you see placeholder text like: `{GUEST_NAME}` or `[GUEST_NAME]`?
3. Or is the template fully filled (no blanks at all)?

**If template is already fully filled** → Not a problem, that's expected. Each lease gets a new copy.

**If template has `{PLACEHOLDER}` or `[PLACEHOLDER]` text** → Not underscore/dot blanks. Need to convert first.

---

## Common Scenarios & Solutions

### Scenario A: Template has NO underscores/dots/ellipsis

**Symptoms**:
- Log shows "detected 0 blanks"
- Diagnostic shows "NO BLANKS FOUND in original template"

**Solution**:
1. Open template in Word
2. Find field labels (e.g., "Arrendatario:", "Cédula:", "Monto:", etc.)
3. Replace generic text with blanks:
   - "Arrendatario: ___________________"
   - "Monto a pagar: ........................."
   - Or use: "Arrendatario: …"
4. Save DOCX
5. Re-upload in owner panel

---

### Scenario B: Template has blanks but "resolved 0 placeholder mappings"

**Symptoms**:
- Log shows "detected 5 blanks"
- Log shows "resolved 0 placeholder mappings"
- Context too vague (blanks not near labels)

**Solution**:
1. Each blank must have clear context nearby:
   - ✓ Good: "Nombre del Arrendatario: _________________"
   - ✓ Good: "Saldo a pagar USD: ................"
   - ❌ Bad: "________________" (blank with no label)
   - ❌ Bad: Blank in middle of paragraph

2. Ensure labels include keywords:
   - For guest_name: use "Arrendatario", "Inquilino", "Tomador"
   - For owner_name: use "Arrendador", "Propietario", "Dador"
   - For monthly_rent: use "Canon", "Renta", "Monto", "Mensualidad"
   - For address: use "Ubicación", "Dirección", "Domicilio"
   - For start date: use "Inicio", "Desde", "Fecha de inicio"

3. Re-upload template

---

### Scenario C: Blanks detected, mapped, but vars_set=0

**Symptoms**:
- Log shows "detected 5 blanks" ✓
- Log shows "resolved 5 placeholder mappings" ✓
- But "vars_set=0"

**Solution**:
This means `build_placeholder_values()` is not finding data in the payload.

1. Check that chatbot form captures all fields:
   - Guest name, email, phone, ID number
   - Start date, end date (or months/years)
   - Accommodation address, title
   - Monthly rent / desired price

2. Check that chatbot data is passing through to lease creation

3. Enable detailed payload logging (add to create_lease_contract_for_guest):
   ```php
   error_log( 'Payload for contract: ' . wp_json_encode( $ai_payload ) );
   ```

---

### Scenario D: vars_set is good, but document still looks blank

**Symptoms**:
- Log shows "vars_set=14, vars_blank=3" ✓
- But final DOCX still looks empty

**Possible causes**:
1. PhpWord successfully wrote values but they're not displaying in Word viewer
   - Open file in different viewer or re-save in Word
   - Check if values are in hidden text

2. Processed template lost placeholders
   - Delete cache: clear `_af_processed_template_path` meta
   - Generate again (will reprocess from original)

3. HTML entities breaking display
   - Accents like á, é, í, ó, ú may not display correctly
   - Solution: ensure DOCX encoding is UTF-8

---

## Quick Fixes

### Clear Processed Template Cache
If template was processed but is incorrect, clear cache to reprocess:

```bash
# Via WordPress
wp post meta delete ATTACHMENT_ID _af_processed_template_path

# Or manually in DB
DELETE FROM wp_postmeta WHERE post_id = ATTACHMENT_ID 
AND meta_key = '_af_processed_template_path'
```

Then generate a new lease - it will reprocess from original.

### Force Re-upload Template
1. Delete current template from owner panel
2. Re-upload original DOCX (no changes needed, just to clear cache)
3. Generate new lease

### Check Blank Markers Are Correct

Make sure blanks use exactly one of:
- `___` (underscore, at least 3 in a row)
- `...` (dot, at least 5 in a row)  
- `…` (ellipsis character, at least 3 in a row)

NOT: `___ ___ ___` (spaced), or `..` (only 2), or mixed

---

## Technical Details

### How Blank Detection Works

1. `process_owner_template()` called on upload
2. `extract_paragraphs_with_blanks()` looks for regex: `_{3,}|\.{5,}|…{3,}|\t+`
   - Underscore: 3+ in a row
   - Dot: 5+ in a row
   - Ellipsis: 3+ in a row
   - Tab: any tabs

3. `resolve_blank_to_placeholder_order()` maps each blank to a field
   - Uses context rules (looks at text before/after)
   - Falls back to AI mapping (only HIGH confidence)
   - If no match, uses CAMPO_N (safe blank)

4. `inject_placeholders()` replaces blank with ${FIELD_NAME}
5. `fill_template()` uses PhpWord to replace ${FIELD_NAME} with actual value

### Why Template Needs Blanks

If template has no blanks:
- No placeholders injected
- Document treated as "already complete"
- PhpWord has nothing to find/replace
- Result: template returned as-is

---

## Debug Logging Checklist

Enable detailed logging by checking these in error logs:

```
✓ process_owner_template: detected X blanks
✓ process_owner_template: resolved X placeholder mappings  
✓ fill_template: starting fill for lease_id
✓ fill_template: template found X variables
✓ fill_template: prepared X values to set
✓ fill_template: vars_set=X, vars_blank=Y
```

If any of these are missing, that's where the problem is.

---

## Still Stuck?

1. Share error log snippet with "process_owner_template" or "fill_template" messages
2. Verify template file HAS actual blanks (open in Word)
3. Check that chatbot form actually captures all data (check guest creation logs)
4. Try re-uploading template if cache seems corrupted
