`library.tsv` has the header LibraryThing's tab-delimited export writes (the
column list of the export since its 2014 rebuild): dates in brackets
(`[2023-05-08]`, a year alone for an unknown day), the primary author as
"Last, First", the book's own ISBN in brackets and every edition's in
`ISBNs`, collections and tags comma-separated. LibraryThing writes UTF-8
with stray bytes of another encoding in some values; one author here is a
single Windows-1252 byte to stand for them. The books and their values are
made up.
