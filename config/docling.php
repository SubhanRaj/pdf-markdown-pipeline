<?php

// Registry for the Docling structure-detection pass (Pass 0 of ConvertDocumentToMarkdown) —
// detects headings/tables/layout before markitdown's text-layer extraction runs. This is a
// separate registry from config/ocr.php's 'engines', which is the main-text OCR dropdown:
// Docling can only call tesseract/easyocr/rapidocr as an OCR backend for pages it can't read
// natively (it cannot call Paddle or Surya directly), and whatever text Docling's own OCR
// produces here is discarded — only the region/table structure it detects is kept. See
// STRUCTURE_RESEARCH.md for the evaluation this was built from.
return [
    'venv' => base_path('storage/app/private/ocr-engines/docling'),

    'default_ocr_engine' => 'tesseract',

    'ocr_engines' => [
        'tesseract' => [
            'label' => 'Tesseract',
            'ocr_lang' => 'hin+eng',
        ],
        'easyocr' => [
            'label' => 'EasyOCR',
            'ocr_lang' => 'hi,en',
        ],
        'rapidocr' => [
            'label' => 'RapidOCR (Docling default backend)',
            // Docling's own default backend silently resolves to a Chinese-pretrained model
            // unless a language is pinned explicitly — confirmed by testing (see
            // STRUCTURE_RESEARCH.md). Never leave this unset.
            'ocr_lang' => 'hi,en',
        ],
    ],
];
