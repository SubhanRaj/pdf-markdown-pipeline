#!/usr/bin/env python3
"""
Self-check for the column-detection reading-order logic in pdf_structure_extractor.py — see
_find_column_gutter()/_reading_order_sort(). Run directly: `python3 test_pdf_structure_extractor.py`.
No pytest dependency; assert-based, same convention as the rest of this script.
"""
from pdf_structure_extractor import Line, _find_column_gutter, _reading_order_sort


def two_column_page() -> list[Line]:
    """A CL-Bottling-Rules-amendment-style page: left half = existing provision (5 lines,
    x0=50-250), right half = new provision (5 lines, x0=320-520), same y-range on both sides but
    each side's own paragraph wraps independently (not row-aligned)."""
    lines = []
    for i in range(5):
        lines.append(Line(text=f'existing line {i}', size=10, page=0, x0=50, x1=250, y0=700 - i * 12))
    for i in range(5):
        # Offset by half a line height so left/right never land at the same y0 — mimics two
        # independently-wrapped paragraphs, not a row-aligned table.
        lines.append(Line(text=f'new line {i}', size=10, page=0, x0=320, x1=520, y0=694 - i * 12))
    return lines


def single_column_page() -> list[Line]:
    """An ordinary paragraph page: every line runs most of the page width, no gutter."""
    return [Line(text=f'para line {i}', size=10, page=0, x0=50, x1=520, y0=700 - i * 12) for i in range(6)]


def two_column_table_page() -> list[Line]:
    """A plain 'item | price' table: same visual gutter as the prose case, but rows line up
    across it at matching heights — must NOT be treated as two reading-order columns, or
    detect_tables() downstream would never see adjacent same-row cells."""
    lines = []
    for i in range(5):
        y = 700 - i * 20
        lines.append(Line(text=f'item {i}', size=10, page=0, x0=50, x1=250, y0=y))
        lines.append(Line(text=f'{i}.00', size=10, page=0, x0=320, x1=380, y0=y))
    return lines


def test_two_column_prose_detected_and_read_left_then_right():
    lines = two_column_page()
    gutter = _find_column_gutter(lines)
    assert gutter is not None, 'expected a gutter to be detected on a two-column page'
    assert 250 < gutter < 320

    ordered = _reading_order_sort(lines)
    texts = [l.text for l in ordered]
    assert texts == [f'existing line {i}' for i in range(5)] + [f'new line {i}' for i in range(5)], texts


def test_single_column_page_untouched():
    lines = single_column_page()
    assert _find_column_gutter(lines) is None
    ordered = _reading_order_sort(lines)
    assert [l.text for l in ordered] == [f'para line {i}' for i in range(6)]


def test_row_aligned_table_not_treated_as_columns():
    lines = two_column_table_page()
    assert _find_column_gutter(lines) is None, 'a row-aligned table must not be read as two columns'
    ordered = _reading_order_sort(lines)
    # Row-major: each row's item then its price, top row first — exactly what detect_tables()
    # needs to see to group them back into a table.
    assert [l.text for l in ordered][:2] == ['item 0', '0.00']


def test_short_margin_note_does_not_flip_page_into_column_mode():
    # A single short line floating near the right margin (a page number, a stray annotation)
    # shouldn't be enough signal to read the whole page as two columns.
    lines = single_column_page() + [Line(text='12', size=8, page=0, x0=500, x1=515, y0=690)]
    assert _find_column_gutter(lines) is None


if __name__ == '__main__':
    tests = [v for k, v in list(globals().items()) if k.startswith('test_')]
    for t in tests:
        t()
        print(f'ok  {t.__name__}')
    print(f'{len(tests)} passed')
