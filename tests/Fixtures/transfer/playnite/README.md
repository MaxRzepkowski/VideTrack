The CSV the Library Exporter Advanced add-on writes: the header in the
language of Playnite's interface (`library-en.csv` English, `library-pl.csv`
Polish), dates as .NET writes them for the Windows region (en-US with a
12-hour clock, pl-PL day first with dots), booleans as `True`/`False`, the
play time in seconds, the user score out of 100, multi-value columns joined
by ", ", CRLF lines. The completion statuses are the ones Playnite creates
(`Beaten`, `Playing`, `Plan to Play`, `Completed`, `Not Played`) plus one a
user made up (`Replaying`). The games and their values are made up.
