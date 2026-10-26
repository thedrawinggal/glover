# smalot/pdfparser (bundled)

A pinned, unmodified copy of the `src/` folder of [smalot/pdfparser](https://github.com/smalot/pdfparser), used by the Document Library's full-text search to read PDF text when the host can't run poppler's `pdftotext`. See `docs/features/document-library.md`, "Full-text search".

- Version: 2.13.0 (released 2026-09-25)
- Source: https://api.github.com/repos/smalot/pdfparser/zipball/4e964c7336b6c6b7f5a1de7e4fedbf2477265f5a
- Archive SHA-256: `1250d3da7c32b9f9fbb3c53bbe7dacc2c0d141d3832d708a2937af672d0e0cb9`
- License: LGPL-3.0 (`LICENSE.txt`). The library is used unmodified, as a separate component.
- Requires PHP's `zlib`, `iconv` and `mbstring` extensions. The Composer package also lists `symfony/polyfill-mbstring`, which is only needed when `mbstring` is missing, so it is not bundled.
- Loaded by a small autoloader in `includes/features/document-library/document-library-search-extract.php`. No Composer.

To update: download a release at least two weeks old, replace `src/` and `LICENSE.txt`, update this file, and re-run the search checks in the feature spec.
