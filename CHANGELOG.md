# Changelog

Written by release-please from the pull request titles on `main`. The first release is summarized by hand.

## [0.6.0](https://github.com/MaxRzepkowski/VideTrack/compare/v0.5.0...v0.6.0) (2026-11-28)


### Features

* import what you watch in Nuvio, every quarter hour ([173e585](https://github.com/MaxRzepkowski/VideTrack/commit/173e5857a45eb4a4368b182bd6d65dc407e1939b))
* move connected accounts from Import to Integrations ([1f62a7b](https://github.com/MaxRzepkowski/VideTrack/commit/1f62a7b05ee380240eb1d9bac76fa74b3ed8cada))
* move the expired data clean-up to the Maintenance tab ([e6ccee6](https://github.com/MaxRzepkowski/VideTrack/commit/e6ccee68ce0c00b8cf5fd726eb5c9a9aed71beca))
* remove titles, people and companies nobody holds, every night ([214762a](https://github.com/MaxRzepkowski/VideTrack/commit/214762a54bd8f432033e2fde14e07f5e1c25fed9))
* save a settings card with plain buttons under its fields ([8e2b881](https://github.com/MaxRzepkowski/VideTrack/commit/8e2b8814d9db95ce1c91674f36b0b44452a19127))
* show the admin an Instance page that flags a refused provider key ([19901f9](https://github.com/MaxRzepkowski/VideTrack/commit/19901f9fc452b0b9f8dbb706739a78590d35bedb))
* warn the admin when background work, storage or Apprise fail ([1e7e271](https://github.com/MaxRzepkowski/VideTrack/commit/1e7e271997aaf00a9e060950ea863cb14090e249))


### Bug Fixes

* clear expired cache rows left in the database after the cache moved to files ([83965ac](https://github.com/MaxRzepkowski/VideTrack/commit/83965ac16adce0e3903ab3b1503cdeddfd93e6cf))
* connect an account whose key is all a service asks for ([07911a1](https://github.com/MaxRzepkowski/VideTrack/commit/07911a1f8c3bf031bc1c117432b308394e2707be))
* right-clicking a watched title no longer shows a loader ([#139](https://github.com/MaxRzepkowski/VideTrack/issues/139)) ([66f131f](https://github.com/MaxRzepkowski/VideTrack/commit/66f131f66c5f545453305dba0b5f8fa2479da01a))
* stop imports logging a viewing another source already logged ([fb95cd8](https://github.com/MaxRzepkowski/VideTrack/commit/fb95cd87b4b9f69763e44b9532fc42947a50f79b))
* stop the false scheduler alarm on start and calm error banners ([#137](https://github.com/MaxRzepkowski/VideTrack/issues/137)) ([1fdc8ad](https://github.com/MaxRzepkowski/VideTrack/commit/1fdc8ad9d02b8a77cd40df4812e4c34288a0cfa7))
* wait out a service that asks for a pause the same way in every import ([3cd7237](https://github.com/MaxRzepkowski/VideTrack/commit/3cd723758cf38d8ebbfa0417e9b8b39f31033351))


### Performance

* refresh the query planner statistics on every start ([#134](https://github.com/MaxRzepkowski/VideTrack/issues/134)) ([cea87b1](https://github.com/MaxRzepkowski/VideTrack/commit/cea87b1155778a15bfff64ce35a54cdc08439c6d))

## [0.5.0](https://github.com/MaxRzepkowski/VideTrack/compare/v0.4.0...v0.5.0) (2026-11-26)


### ⚠ BREAKING CHANGES

* name a statistics tile's total `amount`, not `minutes`, in the API ([#130](https://github.com/MaxRzepkowski/VideTrack/issues/130))

### Features

* add up to 25 titles to a list in one API call ([b88f8ed](https://github.com/MaxRzepkowski/VideTrack/commit/b88f8ed50165614d4e3e73a50b1a539469f8f288))
* let the assistant add many titles to a list at once ([2fab00a](https://github.com/MaxRzepkowski/VideTrack/commit/2fab00a9f3b779ffd1b622e13a486fc979c7f49e))
* let the assistant reorder and duplicate lists ([484a4b7](https://github.com/MaxRzepkowski/VideTrack/commit/484a4b779a0566c26f2422286ece542182a401a4))
* show how many notifications are unread on the bell ([#131](https://github.com/MaxRzepkowski/VideTrack/issues/131)) ([e3b5bee](https://github.com/MaxRzepkowski/VideTrack/commit/e3b5beeb8cf75e1c21403d6bde70937301f7cfdd))


### Bug Fixes

* count release and added days on Suggestions in the viewer's time zone ([#119](https://github.com/MaxRzepkowski/VideTrack/issues/119)) ([a7b53b7](https://github.com/MaxRzepkowski/VideTrack/commit/a7b53b733c08d6f3938bf19cc1094873cc91e541))
* keep outside times and statistics on APP_TIMEZONE instead of UTC ([#122](https://github.com/MaxRzepkowski/VideTrack/issues/122)) ([4b7a90d](https://github.com/MaxRzepkowski/VideTrack/commit/4b7a90d52be5fc18751144f010c08fd20ab9eb02))
* keep refreshing collections when one comes from a disabled provider ([#117](https://github.com/MaxRzepkowski/VideTrack/issues/117)) ([b966646](https://github.com/MaxRzepkowski/VideTrack/commit/b966646709d425e4b1e832d2daefc1ca245cde1c))
* let your own bell, then the backlog, decide where a title shows over its status ([#118](https://github.com/MaxRzepkowski/VideTrack/issues/118)) ([3957f41](https://github.com/MaxRzepkowski/VideTrack/commit/3957f41fb4b52ce58cda47fe3b4a2a6fd520d8dc))
* mark a season's first episode as its premiere when numbering is absolute ([#124](https://github.com/MaxRzepkowski/VideTrack/issues/124)) ([4d3ddd9](https://github.com/MaxRzepkowski/VideTrack/commit/4d3ddd9117b0fb3eca652cb1e442b00badce0208))
* name a statistics tile's total `amount`, not `minutes`, in the API ([#130](https://github.com/MaxRzepkowski/VideTrack/issues/130)) ([74eee6e](https://github.com/MaxRzepkowski/VideTrack/commit/74eee6e2c8d38cd025f2e75475853cce2e3dd1f7))
* name an RSS feed's language in a form feed readers understand ([#129](https://github.com/MaxRzepkowski/VideTrack/issues/129)) ([d08ca73](https://github.com/MaxRzepkowski/VideTrack/commit/d08ca739dac9109983a03e8dd2243680d25739c6))
* stop the assistant's answers repeating a title's state ([#128](https://github.com/MaxRzepkowski/VideTrack/issues/128)) ([f06b337](https://github.com/MaxRzepkowski/VideTrack/commit/f06b3373f7fc3abf5858e0956681244bddc535b9))
* write durations and range filters the way each language does ([#120](https://github.com/MaxRzepkowski/VideTrack/issues/120)) ([bb1ca5b](https://github.com/MaxRzepkowski/VideTrack/commit/bb1ca5bdf9ab8d2d822ba599ae10aa72e3391a2e))


### Performance

* load the modals and the command palette only when first opened ([a057dd5](https://github.com/MaxRzepkowski/VideTrack/commit/a057dd5f7b71a88cd7984752ca284a0b86d5052a))
* open a game company's page in one request to IGDB instead of three ([105e1ea](https://github.com/MaxRzepkowski/VideTrack/commit/105e1ea24a9ee6f120a43bb4f0f6e5c58dfe099d))
* open the calendar, countdowns and home page about twice as fast ([d64fc49](https://github.com/MaxRzepkowski/VideTrack/commit/d64fc493f588ee5f42c3dbc0cafc19332c2b609e))
* open the history, ratings and favorites panels faster ([3637d43](https://github.com/MaxRzepkowski/VideTrack/commit/3637d4398236f79914963ef13d620358c64d6049))
* send the bell's list only once the bell is opened ([d501eef](https://github.com/MaxRzepkowski/VideTrack/commit/d501eef945f134b83fde1a22a7baf848b1e31ba1))
* trim about 30 KB from every page's first load ([b7d72ea](https://github.com/MaxRzepkowski/VideTrack/commit/b7d72eaefccd6603853c7a5038024569a16b8092))


### Dependencies

* bump the production group with 3 updates ([#125](https://github.com/MaxRzepkowski/VideTrack/issues/125)) ([374d70e](https://github.com/MaxRzepkowski/VideTrack/commit/374d70edebfa91b4b48332d32242459bb61478b1))
* bump the production group with 3 updates ([#127](https://github.com/MaxRzepkowski/VideTrack/issues/127)) ([3f8e55d](https://github.com/MaxRzepkowski/VideTrack/commit/3f8e55da5e8654eda4c114771617153db8050f0b))
* bump vue from 3.5.45 to 3.5.46 in the vue-and-inertia group ([#126](https://github.com/MaxRzepkowski/VideTrack/issues/126)) ([c430f73](https://github.com/MaxRzepkowski/VideTrack/commit/c430f736f78aae6ca10e157fa770065ad483cf65))
* update Symfony to 8.1.12 for mailer, routing and http-kernel advisories ([#121](https://github.com/MaxRzepkowski/VideTrack/issues/121)) ([0b0caec](https://github.com/MaxRzepkowski/VideTrack/commit/0b0caecf4dfa5a4f84da9562b3184501beebeb9e))

## [0.4.0](https://github.com/MaxRzepkowski/VideTrack/compare/v0.3.0...v0.4.0) (2026-11-24)


### Features

* add Brazilian Portuguese as an interface language ([#100](https://github.com/MaxRzepkowski/VideTrack/issues/100)) ([7d12528](https://github.com/MaxRzepkowski/VideTrack/commit/7d125282490aad5cb8ad6b4583ea349a195663bb))
* add Czech as an interface language ([#104](https://github.com/MaxRzepkowski/VideTrack/issues/104)) ([96e0481](https://github.com/MaxRzepkowski/VideTrack/commit/96e04816fe7bac3d2d397d4bfa2450b22112d29d))
* add Dutch as an interface language ([#102](https://github.com/MaxRzepkowski/VideTrack/issues/102)) ([0990582](https://github.com/MaxRzepkowski/VideTrack/commit/0990582ee29925b9d29f0bf8d3cf101ca1d87572))
* add French as an interface language ([#98](https://github.com/MaxRzepkowski/VideTrack/issues/98)) ([2048b36](https://github.com/MaxRzepkowski/VideTrack/commit/2048b369bf357e5397cc045530e2fdc083f69aa6))
* add German as an interface language ([#97](https://github.com/MaxRzepkowski/VideTrack/issues/97)) ([8f2bbe6](https://github.com/MaxRzepkowski/VideTrack/commit/8f2bbe6fa82d957128520265e863ae4ea1f773eb))
* add Italian as an interface language ([#101](https://github.com/MaxRzepkowski/VideTrack/issues/101)) ([21161e4](https://github.com/MaxRzepkowski/VideTrack/commit/21161e4dc44a089b9c9831f8d4301d458d81a106))
* add Japanese as an interface language ([#108](https://github.com/MaxRzepkowski/VideTrack/issues/108)) ([42f6706](https://github.com/MaxRzepkowski/VideTrack/commit/42f6706c6acbbc35751678e3d6969fe8a1ae5c25))
* add Korean as an interface language ([#109](https://github.com/MaxRzepkowski/VideTrack/issues/109)) ([1bd2e8a](https://github.com/MaxRzepkowski/VideTrack/commit/1bd2e8a8da4eff70b84906c79a68cb33f4152fe5))
* add Russian as an interface language ([#106](https://github.com/MaxRzepkowski/VideTrack/issues/106)) ([d0c5836](https://github.com/MaxRzepkowski/VideTrack/commit/d0c5836a15d4cfb7b8366d2cc9db60bdf3031d82))
* add Simplified Chinese as an interface language ([#107](https://github.com/MaxRzepkowski/VideTrack/issues/107)) ([17ae29c](https://github.com/MaxRzepkowski/VideTrack/commit/17ae29c85b2e448e5e834fac6d4a1792b040d8d9))
* add Spanish as an interface language ([#99](https://github.com/MaxRzepkowski/VideTrack/issues/99)) ([acf68f5](https://github.com/MaxRzepkowski/VideTrack/commit/acf68f5d725f0a108187516b165e6ef31b44da50))
* add Swedish as an interface language ([#105](https://github.com/MaxRzepkowski/VideTrack/issues/105)) ([7c98cf9](https://github.com/MaxRzepkowski/VideTrack/commit/7c98cf9df25dde7a8908af2876e57803e4c11d67))
* add Ukrainian as an interface language ([#103](https://github.com/MaxRzepkowski/VideTrack/issues/103)) ([bfd2476](https://github.com/MaxRzepkowski/VideTrack/commit/bfd2476936f163f97593a3f66bf8253eb5523278))


### Bug Fixes

* ask for the password before the account's e-mail changes ([#114](https://github.com/MaxRzepkowski/VideTrack/issues/114)) ([44036bb](https://github.com/MaxRzepkowski/VideTrack/commit/44036bb58755d7ea43c0f704f746c98b08b80a65))
* keep a private profile's lists and a blocked account's feeds closed ([#112](https://github.com/MaxRzepkowski/VideTrack/issues/112)) ([e873fe6](https://github.com/MaxRzepkowski/VideTrack/commit/e873fe6097ddacc014da44439b628de73869d281))
* labels that meant two things get their own words ([#95](https://github.com/MaxRzepkowski/VideTrack/issues/95)) ([7ae0d68](https://github.com/MaxRzepkowski/VideTrack/commit/7ae0d68714070989f2175ad0e4aa851cace920a6))
* never make an existing account an administrator on start ([#113](https://github.com/MaxRzepkowski/VideTrack/issues/113)) ([fc352aa](https://github.com/MaxRzepkowski/VideTrack/commit/fc352aaac545f1e6328b9adbd92ccab5b6bdf065))
* open the home network to every account, the server only to admins ([#111](https://github.com/MaxRzepkowski/VideTrack/issues/111)) ([da67ff4](https://github.com/MaxRzepkowski/VideTrack/commit/da67ff4a66d1e3e5f2ca846baee16160ceac4376))
* switch off Caddy's admin API in the image ([#110](https://github.com/MaxRzepkowski/VideTrack/issues/110)) ([a0a8e41](https://github.com/MaxRzepkowski/VideTrack/commit/a0a8e4174b90fd8ecd9c6af23a05e09f47d5ab93))


### Dependencies

* bump dedoc/scramble from 0.13.54 to 0.13.55 in the production group ([#91](https://github.com/MaxRzepkowski/VideTrack/issues/91)) ([579d357](https://github.com/MaxRzepkowski/VideTrack/commit/579d35759c01d9e2dd541cbfb25ffdf790f3e01b))
* bump the production group with 2 updates ([#94](https://github.com/MaxRzepkowski/VideTrack/issues/94)) ([21d9cda](https://github.com/MaxRzepkowski/VideTrack/commit/21d9cda3369c7236dac98e09b49876c1b17a2107))

## [0.3.0](https://github.com/MaxRzepkowski/VideTrack/compare/v0.2.0...v0.3.0) (2026-11-22)


### Features

* a click on a menu group opens its first page, library reordered ([dd25a67](https://github.com/MaxRzepkowski/VideTrack/commit/dd25a6762590fa431a25a6b2dc6024233c4593f7))
* a finished title's button says when it was finished ([31a91a9](https://github.com/MaxRzepkowski/VideTrack/commit/31a91a96c3d8b882f76ca2b101162a198280b622))
* a title cut short shows in full on hover ([936f85b](https://github.com/MaxRzepkowski/VideTrack/commit/936f85b60fe2c5f09d469255cd95408003cac11c))
* card menu on phones says the rating, lists and heart a title holds ([1a16a43](https://github.com/MaxRzepkowski/VideTrack/commit/1a16a43ce34530763bc16c515a98cef318c092a9))
* choose how much adult content search and browsing show ([f9c327d](https://github.com/MaxRzepkowski/VideTrack/commit/f9c327dd8d8e22162061fd704620993982160e0c))
* choose imports and exports from tiles with service logos ([14de870](https://github.com/MaxRzepkowski/VideTrack/commit/14de87026f130ec8fb89635e115eeaea6c118f70))
* compare ratings and hide what you know on other people's panels ([e747c46](https://github.com/MaxRzepkowski/VideTrack/commit/e747c4676e55b3fed76a16a97d7bb5cad1e515b4))
* details open in the shared dialog with refresh as one of its rows ([12a0ce0](https://github.com/MaxRzepkowski/VideTrack/commit/12a0ce03d645d68c7be641d65cc996bcb4078c5b))
* following gets its own page under releases instead of a profile tab ([fa9abd7](https://github.com/MaxRzepkowski/VideTrack/commit/fa9abd7f00c1764f85cfab04b29b7fdd3630cc42))
* group the main menu into library, discover and releases ([a4fd22b](https://github.com/MaxRzepkowski/VideTrack/commit/a4fd22b5fa7dbe6cbe70aca9189b8916e22e0b33))
* import a Hardcover library through a connected account ([24ca400](https://github.com/MaxRzepkowski/VideTrack/commit/24ca4002552663c4d8134a9d7cd8df324d27b953))
* import audiobooks and e-books from an Audiobookshelf server ([143a24e](https://github.com/MaxRzepkowski/VideTrack/commit/143a24eba8e559f7a81a91fb75b5b690b788378b))
* import beaten and played games from RetroAchievements ([87fe7eb](https://github.com/MaxRzepkowski/VideTrack/commit/87fe7eb4efa83f0a463861c676f22d49b42f3aa8))
* import books from a BookWyrm export ([0878276](https://github.com/MaxRzepkowski/VideTrack/commit/08782762dcbb3bd6d36cde2043bd06c6ad0c736f))
* import books read and being read from a Storyteller server ([1b3e112](https://github.com/MaxRzepkowski/VideTrack/commit/1b3e1125da06b36cf359ea83d57f8e23099a6eb2))
* import history, ratings and lists from a WeTrakr export ([bd0c27f](https://github.com/MaxRzepkowski/VideTrack/commit/bd0c27f2d7bc889b2ebd2161937c2d9df221608c))
* import history, ratings, watchlist and lists from an MDBList account ([29dbfea](https://github.com/MaxRzepkowski/VideTrack/commit/29dbfea03908425c038c52d224fc3626e0f2960f))
* import manga reading and lists from a MangaBaka account ([f4ed8be](https://github.com/MaxRzepkowski/VideTrack/commit/f4ed8bec84449d3484a4760c5ff159074ab24360))
* import manga, comics and books read and being read from a Kavita server ([4c985e0](https://github.com/MaxRzepkowski/VideTrack/commit/4c985e0575cf4f0af3d10d03875e9110bb67c3df))
* import reading from KOReader's statistics ([76f1850](https://github.com/MaxRzepkowski/VideTrack/commit/76f18506386c33c0062c04f15f0fc2b68b762e82))
* import watchlist, favorites, ratings and lists from a TMDB account ([291457b](https://github.com/MaxRzepkowski/VideTrack/commit/291457b3f5890e1d03998175bcb22d89d6eb0e53))
* install VideTrack as an app on phones and computers ([1af944e](https://github.com/MaxRzepkowski/VideTrack/commit/1af944ebe8c6a16a9891febb5f07c1738a528083))
* let AI assistants use your library through an MCP server ([90507b4](https://github.com/MaxRzepkowski/VideTrack/commit/90507b4b4ef3a77d0c6f7c7eb632c96b74617c93))
* list your countdowns in the API and for AI assistants ([3fd4704](https://github.com/MaxRzepkowski/VideTrack/commit/3fd4704630bd71cd9db96613a77aa56b1e0a3149))
* long descriptions on title pages fold behind "Show more" ([12c447a](https://github.com/MaxRzepkowski/VideTrack/commit/12c447a68108a82e3c4e1cb68c91021de2807527))
* make the settings wider and consistent, with a steady save bar ([c0d5b27](https://github.com/MaxRzepkowski/VideTrack/commit/c0d5b275e17aca169d947b26ebd72109d0756eb6))
* move the calendar subscription to its own settings page ([42d9387](https://github.com/MaxRzepkowski/VideTrack/commit/42d93879c40489b5cc39a01297fd6f5c05466395))
* one action per state in the status dialog, with steps and a way back ([732b483](https://github.com/MaxRzepkowski/VideTrack/commit/732b4835ca8785eb527b6927b3ca0e555cd8930e))
* page headings wear the colour of their menu group ([e7bb4b4](https://github.com/MaxRzepkowski/VideTrack/commit/e7bb4b469d556e79c6792902e79109e4ec63e761))
* pick what to search with a button per kind on the new search page ([05c41df](https://github.com/MaxRzepkowski/VideTrack/commit/05c41dffb8cb6ea435035d67a48757552c2b38a4))
* rate with ten stars and confirm in the new dialog, a sheet on phones ([58fa9a8](https://github.com/MaxRzepkowski/VideTrack/commit/58fa9a86c5a0002dc0e9bd4e0a93eaae29742713))
* read your statistics in the API and through AI assistants ([b095073](https://github.com/MaxRzepkowski/VideTrack/commit/b095073253864f7af12c0e693f9acbe59a022fce))
* remember where each history entry came from ([c8ae25c](https://github.com/MaxRzepkowski/VideTrack/commit/c8ae25c03c6e0ef626361e94f7349ef24dc1e852))
* see what is trending in the API and through AI assistants ([3556beb](https://github.com/MaxRzepkowski/VideTrack/commit/3556beb27a9c31142dcc22550f29fea88c2539ba))
* set tracking sources and options per media type ([6403bb6](https://github.com/MaxRzepkowski/VideTrack/commit/6403bb62af41f55b33ffe50f91d4f9e56a199ce1))
* show DLC, remakes and remasters beside the games you track ([3b728aa](https://github.com/MaxRzepkowski/VideTrack/commit/3b728aa44a2ff976a39f3b0300402c96dbe190f3))
* show notifications in the browser and the installed app ([1a83c39](https://github.com/MaxRzepkowski/VideTrack/commit/1a83c395540d94fca50808e510754847d78b3550))
* show what a title is based on and what it became in other media ([88f39ee](https://github.com/MaxRzepkowski/VideTrack/commit/88f39eeb2d732433ea1c0c7ced98167c75dfe314))
* show what each notification changed, by day, in a redesigned bell ([a3b08c5](https://github.com/MaxRzepkowski/VideTrack/commit/a3b08c58d7511a40ef952bbc77c82cb31bd34c49))
* sort the history by how many times each title was finished ([3c9b2e8](https://github.com/MaxRzepkowski/VideTrack/commit/3c9b2e88447c1f39623e4e93f5a65fe3ee7a01f7))
* the command palette finds your lists and collections ([720250c](https://github.com/MaxRzepkowski/VideTrack/commit/720250cd6d5c2834d7464f6b3cf517cf9ff2af82))
* the rating dialog says the score under the mouse ([82a5172](https://github.com/MaxRzepkowski/VideTrack/commit/82a5172ba41517158887ac01f08906755d8d40e8))
* tick lists in the new dialog, each saying how many items it holds ([0345ec9](https://github.com/MaxRzepkowski/VideTrack/commit/0345ec9767b96923f6304f036d99e61eab00a56c))


### Bug Fixes

* a calendar card of several episodes lights up on hover like the rest ([0006c4c](https://github.com/MaxRzepkowski/VideTrack/commit/0006c4cff42f893935c67fcce57d3a73b63a0daa))
* a collection offers its refresh once, in its details window ([787e8e1](https://github.com/MaxRzepkowski/VideTrack/commit/787e8e1a0b126f4443d05afa8f5aefb89ed86b59))
* a title tied to an ancient book no longer fails to open ([53802b9](https://github.com/MaxRzepkowski/VideTrack/commit/53802b9c6bbc6f090c4a54e2bbb7b6c3564a70e2))
* choose a rating with a finger and save it, instead of rating on the first tap ([d4c0417](https://github.com/MaxRzepkowski/VideTrack/commit/d4c04178bd59e84c955f393807eb0084d907f205))
* connect Hardcover with your own API key, nothing to set up in .env ([3af184b](https://github.com/MaxRzepkowski/VideTrack/commit/3af184bbf8094b54c93f7292890442b0327ea552))
* connect MangaBaka with a personal token, nothing to set up in .env ([ff38a9b](https://github.com/MaxRzepkowski/VideTrack/commit/ff38a9b8c49ac008fd84ff068c0babc2d7c7b07a))
* connect MDBList with your own API key, nothing to set up in .env ([8543ab2](https://github.com/MaxRzepkowski/VideTrack/commit/8543ab29d4d8e0658e0a980cf6018289e4473d59))
* connect Simkl through your own Simkl app, nothing to set up in .env ([c374356](https://github.com/MaxRzepkowski/VideTrack/commit/c374356cfb652d1907f6c526f1808d9de50e37b5))
* deleting a list goes to your lists instead of a missing page ([e7140a3](https://github.com/MaxRzepkowski/VideTrack/commit/e7140a3d302a87f1e2986e4524b3e08959220470))
* drop connections made through an operator's app instead of expiring them ([56b63ba](https://github.com/MaxRzepkowski/VideTrack/commit/56b63ba3f5bb1154ec592a19b294baba232c37e5))
* import Steam with your own Web API key, nothing to set up in .env ([3e277d4](https://github.com/MaxRzepkowski/VideTrack/commit/3e277d4f50aa2d0709ecd7d72ba8514a2a0d9de6))
* keep a Simkl connection when a token refresh is only rate-limited ([c22f351](https://github.com/MaxRzepkowski/VideTrack/commit/c22f35191b5e87c0c62b86214a98648691362674))
* keep the bell's unread count and list on the notifications page ([e8514ca](https://github.com/MaxRzepkowski/VideTrack/commit/e8514cafb675bc4725f3d6db3236b30f36329c62))
* menu rows of Library and Releases fill with their colour on hover ([d1a5c51](https://github.com/MaxRzepkowski/VideTrack/commit/d1a5c5171b7e0b08f21d6adc18b012c31d2ccd04))
* open uploaded databases read-only without leaving files beside them ([44678c9](https://github.com/MaxRzepkowski/VideTrack/commit/44678c93bbd565e60b4f229d683be29eb6c0cf53))
* pages show their texts from the first paint, never their keys ([498f03d](https://github.com/MaxRzepkowski/VideTrack/commit/498f03d17c09156783cc7f79881c36aa58f1fbf6))
* pull the bell's sheet down to close, and delete a notification from its row ([1ce1b9e](https://github.com/MaxRzepkowski/VideTrack/commit/1ce1b9ead308444839659482ecd48f0fcc16c8f7))
* recommended and similar titles no longer share one name ([d21e5d4](https://github.com/MaxRzepkowski/VideTrack/commit/d21e5d44191f88aec3e4872906d2f632c3287e9b))
* stop going over a service's request limit at the turn of a minute ([5b82d70](https://github.com/MaxRzepkowski/VideTrack/commit/5b82d708133889d9e27e9b65e7c4d8258f23249d))


### Performance

* profile panels open several times faster ([cd95aca](https://github.com/MaxRzepkowski/VideTrack/commit/cd95acaa657c97ff0b692e7479048ecce2e295d6))
* warm trending shelves only in languages somebody reads in ([3bad9be](https://github.com/MaxRzepkowski/VideTrack/commit/3bad9beb50e6ef2f50c6fae99a463c28a04e1339))


### Dependencies

* bump simple-icons from 16.37.0 to 16.38.0 in the production group ([#65](https://github.com/MaxRzepkowski/VideTrack/issues/65)) ([2348b03](https://github.com/MaxRzepkowski/VideTrack/commit/2348b03de82d55b7c5e293de42a086f785efd002))
* bump the production group with 5 updates ([#64](https://github.com/MaxRzepkowski/VideTrack/issues/64)) ([c83e493](https://github.com/MaxRzepkowski/VideTrack/commit/c83e4934a552309aa6fcf0038952cd878d3684b0))

## [0.2.0](https://github.com/MaxRzepkowski/VideTrack/compare/v0.1.0...v0.2.0) (2026-11-18)


### ⚠ BREAKING CHANGES

* stop sending the visitor's browser header to providers ([#49](https://github.com/MaxRzepkowski/VideTrack/issues/49))

### Features

* add webhooks to settings ([5cdbff5](https://github.com/MaxRzepkowski/VideTrack/commit/5cdbff5f238c03e17bcbb9445c6f02b8783a1787))
* browse manga by MyAnimeList's rankings on the discover page ([2c658c0](https://github.com/MaxRzepkowski/VideTrack/commit/2c658c0511ec62efc4ad822857f26ef540ca4002))
* compare your ratings with everyone else's in statistics ([f914194](https://github.com/MaxRzepkowski/VideTrack/commit/f91419467654ac4445d5092188c84db5920a6dea))
* export anime and manga lists in MyAnimeList's XML format ([58af4c2](https://github.com/MaxRzepkowski/VideTrack/commit/58af4c20325f4b15628ab52dd4f2ffb02d7bb6ba))
* fill out the manga page with MyAnimeList's details ([fd93879](https://github.com/MaxRzepkowski/VideTrack/commit/fd93879876e00347b586a0fb98b55192531d64ed))
* fit statistics to phone screens and screen readers ([2332b82](https://github.com/MaxRzepkowski/VideTrack/commit/2332b8214075ccb77a8dfbd35f332af7272f981b))
* import a MyAnimeList XML export file ([209c5c5](https://github.com/MaxRzepkowski/VideTrack/commit/209c5c56b46d47d619a88177cad357eee81f077f))
* import from Letterboxd, TV Time, StoryGraph, Backloggd and Jellyfin Playback Reporting ([cde2e49](https://github.com/MaxRzepkowski/VideTrack/commit/cde2e4957874b778f233ef4877ae66bb3fa89dfb))
* import from Netflix, Playnite, GOG Galaxy, Calibre and five more ([055c415](https://github.com/MaxRzepkowski/VideTrack/commit/055c4151284c78e99f27ef157a4335fce031e327))
* link statistics from the dashboard and your profile ([d7afb4d](https://github.com/MaxRzepkowski/VideTrack/commit/d7afb4ded5d58479b3993956c441c9c11489db60))
* log in with your own OpenID Connect server, Google, GitHub or GitLab ([f374558](https://github.com/MaxRzepkowski/VideTrack/commit/f37455830c3d601e9ee03f02f919b76854824bf0))
* narrow the calendar feed, follow it as RSS and replace its address ([4337d09](https://github.com/MaxRzepkowski/VideTrack/commit/4337d09de10fe6060cddfd405d32a1bb27fd66d6))
* notification channels for Discord, e-mail and Apprise services ([200f78f](https://github.com/MaxRzepkowski/VideTrack/commit/200f78f9f96a7190b21dcfeec34fb38320b3a083))
* one notification per new title and per show's episodes on a day ([6b83cba](https://github.com/MaxRzepkowski/VideTrack/commit/6b83cba19b657a201b2a00e7d92a1c3af2f1cdde))
* pick the statistics period and type from a sticky toolbar ([e8286bf](https://github.com/MaxRzepkowski/VideTrack/commit/e8286bf07fff78d3fd7d51e00084e7be39462990))
* send library changes and notifications to your webhooks ([6b17ba6](https://github.com/MaxRzepkowski/VideTrack/commit/6b17ba68b5132b24a764f7bddcc9baa1700f0a66))
* show a manga's sequels and spin-offs from MyAnimeList ([cc8e17e](https://github.com/MaxRzepkowski/VideTrack/commit/cc8e17e8aedbcc88ef56a79ec76b417264df3d02))
* show manga rankings as tiles and recommendations higher up ([82a0a17](https://github.com/MaxRzepkowski/VideTrack/commit/82a0a176284a8b569a8aabd5b34342957abe697f))
* show the cast, directors, writers and authors behind your titles ([bf65e99](https://github.com/MaxRzepkowski/VideTrack/commit/bf65e9908e585d8716c6cf982afaed35cbf45ece))
* show title origins and their networks or studios in statistics ([2d26ac9](https://github.com/MaxRzepkowski/VideTrack/commit/2d26ac9180b6d3dbeff09fc48efa5651604ed2ca))
* show your longest streak, busiest day and biggest marathon ([94512f2](https://github.com/MaxRzepkowski/VideTrack/commit/94512f2380171ce6d04ac140f36a9cd41c2a115c))
* statistics page with time spent, activity, genres and reading time ([f0d1ee0](https://github.com/MaxRzepkowski/VideTrack/commit/f0d1ee0c35b18eecf6669b69a0d2c1e3a0d6887a))


### Bug Fixes

* call a season premiere the episode TMDB marks as opening it ([0e86780](https://github.com/MaxRzepkowski/VideTrack/commit/0e867804d4f5c81f9ed3de1ebb5b748f40ef9947))
* hide only adult manga, not suggestive ones, from search ([dbbf471](https://github.com/MaxRzepkowski/VideTrack/commit/dbbf47173cd02aae388aec6a80a392a555eb59bd))
* keep a separator from starting a wrapped line of facts ([b4077ee](https://github.com/MaxRzepkowski/VideTrack/commit/b4077ee833d3184780f046504f1096541f9ed692))
* show a manga start known only to the year as the year ([8d1aa61](https://github.com/MaxRzepkowski/VideTrack/commit/8d1aa61620ce779b7973a8621b5942fb1d921c00))
* show manga under their English title ([#46](https://github.com/MaxRzepkowski/VideTrack/issues/46)) ([e48c356](https://github.com/MaxRzepkowski/VideTrack/commit/e48c356ecd3d9a435953b3c963c8da6090f324ba))
* show the header menu in a drawer on tablets ([d8b079c](https://github.com/MaxRzepkowski/VideTrack/commit/d8b079c76d19778deb1bf9f310ce31be292289ef))
* show the running version and a source link in the footer ([#51](https://github.com/MaxRzepkowski/VideTrack/issues/51)) ([2a15919](https://github.com/MaxRzepkowski/VideTrack/commit/2a15919cd26760e0cbb0ff93d6f927f24939a703))
* stop sending the visitor's browser header to providers ([#49](https://github.com/MaxRzepkowski/VideTrack/issues/49)) ([fac48c5](https://github.com/MaxRzepkowski/VideTrack/commit/fac48c51644404fb4a59f3dbe4ade2f07abc36ca))


### Performance

* keep worked-out statistics until your library changes ([0c8c9c3](https://github.com/MaxRzepkowski/VideTrack/commit/0c8c9c39346d8e705dde0ddfbffa815afb4b6ef5))

## 0.1.0 (2026-11-15)

The first public release of VideTrack, a self-hosted tracker for movies, TV shows, games, books, manga and comics.

### What it does

- Keeps movies, shows, games, books, manga and comics in one library, with the next episode of every show you watch and whatever you are in the middle of.
- Follows upcoming releases in a calendar and countdowns, which you can also subscribe to from your own calendar app.
- Tells you about new releases of the titles, people and studios you follow, in the app or on Discord.
- Ratings, favorites, a backlog and your own lists, with a full history including rewatches.
- Suggestions based on what you finished, on a page of their own.
- Imports from Trakt, Simkl, IMDb, Goodreads, MyAnimeList, AniList, Kitsu, Steam and more; logs what you watch from Plex, Jellyfin, Emby or Tautulli; exports a full backup, CSV, or files for IMDb and Letterboxd.
- An account for everyone at home, in English or Polish, and an API for your own scripts.

### Install

One Docker container with one SQLite file: follow [Install](https://github.com/MaxRzepkowski/VideTrack#install) in the README. Images for amd64 and arm64: `ghcr.io/maxrzepkowski/videtrack:0.1.0` (also `0.1` and `latest`).
