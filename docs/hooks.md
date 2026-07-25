# Unused Image Cleaner — Developer Hooks

Unused Image Cleaner is built to be extended. A handful of hooks let you widen what the scan
looks at or add an entirely new scanner of your own — no core edits required.

All hooks are prefixed `uic_`.

| Hook | Type | Use it to |
|------|------|-----------|
| [`uic_register_scanners`](#uic_register_scanners) | action | Add your own scanner to the registry |
| [`uic_image_key_hints`](#uic_image_key_hints) | filter | Teach the Generic Fallback Scanner a new field-name pattern |
| [`uic_content_scanner_post_types`](#uic_content_scanner_post_types) | filter | Change which post types the Content Scanner reads |

---

## `uic_register_scanners`

Fires once, during boot, after every built-in scanner has been registered. Use it to add a
scanner of your own — for a custom field type, a page builder the plugin doesn't recognise by
name, or anything else with its own way of storing an image reference.

**Parameters**

- `ScannerRegistry $registry` — call `$registry->add( $scanner )` with an instance implementing
  `UnusedImageCleaner\Scanner\Contracts\Scanner` (`id()`, `label()`, `is_applicable()`,
  `checks()`, `version()`, `scan()` — see that interface in this repo's `src/Scanner/Contracts/`
  for the full contract, and `src/Scanner/Reference.php` for the object `scan()` returns).

```php
add_action( 'uic_register_scanners', function ( $registry ) {
	$registry->add( new My_Custom_Scanner() );
} );
```

**Be aware:** coverage and confidence are only ever raised by a scanner listed in the plugin's
own weight/reliability table, which isn't filterable. A scanner registered this way behaves like
the built-in Generic Fallback Scanner — it can hold an image back from a Trash recommendation by
finding a reference to it, but it can never raise confidence or coverage on its own. That's a
deliberate ceiling: the plugin doesn't know how reliable a third-party scanner's search really
is, so it doesn't guess.

## `uic_image_key_hints`

Filters the lowercase field-name fragments the Generic Fallback Scanner treats as "probably
holds an image" when it meets an integer it can't otherwise identify — things like `_thumb`,
`_photo`, `_bg_image`. Additions only: the built-in fragments are always tested, since removing
one turns a used image into a false deletion candidate.

**Parameters**

- `string[] $hints` — Lowercase fragments, matched as substrings against meta/option keys.

```php
add_filter( 'uic_image_key_hints', function ( $hints ) {
	// Your own custom field naming convention.
	$hints[] = '_hero_media';
	return $hints;
} );
```

## `uic_content_scanner_post_types`

Filters which public post types the Content Scanner reads for `<img>` tags, Gutenberg blocks,
and shortcodes. By default this is every registered public post type except `attachment`,
discovered at runtime — so a custom post type is covered automatically, without this filter, the
moment it's registered as public. Use this filter only to narrow that list, or to add a post type
that's registered as non-public but still holds real content.

**Parameters**

- `string[] $types` — Post type slugs.

```php
add_filter( 'uic_content_scanner_post_types', function ( $types ) {
	// Skip a large, image-free custom post type to save scan time.
	return array_diff( $types, array( 'log_entry' ) );
} );
```

---

Found a bug, or want a new hook? Open an issue or pull request on
[GitHub](https://github.com/devmonowar/unused-image-cleaner).
