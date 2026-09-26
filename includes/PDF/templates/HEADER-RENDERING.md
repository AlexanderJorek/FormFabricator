# Header Rendering — Developer Reference

This document covers every non-obvious detail about how the PDF layout editor's header builder works, why it works that way, and what will break if you change it. Read all of it before touching any of these files.

---

## Files involved

| File | Role |
|------|------|
| `includes/PDF/templates/layout.php` | PHP: converts saved header JSON → mPDF HTML |
| `assets/js/admin-pdflayout.js` | JS: builder canvas + live preview renderer |
| `includes/Admin/PDFLayoutEditor.php` | PHP: editor page, save/preview handlers, `sanitizeHeaderLayout()` |
| `includes/PDF/Generator.php` | Instantiates mPDF, injects HTML, configures margins |

---

## Coordinate system — the grid

The builder uses a **42-column grid**. Each cell is `HB_CELL = 15px` in the JS canvas.

Every element has integer properties: `x`, `y`, `w`, `h` (all in grid units).

**In the PDF**, cells are converted to millimetres:

```
cell_mm = (210 - margin_left - margin_right) / 42
```

`210` is A4 width in mm. `margin_left` and `margin_right` are the user's margin settings (default 15mm each), so with defaults: `cell_mm = 180 / 42 ≈ 4.286mm`.

**Cells are square**: the same `cell_mm` value is used for both x and y. This means a 1-unit-wide by 1-unit-tall cell is a square in the PDF. The builder canvas cells are also square (15×15px). Do not introduce separate `col_mm` / `row_mm` — they must remain equal.

---

## mPDF absolute positioning — the most important gotcha

In mPDF, **`position:absolute` is always relative to the page origin (0,0 = top-left corner of the paper)**, not relative to any parent element. Setting `position:relative` on a parent has no effect — mPDF ignores it.

This means every element's absolute coordinates must be computed from the **page edge**, not from a container:

```
abs_left = margin_left + el.x × cell_mm     (mm from left edge of paper)
abs_top  = margin_top  + el.y × cell_mm     (mm from top edge of paper)
```

**The preview works differently.** The preview uses a normal browser DOM, so `position:absolute` is relative to the nearest `position:relative` ancestor. The preview wraps all elements inside a `position:relative` div that is offset to start after the paper margins (via CSS padding on the paper div). So in the preview, element coordinates are relative to that container, not the page. This is correct behaviour — the visual result matches because the container itself is positioned after the margins.

---

## Why the header is inline in the body, not in SetHTMLHeader()

mPDF's `SetHTMLHeader()` places content in the margin area above `margin_top`. When using it, mPDF **forces** `margin_top ≥ header_height + margin_header` — it overrides the user's margin setting silently. A user-configured 15mm top margin would silently become ~45mm.

The fix: the header is emitted as regular body HTML at the top of the content. This means `margin_top` is purely the user's setting and is respected exactly.

---

## The spacer div

Because the header elements are `position:absolute` (in mPDF: page-relative), they float over the content and do not push body content down. Without compensation, the form fields would render underneath the header.

The fix is a **spacer div** immediately before the absolute-positioned elements:

```php
$out = '<div style="height:' . $header_h_mm . 'mm;">&nbsp;</div>';
```

`header_h_mm = max_bottom_row × cell_mm` where `max_bottom_row` is the bottom edge (y + h) of the lowest element. The `&nbsp;` prevents mPDF from collapsing a zero-content div.

The spacer is in the normal flow; the header elements are not. Together they produce the correct result: content starts below the header.

---

## Image sizing

Images use `width: el_w_mm mm; height: auto`.

**Do not set an explicit height on images.** Setting `height: el_h_mm mm` forces the image to stretch vertically to the full cell height. For thin elements (e.g. a 1-row decorative line), this makes them appear far thicker than intended. `height:auto` preserves the image's natural aspect ratio; the `overflow:hidden` on the container div clips anything that would exceed the cell bounds.

The same applies in the preview: images use `width:100%; height:auto`, not `height:100%`.

---

## Preview scaling

The preview paper has a fixed **992px design width**: A4 (210mm) at mPDF's **120 DPI** (`210 × 120 / 25.4 ≈ 992`). `admin-pdflayout.js` converts at that same resolution everywhere (`mm()`: 120/25.4 px per mm, `pt()`: 120/72 px per pt), and `scaleA4()` shrinks whole sheets with CSS on narrower screens, so nothing inside a sheet measures itself.

The header renders at **builder canvas native size** (42 × 15px = 630px wide) and is then CSS-scaled to the paper's content width:

```js
var canvasW  = HB_COLS * HB_CELL;                                       // 630px
var marginPx = parseFloat(mm(s.margin_left)) + parseFloat(mm(s.margin_right));
var contentW = Math.max(1, 992 - marginPx);                             // fixed design width
var scale    = contentW / canvasW;                                      // no Math.min(1, ...) cap
```

Do **not** use `paper.offsetWidth` here. It varies with the viewport and with timing (the sheet is CSS-scaled on small screens), and a scale derived from it stops matching the PDF.

There is intentionally **no `Math.min(1, scale)` cap**. With the default 15mm margins the content area is about 850px, wider than the 630px canvas, so the canvas upscales. Capping at 1 would leave a gap and break proportional matching with the PDF.

The scaled container height is:

```js
var hpx      = max(el.y + el.h) * HB_CELL;   // native px height of header
var scaledH  = Math.ceil(hpx * scale);         // display height after scaling
```

The outer div is set to `height: scaledH px; overflow:hidden` to clip the scaled container to exactly the right height.

---

## Why the old table/band approach was abandoned

The previous implementation split elements into horizontal "bands" and rendered each band as a `<tr>` in an HTML table. Problems:

1. **Images stretched**: table cells expand to fill the row height. A 1-row thin line image would be stretched to `cell_mm` tall regardless of its natural proportions.
2. **Band splitting fragility**: elements that didn't align to clean band boundaries caused the algorithm to skip bands or produce wrong heights.
3. **Row height mismatch**: empty bands were initially `continue`d (skipped), but the height accounting still counted them, creating gaps.
4. **mPDF table quirks**: `max-height`, `object-fit`, and `overflow:hidden` on `<td>` are partially or fully unsupported in mPDF.

The absolute-positioning approach has none of these problems: each element is independent, and the grid is the single source of truth.

---

## Data flow summary

```
Builder canvas (JS)
  └─ el.{x,y,w,h} in grid units, HB_CELL=15px per unit
       │
       ▼
Saved to DB as JSON in fabricator_forms_pdf_layout.header_layout.elements
       │
       ├─► Preview renderer (JS, assets/js/admin-pdflayout.js, the patched buildPreview())
       │     position:absolute in px within a scaled position:relative container
       │     image: width:100%; height:auto
       │
       └─► PDF renderer (PHP, layout.php, the 'header' closure)
             position:absolute in mm, page-relative (mPDF behaviour)
             left = margin_left + el.x × cell_mm
             top  = margin_top  + el.y × cell_mm
             image: width:el_w_mm mm; height:auto
```

---

## Settings that affect header layout

| Setting key | Effect |
|-------------|--------|
| `margin_left` | Shifts all elements right; also reduces `cell_mm` (narrower content = smaller cells) |
| `margin_right` | Same effect on `cell_mm` |
| `margin_top` | Shifts all elements down (added to `abs_top` of every element) |
| `header_layout.elements` | The element array; each has `{type, x, y, w, h, src/text/size/bold/color/align}` |

Margins are clamped to 0–50mm both when saved (`PDFLayoutEditor::save()`) and again when `layout.php` reads them.

Changing margins changes `cell_mm`, which changes every element's size in mm. The grid ratios are preserved but absolute mm sizes shift. This is intentional — the layout scales with the content area.
