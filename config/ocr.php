<?php

// Registry of locally-installed OCR engines available from the "Run OCR Extraction" dropdown
// in the Compare & Verify review modal. Every engine reads the same rasterized page PNGs
// (produced once by RunOcrExtraction via pdftoppm) and is expected to emit Markdown through
// resources/python/pdf_structure_extractor.py's shared heading/list/paragraph classifier, so
// results are comparable across engines. See OCR_RESEARCH.md for background on why these four
// were picked and what tradeoffs (memory, accuracy) were already observed per engine.
return [
    'default' => 'tesseract',

    'engines' => [
        'tesseract' => [
            'label' => 'Tesseract (current default)',
            // System binary, not a dedicated venv — already installed and used in production.
            'binary' => 'tesseract',
        ],
        'easyocr' => [
            'label' => 'EasyOCR',
            'venv' => base_path('storage/app/private/ocr-engines/easyocr'),
        ],
        'paddleocr' => [
            'label' => 'PaddleOCR',
            'venv' => base_path('storage/app/private/ocr-engines/paddleocr'),
        ],
        'surya' => [
            'label' => 'Surya OCR (slow on CPU — see OCR_RESEARCH.md)',
            'venv' => base_path('storage/app/private/ocr-engines/surya'),
            // Surya's recognition step runs a real vision-LLM through llama.cpp, which needs
            // its own binary + shared libs — not a pip dependency, extracted manually from the
            // Ubuntu llama.cpp-tools/libllama0/libggml0 packages into this engine's own venv
            // dir (see OCR_RESEARCH.md for how/why). CPU-only: no GPU backend is loaded.
            'env' => [
                'LLAMA_CPP_BINARY'  => base_path('storage/app/private/ocr-engines/surya/llama-cpp/bin/llama-server'),
                'LD_LIBRARY_PATH'   => base_path('storage/app/private/ocr-engines/surya/llama-cpp/lib'),
                'GGML_BACKEND_PATH' => base_path('storage/app/private/ocr-engines/surya/llama-cpp/lib/ggml/backends0/libggml-cpu-x64.so'),
            ],
        ],
    ],
];
