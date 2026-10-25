# Booklove (ebook-love)

WordPress plugin that makes ebooks first-class **documents**, not dead files.

Upload EPUB, MOBI, AZW/AZW3, PDF, TXT, RTF, FB2, CBZ, or DOCX via the Media Library. Booklove mirrors the cleverness WordPress already applies to audio (ID3 → title/content) and PDFs (preview thumbs):

1. **`booklove_read_ebook_metadata()`** — same idea as `wp_read_audio_metadata()`
2. Attachment title, caption, and content filled from book tags
3. Cover extracted and stored like media sizes / featured image
4. TOC parsed (EPUB NCX/nav, FB2 sections, heuristics elsewhere)
5. An **Ebook** CPT document is created, taxonomied (author/subject/series/format), and full-text indexed for site search
6. **OCR hook** (`booklove_ocr_extract`) ready for Tesseract/cloud later — scanned PDFs mark `ocr_status=pending`

Architecture borrows from [Calibre](https://github.com/kovidgoyal/calibre): format registry + metadata priorities, OPF/OCF for EPUB, EXTH for MOBI, filename fallback `Title - Author.ext`, smart-merge metadata bags.

## Install

1. Copy this directory into `wp-content/plugins/ebook-love` (or clone this repo there).
2. Activate **Booklove** in Plugins.
3. Upload a book under **Media → Add New**.
4. Open **Ebooks** in the admin — your document should be there with cover, TOC, and metadata.

Requires PHP 8.1+, WordPress 6.4+, and the `zip` / `xml` / `mbstring` extensions. Imagick optional (PDF first-page covers, like core PDF thumbs).

## Hooks (extension points)

| Hook | Purpose |
|------|---------|
| `booklove_formats` | Register/override formats + extractors |
| `booklove_read_ebook_metadata` | Filter attachment-shaped metadata array |
| `booklove_after_extract` | Post-parse mutate `Book_Metadata` |
| `booklove_ocr_extract` | Return `['text'=>…,'toc'=>…,'status'=>'done']` for OCR |
| `booklove_ocr_auto` | Auto-dispatch OCR when status is `pending` |
| `booklove_document_content` | Filter generated ebook `post_content` |
| `booklove_ebook_ingested` | After CPT create/update from upload |
| `booklove_pdf_cover` | Provide PDF cover bytes without Imagick |

### Example OCR provider

```php
add_filter( 'booklove_ocr_extract', function ( $result, $file, $meta, $args ) {
	// Call Tesseract / cloud OCR; return null to skip.
	$text = my_ocr_pdf( $file );
	if ( ! $text ) {
		return $result;
	}
	return array(
		'text'   => $text,
		'status' => 'done',
	);
}, 10, 4 );
```

## Development notes

- Extractors live in `includes/extractors/` and must stay side-effect free (no WP inserts).
- `Ingestor` owns the WordPress media pipeline: `wp_generate_attachment_metadata`, `add_attachment`, REST field `booklove` on attachments.
- Re-scan from the ebook edit sidebar or `admin-post.php?action=booklove_rescan`.

## License

GPL-2.0-or-later
