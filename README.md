# JATS Image Resolver Plugin

This plugin rewrites JATS XML `<graphic>` and `<inline-graphic>` references to
valid OJS file URLs when an XML galley is downloaded. It allows images linked
with `xlink:href` to display correctly on the frontend.

## Features

- Resolves `xlink:href` (and `href`) in JATS XML to OJS download URLs.
- Uses dependent files attached to the XML galley as the image source.
- Works during galley download so the original XML stays unchanged.

## Requirements

- OJS 3.4.x
- JATS XML stored as an Article Galley
- Image files uploaded as dependent files of that XML galley

## Installation

1. Copy the plugin folder to `plugins/generic/jatsImage`.
2. Run the plugin upgrade (if needed) from the OJS admin interface.
3. Enable the plugin in **Website > Plugins**.

## Usage

1. Upload the JATS XML as an article galley.
2. Upload each image referenced by `<graphic>` as a dependent file of that galley.
3. Ensure `xlink:href` matches the image filename (path segments are allowed).
4. View the XML galley; images should load correctly.

## How It Works

When an XML galley is downloaded, the plugin:

1. Loads the JATS XML.
2. Finds `<graphic>` and `<inline-graphic>` nodes.
3. Matches each `xlink:href` against dependent file names (and basenames).
4. Replaces the attribute with a valid OJS download URL.

## Notes

- Only dependent files of the XML galley are considered.
- If `xlink:href` does not match a dependent file name, it is left unchanged.
- The plugin does not modify the XML stored in the database.

## License

GPL v3. See `docs/COPYING`.
