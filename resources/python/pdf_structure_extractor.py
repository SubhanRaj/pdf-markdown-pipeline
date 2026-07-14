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
    # Pre-rendered content (e.g. an OCR engine's own detected table HTML) that should be
    # emitted as-is, bypassing the heading/list/paragraph classifier entirely.
    raw: bool = False


@dataclass
class TableBlock:
    rows: list[list[str]]
    page: int = 0


NUMBERED_PREFIX_RE = re.compile(r'^(\d+(?:\.\d+)*)[\.\)]?\s+')

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
                # than retrying row-by-row, which would re-scan the same run repeatedly.
                for trow in table_rows:
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


def classify_and_render(lines: list[Line]) -> str:
    sizes = [l.size for l in lines if isinstance(l, Line) and l.text.strip()]
    if not sizes:
        return ""
    median = statistics.median(sizes)

    list_re = re.compile(r'^\s*(\(?\d{1,3}[\.\)]|\(?[a-zA-Z][\.\)]|[-•*])\s+')

    out: list[str] = []
    paragraph: list[str] = []
    last_page = None

    def flush_paragraph():
        if paragraph:
            out.append(' '.join(paragraph).strip())
            paragraph.clear()

    for block in detect_tables(lines):
        if last_page is not None and block.page != last_page:
            flush_paragraph()
            out.append('\n---\n')
        last_page = block.page

        if isinstance(block, TableBlock):
            flush_paragraph()
            out.append(render_table(block))
            continue

        text = block.text.strip()
        if not text:
            flush_paragraph()
            continue

        if block.raw:
            flush_paragraph()
            out.append(text)
            continue

        ratio = block.size / median if median else 1
        level = heading_level_from_size(ratio) or heading_level_from_caps(text)

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


def extract_pdf(path: str) -> list[Line]:
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
    sys.stdout.write(classify_and_render(lines))


if __name__ == '__main__':
    main()
