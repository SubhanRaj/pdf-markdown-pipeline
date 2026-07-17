#!/usr/bin/env python3
"""
Structure-aware PDF/OCR -> Markdown extraction.

markitdown's built-in PDF converter is plain-text only (pdfminer.high_level.extract_text,
"most style information is ignored" per its own docstring) and Tesseract's default stdout
mode is plain OCR text with no layout info at all. Neither preserves headings, lists, or
paragraph structure. This script fixes that by working from font-size/line-height data:

  --mode pdf   <file.pdf>   Native-text PDFs: pdfminer's low-level API exposes per-character
                             font size and font name, which is enough to detect headings
                             (larger text) and bold runs (font name contains "Bold").
  --mode hocr  <dir>        Scanned/OCR'd PDFs: Tesseract's hOCR output (`tesseract ... hocr`)
                             gives an `x_size` (pixel font size) per line, which is used the
                             same way. Bold is NOT attempted here — Tesseract's LSTM engine
                             does not reliably expose font-weight, so guessing would produce
                             more false positives than it's worth. Reads one .hocr file per
                             page from the directory, in filename order.
  --mode easyocr/paddleocr/surya <dir>
                             Alternative local OCR engines (see OCR_RESEARCH.md), each run
                             directly against the same rasterized page PNGs Tesseract would
                             use. None of these expose a font-size concept, so the detected
                             text-line bounding-box height is used as the "size" signal instead
                             — same heading/list heuristics, just a different size proxy.

Output: Markdown on stdout. All modes share one heading/list/paragraph classifier so a
verifier sees consistent structure regardless of which extraction path produced it.
"""
import re
import sys
import json
import glob
import html
import argparse
import statistics
from dataclasses import dataclass


@dataclass
class Line:
    text: str
    size: float
    bold: bool = False
    page: int = 0
    # Horizontal position (x0/x1) and row position (y0), used only for table detection —
    # left at 0 by extractors that can't cheaply provide them, which just means those lines
    # are never grouped into table columns (paragraphs still render fine).
    x0: float = 0.0
    x1: float = 0.0
    y0: float = 0.0
    # Set when this line was part of a multi-cell row run that *looked* like a table but got
    # rejected by detect_tables()'s sparse-grid check (see MIN_TABLE_ROWS/fill_ratio below).
    # These lines are exactly the flattened, jumbled fragments of a table detect_tables()
    # couldn't reconstruct — when Docling's structure JSON supplies a clean version of that
    # same table for the same page, classify_and_render() drops these fragments rather than
    # rendering both the garbled paragraph and the clean table back to back.
    table_fragment: bool = False
    # Pre-rendered content (e.g. an OCR engine's own detected table HTML) that should be
    # emitted as-is, bypassing the heading/list/paragraph classifier entirely.
    raw: bool = False


@dataclass
class TableBlock:
    rows: list[list[str]]
    page: int = 0


@dataclass
class HeadingBlock:
    text: str
    page: int = 0
    level: int = 2


NUMBERED_PREFIX_RE = re.compile(r'^(\d+(?:\.\d+)*)[\.\)]?\s+')

# Legacy non-Unicode Devanagari fonts (Kruti Dev, Chanakya, DevLys, Shusha, Walkman, etc.) map
# their glyphs into the Latin/ASCII code range with no real ToUnicode CMap. pdfminer still
# extracts "text" from these — readable-looking but wrong (e.g. "Hkkjr" instead of "भारत") —
# which is worse than the (cid:N) fallback case already checked in ConvertDocumentToMarkdown's
# isGoodQuality(), since char-count and cid-token checks both pass. Detected here, at the only
# place this script already reads per-character font names (see bold_count below), rather than
# attempting a character remapping table — remapping risks silently producing subtly-wrong text
# in a legal government document; flagging for human review matches this script's existing
# quality philosophy. Confirmed against a real document during the Docling evaluation — see
# STRUCTURE_RESEARCH.md.
LEGACY_HINDI_FONT_RE = re.compile(r'kruti|chanakya|devlys|shusha|walkman|agra|shree.?dev', re.IGNORECASE)

# Table-row grouping tolerances, in the source's native units (PDF points for --mode pdf,
# pixels for every OCR-based mode) — coarse on purpose since OCR bounding boxes are noisier
# than pdfminer's exact glyph coordinates.
ROW_Y_TOLERANCE = 4.0
COLUMN_X_TOLERANCE = 16.0
# A 2-row multi-cell run is too easily produced by pdfminer splitting one justified body-text
# line into several LTTextLine fragments (see detect_tables' fill-ratio check for the other
# half of this guard) — real tables in practice run to at least 3 rows here.
MIN_TABLE_ROWS = 3


def _group_rows(lines: list[Line]) -> list[list[Line]]:
    """Group same-page lines with near-identical y0 into a visual row, in original order."""
    rows: list[list[Line]] = []
    for line in lines:
        if rows and rows[-1][-1].page == line.page and abs(rows[-1][-1].y0 - line.y0) <= ROW_Y_TOLERANCE:
            rows[-1].append(line)
        else:
            rows.append([line])
    for row in rows:
        row.sort(key=lambda l: l.x0)
    return rows


def _cluster_columns(rows: list[list[Line]]) -> list[float]:
    """Merge every cell's x0 across a candidate table block into shared column anchors."""
    xs = sorted(cell.x0 for row in rows for cell in row)
    columns: list[float] = []
    for x in xs:
        if not columns or x - columns[-1] > COLUMN_X_TOLERANCE:
            columns.append(x)
    return columns


def detect_tables(lines: list[Line]) -> list[Line | TableBlock]:
    """
    Groups lines into visual rows by y-position, then flags runs of >=2 consecutive rows that
    each have >=2 cells as a table. None of the six extraction paths (pdfminer, hOCR, EasyOCR,
    PaddleOCR, Surya block-OCR) expose real table structure, so this geometric heuristic is what
    stands between a scanned/native table and it being flattened into one run-on paragraph.
    Rows with only one cell (ordinary text/headings) pass through untouched. Lines with no
    positional data (x0 == x1 == 0, e.g. hOCR before bbox parsing) never cluster into rows of
    >=2 cells and so are unaffected.
    """
    rows = _group_rows(lines)
    blocks: list[Line | TableBlock] = []

    i = 0
    while i < len(rows):
        row = rows[i]
        if len(row) >= 2:
            j = i
            while j < len(rows) and len(rows[j]) >= 2 and rows[j][0].page == row[0].page:
                j += 1
            table_rows = rows[i:j]
            if len(table_rows) >= MIN_TABLE_ROWS:
                columns = _cluster_columns(table_rows)
                grid: list[list[str]] = []
                for trow in table_rows:
                    cells = [''] * len(columns)
                    for cell in trow:
                        idx = min(range(len(columns)), key=lambda k: abs(columns[k] - cell.x0))
                        cells[idx] = f'{cells[idx]} {cell.text}'.strip() if cells[idx] else cell.text
                    grid.append(cells)

                # Real tables reuse the same column positions across rows, so most cells in the
                # grid end up filled. Justified body-text paragraphs can *look* like a 2-row,
                # multi-cell run (pdfminer sometimes splits one justified line into several
                # LTTextLine fragments), but each row lands in different, non-overlapping
                # columns — a sparse grid is the tell that this isn't really a table.
                filled = sum(1 for r in grid for c in r if c)
                fill_ratio = filled / (len(grid) * len(columns))
                if fill_ratio >= 0.5:
                    blocks.append(TableBlock(rows=grid, page=table_rows[0][0].page))
                    i = j
                    continue

                # Rejected as a table (too sparse) — emit the whole run as plain lines rather
                # than retrying row-by-row, which would re-scan the same run repeatedly. Tagged
                # so classify_and_render() can drop these specific lines if Docling later fills
                # this page's table in from its own structure JSON.
                for trow in table_rows:
                    for line in trow:
                        line.table_fragment = True
                    blocks.extend(trow)
                i = j
                continue
        blocks.extend(row)
        i += 1

    return blocks


def render_table(table: TableBlock) -> str:
    ncols = max(len(row) for row in table.rows)
    pad = lambda row: row + [''] * (ncols - len(row))
    esc = lambda cell: cell.replace('|', '\\|').replace('\n', ' ').strip()

    header = pad(table.rows[0])
    out = ['| ' + ' | '.join(esc(c) for c in header) + ' |']
    out.append('| ' + ' | '.join(['---'] * ncols) + ' |')
    for row in table.rows[1:]:
        out.append('| ' + ' | '.join(esc(c) for c in pad(row)) + ' |')
    return '\n'.join(out)


def heading_level_from_size(ratio: float) -> int:
    """Font-size signal: works for documents that actually use varied sizes for headings."""
    if ratio >= 1.6:
        return 1
    if ratio >= 1.3:
        return 2
    if ratio >= 1.12:
        return 3
    return 0


def heading_level_from_caps(text: str) -> int:
    """
    Fallback signal for the common case where headings share the body font size and are
    distinguished only by ALL CAPS + numbering (typical of government orders/policies typed
    without real paragraph styles) — font size alone misses these entirely.
    """
    letters = [c for c in text if c.isalpha()]
    if len(letters) < 3 or len(text) >= 100:
        return 0
    upper_ratio = sum(1 for c in letters if c.isupper()) / len(letters)
    if upper_ratio < 0.85:
        return 0

    m = NUMBERED_PREFIX_RE.match(text)
    if m:
        depth = m.group(1).count('.') + 1  # "1" -> 1, "1.1" -> 2, "1.2.1" -> 3
        return min(depth + 1, 6)
    return 1


def _own_heading_level(text: str, size: float, median: float) -> int:
    """Shared with classify_and_render's render loop, so the pre-pass that decides which pages
    are missing a heading uses exactly the same judgment as the render itself."""
    ratio = size / median if median else 1
    return heading_level_from_size(ratio) or heading_level_from_caps(text)


def _insert_index(blocks: list, page: int, at_start: bool) -> int:
    """Where to insert a Docling-supplied block (table or heading) for `page` into `blocks`.
    at_start=True lands it right before this page's first existing block (headings read best
    at the top of their section); at_start=False lands it right after this page's last block
    (tables read fine appended). Falls back to right after the previous page's last block when
    this page has no existing blocks at all (e.g. an image-only page)."""
    if at_start:
        for idx, b in enumerate(blocks):
            if b.page == page:
                return idx
    else:
        for idx in range(len(blocks) - 1, -1, -1):
            if blocks[idx].page == page:
                return idx + 1
    for idx in range(len(blocks) - 1, -1, -1):
        if blocks[idx].page < page:
            return idx + 1
    return len(blocks)


def docling_table_blocks(structure_json_path: str) -> list[TableBlock]:
    """
    Loads the compact structure.json Docling's Pass 0 already wrote (see
    ConvertDocumentToMarkdown::runDoclingStructureAnalysis) and turns its tables into
    TableBlocks, keyed by page, for classify_and_render() to fill gaps the geometric
    detect_tables() heuristic below missed. Docling's TableFormer model reads table shape far
    more reliably than column-clustering on OCR bboxes — see STRUCTURE_RESEARCH.md.

    ponytail: pipe-syntax Markdown tables can't express row/col spans, so a spanned cell's text
    is placed only at its anchor position and the cells it spans are left blank — visually
    close enough for review; a real merged-cell renderer would need HTML tables instead.
    """
    with open(structure_json_path, encoding='utf-8') as f:
        data = json.load(f)

    blocks = []
    for table in data.get('tables', []):
        rows, cols = table.get('num_rows') or 0, table.get('num_cols') or 0
        if not rows or not cols:
            continue
        grid = [['' for _ in range(cols)] for _ in range(rows)]
        for cell in table.get('cells', []):
            r, c = cell.get('row'), cell.get('col')
            if r is None or c is None or not (0 <= r < rows) or not (0 <= c < cols):
                continue
            grid[r][c] = (cell.get('text') or '').replace('\n', ' ').strip()
        blocks.append(TableBlock(rows=grid, page=(table.get('page') or 1) - 1))
    return blocks


def docling_heading_blocks(structure_json_path: str) -> list[HeadingBlock]:
    """
    Same idea as docling_table_blocks(), for headings: Docling's structure.json records each
    detected heading's text and page but not a nesting level, so the level is inferred the same
    way heading_level_from_caps() infers it for the geometric heuristic — from a numbered prefix
    (`1.2.1` -> deeper level), defaulting to level 2 for an unnumbered heading (level 1 is left
    for the heuristic's own font-size signal, since Docling gives us no way to tell a document
    title from a section header).
    """
    with open(structure_json_path, encoding='utf-8') as f:
        data = json.load(f)

    blocks = []
    for heading in data.get('headings', []):
        text = (heading.get('text') or '').strip()
        if not text:
            continue
        m = NUMBERED_PREFIX_RE.match(text)
        level = min(m.group(1).count('.') + 2, 6) if m else 2
        blocks.append(HeadingBlock(text=text, page=(heading.get('page') or 1) - 1, level=level))
    return blocks


def classify_and_render(
    lines: list[Line],
    docling_tables: list[TableBlock] | None = None,
    docling_headings: list[HeadingBlock] | None = None,
) -> str:
    sizes = [l.size for l in lines if isinstance(l, Line) and l.text.strip()]
    if not sizes:
        return ""
    median = statistics.median(sizes)

    list_re = re.compile(r'^\s*(\(?\d{1,3}[\.\)]|\(?[a-zA-Z][\.\)]|[-•*])\s+')

    blocks = detect_tables(lines)

    if docling_tables:
        covered_pages = {b.page for b in blocks if isinstance(b, TableBlock)}
        for table in docling_tables:
            if table.page in covered_pages:
                continue

            # Drop this page's rejected-sparse fragments (see Line.table_fragment) — they're the
            # same table Docling is about to supply cleanly, just jumbled, so keeping both would
            # show the garbled version right next to the correct one.
            blocks = [b for b in blocks if not (isinstance(b, Line) and b.page == table.page and b.table_fragment)]

            blocks.insert(_insert_index(blocks, table.page, at_start=False), table)

    if docling_headings:
        # A page "has" a heading if our own heuristic would classify at least one of its lines
        # as one — only pages where the heuristic found zero get Docling's headings spliced in,
        # same page-level granularity as the table splice above (not a per-heading text match).
        covered_heading_pages = {
            b.page for b in blocks
            if isinstance(b, Line) and not b.raw and b.text.strip()
            and _own_heading_level(b.text.strip(), b.size, median) > 0
        }
        last_inserted_at: dict[int, int] = {}
        for heading in docling_headings:
            if heading.page in covered_heading_pages:
                continue
            # Once one heading for this page has been placed, later headings for the same page
            # go right after it — otherwise at_start=True would keep inserting each new one
            # before the previous, reversing their original order.
            idx = last_inserted_at[heading.page] + 1 if heading.page in last_inserted_at \
                else _insert_index(blocks, heading.page, at_start=True)
            blocks.insert(idx, heading)
            last_inserted_at[heading.page] = idx

    out: list[str] = []
    paragraph: list[str] = []
    last_page = None

    def flush_paragraph():
        if paragraph:
            out.append(' '.join(paragraph).strip())
            paragraph.clear()

    for block in blocks:
        if last_page is not None and block.page != last_page:
            flush_paragraph()
            out.append('\n---\n')
        last_page = block.page

        if isinstance(block, TableBlock):
            flush_paragraph()
            out.append(render_table(block))
            continue

        if isinstance(block, HeadingBlock):
            flush_paragraph()
            out.append('#' * block.level + ' ' + block.text)
            continue

        text = block.text.strip()
        if not text:
            flush_paragraph()
            continue

        if block.raw:
            flush_paragraph()
            out.append(text)
            continue

        level = _own_heading_level(text, block.size, median)

        if level:
            flush_paragraph()
            out.append('#' * level + ' ' + text)
        elif list_re.match(text):
            flush_paragraph()
            out.append(f'- {list_re.sub("", text)}')
        else:
            rendered = f'**{text}**' if block.bold else text
            paragraph.append(rendered)

    flush_paragraph()
    return '\n\n'.join(out)


def _reading_order_sort(lines: list[Line]) -> list[Line]:
    """
    Re-sorts into row-major reading order (top-to-bottom, left-to-right per row) regardless of
    the order the source extractor happened to emit lines in. This matters specifically for
    tables: pdfminer (and some OCR engines) group text into containers/blocks by whatever
    proximity heuristic they use internally, which for a table often means "all of column 1
    top-to-bottom, then all of column 2" rather than row-by-row — table-row grouping in
    detect_tables() only works if same-row cells are adjacent in the list, so this normalizes
    that before detection runs. PDF y-coordinates increase upward, hence the descending sort.
    """
    return sorted(lines, key=lambda l: (l.page, -round(l.y0 / ROW_Y_TOLERANCE), l.x0))


# Set by extract_pdf() when a legacy non-Unicode font is detected; read by main() afterward.
# Module-level rather than threading a second return value through every extractor, since only
# --mode pdf can hit this (OCR-based modes read rendered pixels, never a font's broken cmap).
detected_legacy_font: str | None = None


def extract_pdf(path: str) -> list[Line]:
    global detected_legacy_font
    from pdfminer.high_level import extract_pages
    from pdfminer.layout import LTTextLine, LTChar, LTTextContainer

    lines: list[Line] = []
    for page_num, page_layout in enumerate(extract_pages(path)):
        for element in page_layout:
            if not isinstance(element, LTTextContainer):
                continue
            for text_line in element:
                if not isinstance(text_line, LTTextLine):
                    continue
                chars = [c for c in text_line if isinstance(c, LTChar)]
                if not chars:
                    continue
                avg_size = sum(c.height for c in chars) / len(chars)
                bold_count = sum(1 for c in chars if 'bold' in c.fontname.lower())
                if detected_legacy_font is None:
                    for c in chars:
                        if LEGACY_HINDI_FONT_RE.search(c.fontname):
                            detected_legacy_font = c.fontname
                            break
                lines.append(Line(
                    text=text_line.get_text(),
                    size=avg_size,
                    bold=bold_count > len(chars) / 2,
                    page=page_num,
                    x0=text_line.x0,
                    x1=text_line.x1,
                    y0=text_line.y0,
                ))
    return _reading_order_sort(lines)


HOCR_LINE_RE = re.compile(
    r"<span class='ocr_line'[^>]*title=\"bbox (\d+) (\d+) (\d+) (\d+);[^\"]*x_size (\d+(?:\.\d+)?)[^\"]*\"[^>]*>(.*?)</span>\s*(?=<span class='ocr_line'|</p>)",
    re.DOTALL,
)
HOCR_WORD_RE = re.compile(r"<span class='ocrx_word'[^>]*>(.*?)</span>")
HOCR_TAG_RE = re.compile(r"<[^>]+>")


def extract_hocr_dir(dir_path: str) -> list[Line]:
    lines: list[Line] = []
    files = sorted(glob.glob(f'{dir_path}/*.hocr'))
    for page_num, file_path in enumerate(files):
        with open(file_path, 'r', encoding='utf-8') as f:
            content = f.read()

        # hOCR line blocks are not strictly well-formed for a generic XML parser (Tesseract's
        # own DOCTYPE + nested <p>/<span> mix), so this parses via regex over the known,
        # stable structure Tesseract emits rather than pulling in a full HTML parser dependency.
        for match in HOCR_LINE_RE.finditer(content):
            x0, y0, x1, y1, size_str, inner = match.groups()
            size = float(size_str)
            words = HOCR_WORD_RE.findall(inner)
            text = ' '.join(html.unescape(HOCR_TAG_RE.sub('', w)).strip() for w in words).strip()
            if text:
                # hOCR y grows downward (image coordinates); negate so _reading_order_sort's
                # "descending y = top of page first" convention still holds.
                lines.append(Line(text=text, size=size, page=page_num, x0=float(x0), x1=float(x1), y0=-float(y0)))
    return _reading_order_sort(lines)


def _bbox_stats(bbox) -> tuple[float, float, float, float]:
    """Returns (x0, x1, y0, height) for a 4-point polygon. Image coordinates (y grows
    downward), so y0 is negated by callers before it reaches _reading_order_sort."""
    xs = [point[0] for point in bbox]
    ys = [point[1] for point in bbox]
    return min(xs), max(xs), min(ys), max(ys) - min(ys)


def extract_easyocr_dir(dir_path: str) -> list[Line]:
    import easyocr

    reader = easyocr.Reader(['hi', 'en'], gpu=False, verbose=False)
    lines: list[Line] = []
    for page_num, image_path in enumerate(sorted(glob.glob(f'{dir_path}/*.png'))):
        for bbox, text, _confidence in reader.readtext(image_path):
            text = text.strip()
            if text:
                x0, x1, y0, height = _bbox_stats(bbox)
                lines.append(Line(text=text, size=height, page=page_num, x0=x0, x1=x1, y0=-y0))
    return _reading_order_sort(lines)


def extract_paddleocr_dir(dir_path: str) -> list[Line]:
    from paddleocr import PaddleOCR

    # Explicitly pinned to the mobile detection model — the "hi" lang preset otherwise
    # resolves to the server-tier detector, which was observed to consume nearly all system
    # RAM on a single page (see OCR_RESEARCH.md, Candidate 1).
    ocr = PaddleOCR(
        lang='hi',
        text_detection_model_name='PP-OCRv5_mobile_det',
        text_recognition_model_name='devanagari_PP-OCRv5_mobile_rec',
        use_doc_orientation_classify=False,
        use_doc_unwarping=False,
        use_textline_orientation=False,
        # PaddleX defaults to oneDNN (MKL-DNN) on CPU, which crashes on this build with
        # "NotImplementedError: ConvertPirAttribute2RuntimeAttribute not support
        # [pir::ArrayAttribute<pir::DoubleAttribute>]" on this text-detection model — a
        # Paddle/oneDNN compatibility bug, not something to fix here. Plain CPU inference works.
        enable_mkldnn=False,
    )
    lines: list[Line] = []
    for page_num, image_path in enumerate(sorted(glob.glob(f'{dir_path}/*.png'))):
        for result in ocr.predict(image_path):
            polys = result.get('rec_polys', [])
            texts = result.get('rec_texts', [])
            for poly, text in zip(polys, texts):
                text = text.strip()
                if text:
                    x0, x1, y0, height = _bbox_stats(poly)
                    lines.append(Line(text=text, size=height, page=page_num, x0=x0, x1=x1, y0=-y0))
    return _reading_order_sort(lines)


SURYA_TAG_RE = re.compile(r"<[^>]+>")


def extract_surya_dir(dir_path: str) -> list[Line]:
    from PIL import Image
    from surya.recognition import RecognitionPredictor

    # Current surya-ocr API does full-page block OCR (HTML per layout block) rather than the
    # older per-line text_lines interface — one block roughly corresponds to a paragraph/heading,
    # so its polygon height is a coarser but still usable "size" signal for the heading heuristic.
    recognizer = RecognitionPredictor()

    lines: list[Line] = []
    for page_num, image_path in enumerate(sorted(glob.glob(f'{dir_path}/*.png'))):
        image = Image.open(image_path).convert('RGB')
        [result] = recognizer([image])
        for block in result.blocks:
            if block.skipped or block.error:
                continue
            x0, y0, x1, y1 = block.bbox
            if block.label == 'Table' and '<table' in block.html:
                # Surya's own layout model already detected this as a table and returned real
                # <table> HTML — pass it through as-is (the review UI's Markdown renderer
                # supports embedded HTML) instead of flattening it through the generic
                # geometric detect_tables() heuristic, which only has line-level text to work
                # with for the other three engines.
                lines.append(Line(text=block.html.strip(), size=y1 - y0, page=page_num, x0=x0, x1=x1, y0=-y0, raw=True))
                continue
            text = html.unescape(SURYA_TAG_RE.sub(' ', block.html)).strip()
            if text:
                lines.append(Line(text=text, size=block.height, page=page_num, x0=x0, x1=x1, y0=-y0))
    return _reading_order_sort(lines)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--mode', choices=['pdf', 'hocr', 'easyocr', 'paddleocr', 'surya'], required=True)
    parser.add_argument('--structure-json', default=None, help='Path to Docling\'s compact structure.json (Pass 0), used to fill in tables/headings the geometric heuristic below misses')
    parser.add_argument('input', help='PDF file path (--mode pdf) or directory of page images/.hocr files (all other modes)')
    args = parser.parse_args()

    extractors = {
        'pdf': extract_pdf,
        'hocr': extract_hocr_dir,
        'easyocr': extract_easyocr_dir,
        'paddleocr': extract_paddleocr_dir,
        'surya': extract_surya_dir,
    }
    lines = extractors[args.mode](args.input)

    docling_tables = None
    docling_headings = None
    if args.structure_json:
        try:
            docling_tables = docling_table_blocks(args.structure_json)
            docling_headings = docling_heading_blocks(args.structure_json)
        except (OSError, json.JSONDecodeError):
            docling_tables = None
            docling_headings = None

    output = classify_and_render(lines, docling_tables, docling_headings)
    if args.mode == 'pdf' and detected_legacy_font:
        # Reuses the existing stdout-string contract with ConvertDocumentToMarkdown rather
        # than adding a new IPC channel — isGoodQuality() strips this marker before saving.
        output = f'<!-- LEGACY_FONT_DETECTED:{detected_legacy_font} -->\n' + output
    sys.stdout.write(output)


if __name__ == '__main__':
    main()
