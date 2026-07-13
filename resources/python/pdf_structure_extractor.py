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

Output: Markdown on stdout. Both modes share one heading/list/paragraph classifier so a
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


NUMBERED_PREFIX_RE = re.compile(r'^(\d+(?:\.\d+)*)[\.\)]?\s+')


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
    sizes = [l.size for l in lines if l.text.strip()]
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

    for line in lines:
        text = line.text.strip()
        if not text:
            flush_paragraph()
            continue

        if last_page is not None and line.page != last_page:
            flush_paragraph()
            out.append('\n---\n')
        last_page = line.page

        ratio = line.size / median if median else 1
        level = heading_level_from_size(ratio) or heading_level_from_caps(text)

        if level:
            flush_paragraph()
            out.append('#' * level + ' ' + text)
        elif list_re.match(text):
            flush_paragraph()
            out.append(f'- {list_re.sub("", text)}')
        else:
            rendered = f'**{text}**' if line.bold else text
            paragraph.append(rendered)

    flush_paragraph()
    return '\n\n'.join(out)


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
                ))
    return lines


HOCR_LINE_RE = re.compile(
    r"<span class='ocr_line'[^>]*title=\"[^\"]*x_size (\d+(?:\.\d+)?)[^\"]*\"[^>]*>(.*?)</span>\s*(?=<span class='ocr_line'|</p>)",
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
            size = float(match.group(1))
            inner = match.group(2)
            words = HOCR_WORD_RE.findall(inner)
            text = ' '.join(html.unescape(HOCR_TAG_RE.sub('', w)).strip() for w in words).strip()
            if text:
                lines.append(Line(text=text, size=size, page=page_num))
    return lines


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--mode', choices=['pdf', 'hocr'], required=True)
    parser.add_argument('input', help='PDF file path (--mode pdf) or directory of .hocr files (--mode hocr)')
    args = parser.parse_args()

    lines = extract_pdf(args.input) if args.mode == 'pdf' else extract_hocr_dir(args.input)
    sys.stdout.write(classify_and_render(lines))


if __name__ == '__main__':
    main()
