`PlaybackReportingBackup-*.tsv` has the shape the Playback Reporting plugin
writes (jellyfin/jellyfin-plugin-playbackreporting, `ActivityRepository.
ExportRawData`, `EventMonitorEntryPoint.GetItemName`): the PlaybackActivity
table row by row, nine tab-separated fields, no header, no quoting, the date
in the server's local time with seven fraction digits, user ids as GUIDs
without dashes, an episode named "Show - s01e01 - Title" and 00 for a number
it lacks. The users, items and playbacks are made up.
