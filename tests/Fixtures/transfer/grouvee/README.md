`collection.csv` with the columns of Grouvee's CSV export (format 2, which
carries `igdb_id`): `shelves`, `platforms` and `dates` hold JSON, quoted.
A shelf maps its name to when the game went on it; `dates` lists
playthroughs, with "None" for a missing date and 0 seconds for a time
nobody logged, and one empty playthrough written whenever a game is
shelved. `release_date` may be a quarter ("2014-Q3"). The games, the user
and the values are made up.
