# Customer spreadsheet CSV

Export CSV is for viewing customer lists in spreadsheets, not a byte-identical
backup/import protocol. It retains the current filters, sorting, archive view,
12 columns, comma delimiter, UTF-8 BOM and bounded maximum of 5,000 customers.
Stored records are not changed by export. Existing contact/name normalization still
runs before serialization (for example, name line breaks become spaces).

## Text and numeric representation

One final cell boundary protects every heading and every textual data column:
Name (including fallbacks), Email, Phone, Value tier, Lifecycle, Tags and Segments.
Relationship names are joined before protection. Translated labels pass through the
same boundary. A TAB (U+0009) is prepended to text whose first significant character
is `=`, `+`, `-`, `@` or the full-width `＝`, `＋`, `－`, `＠`. Detection looks past
Unicode separators, controls/format characters and whitespace. Leading controls are
also protected conservatively. The text after the marker is preserved; protection
is applied once, not once per relationship component. All nonempty phone cells get
the same marker to retain their plus sign, leading zeros and long digit sequences.
Empty cells stay empty.

The TAB stays **inside** the double-quoted CSV field. Embedded quotes are doubled;
PHP's proprietary backslash escape is disabled. Commas, semicolons, quotes,
backslashes and remaining embedded line breaks cannot create another CSV cell or
record when parsed with standard comma/double-quote CSV settings.

The five internally formatted metrics (Orders, Spent, AOV, Risk score, Trust score)
retain their numeric output, including negative and zero decimals. The exemption is
based on those known sources/column positions, never `is_numeric()` on customer text.
Headings for these columns are still text and receive protection when needed.

Examples below use `<TAB>` to show the actual U+0009 byte:

| Final text before protection | Serialized CSV cell |
| --- | --- |
| `=1+1` | `"<TAB>=1+1"` |
| Phone `+84901234567` | `"<TAB>+84901234567"` |
| Phone `001234567890` | `"<TAB>001234567890"` |
| `Nguyễn Bảo` | `"Nguyễn Bảo"` |
| Spent metric `-12.50` | `-12.50` |

## Consumer contract and evidence limits

The selected TAB strategy follows [OWASP's Excel-oriented guidance](https://owasp.org/www-community/attacks/CSV_Injection).
The marker changes exported bytes and remains part of a decoded cell; downstream
programmatic imports may display or preserve it. Consumers must account for this
representation deliberately. Removing it and reopening the CSV as a spreadsheet
can reactivate the original formula-like text. This download provides no unsafe
raw bypass or new import feature.

Producer regressions run actual capability/nonce-protected exports in fresh owned
PHP processes, preserving the production exit. Byte assertions check quoted TABs
and enclosure doubling; Python's independent `csv` parser checks decoded content
and row/column shape. Persisted synthetic CRM rows/events are compared before and
after, with mail intercepted. Server-rendered help checks are not browser-layout QA.
Serialization uses [PHP's documented empty escape support since 7.4](https://www.php.net/manual/en/function.fputcsv.php).

**Spreadsheet application test: NOT RUN.** Numbers 14.2 is installed and its open
panel was accessible, but no app-specific network-disabled configuration was
established within existing permissions. No fixture was opened/imported; desktop
permissions and network settings were not expanded. Excel and other spreadsheet
clients are unverified. A CSV parser is not a formula engine. No universal safety,
locale/custom-import compatibility, save/reopen or arbitrary edit/resave guarantee
is claimed. This is reference-based output policy plus producer-side regression
evidence, not application-level certification.
