# ReviewFlow Plugin

This plugin allows you to define per-page multi-step validation flows, where named users confirm specific roles (author, reviewer, validator, etc.).

## Page Syntax

Use:

~~REVIEWFLOW|
version=2.1
author=@alice
reviewer=@bob
validator=@carol
render=table
~~

- Each role must be confirmed by the matching user.
- Roles can have any label.
- Confirmation is stored in metadata.
- Confirmations are stored in a tamper-proof history, using chained hashes (similar to a blockchain). Each confirmation includes a timestamp from an external time authority and a hash of the previous entry, preventing retroactive tampering.
- Banners indicate status (red = incomplete, green = all confirmed).

## Global Summary

On a separate page:

~~REVIEWFLOWPAGES~~
~~REVIEWFLOWPAGES|regulatory:smq~~

Lists all pages with missing confirmations.
