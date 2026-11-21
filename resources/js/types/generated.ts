export type ActivityBucket = 'day' | 'week' | 'month' | 'year';
export type ActivityEntry = {
    readonly id: number | null;
    readonly at: string | null;
    readonly media: MediaSummary;
    readonly day: string | null;
    readonly rating: number | null;
    readonly outcome: PlayOutcome | null;
    readonly completions: number | null;
};
export type ActivityFilters = {
    readonly sort: ActivitySort;
    readonly dir: SortDirection;
    readonly q: string | null;
    readonly group: ActivityGrouping;
    readonly genre: string | null;
    readonly type: string | null;
    readonly status: PlayOutcome | null;
    readonly from: string | null;
    readonly to: string | null;
    readonly hideRated: boolean;
    readonly hideListed: boolean;
    readonly byCompletions: boolean;
};
export type ActivityGrouping = 'grid' | 'day';
export type ActivitySort = 'activity' | 'added' | 'my_rating' | 'owner_rating' | 'community' | 'title' | 'release' | 'runtime' | 'completions';
export type AgeRatingData = {
    readonly board: string;
    readonly rating: string;
    readonly descriptors: string[];
    readonly synopsis: string | null;
};
export type ApiTokenItem = {
    readonly id: number;
    readonly name: string;
    readonly abilities: TokenAbility[];
    readonly lastUsedAt: string | null;
    readonly createdAt: string | null;
    readonly expiresAt: string | null;
};
export type BellEntryKind = 'person' | 'company' | 'collection' | 'manual' | 'via' | 'muted';
export type BookKind =
    'novella' | 'short_story' | 'graphic_novel' | 'fan_fiction' | 'research' | 'poetry' | 'anthology' | 'web_novel' | 'light_novel';
export type BookRole =
    'translator' | 'illustrator' | 'narrator' | 'cover_artist' | 'editor' | 'foreword' | 'afterword' | 'adaptation' | 'original_author';
export type Bookend = {
    readonly media: MediaSummary;
    readonly at: string;
};
export type BusiestDay = {
    readonly day: DayCount;
    readonly week: DayCount[];
};
export type CalendarDay = {
    readonly date: string;
    readonly entries: CalendarEntry[];
    readonly events: CalendarEvent[];
};
export type CalendarEntry = {
    readonly media: MediaSummary;
    readonly showTitle: string | null;
};
export type CalendarEvent = {
    readonly event: EventEntry;
    readonly mediumType: string;
};
export type CalendarMonth = {
    readonly month: string;
    readonly start: string;
    readonly end: string;
    readonly days: CalendarDay[];
};
export type CatalogLink = {
    readonly ref: string;
    readonly name: string;
};
export type CatalogueShelf = 'game' | 'dlc' | 'other' | 'movie' | 'show';
export type CertificationData = {
    readonly region: string;
    readonly code: string;
    readonly meaning: string | null;
};
export type ChannelData = {
    readonly id: number;
    readonly driver: string;
    readonly kind: string;
    readonly kindLabel: string | null;
    readonly label: string;
    readonly mode: ChannelMode;
    readonly enabled: boolean;
    readonly values: Record<string, string>;
    readonly lastFailure: string | null;
};
export type ChannelKindData = {
    readonly driver: string;
    readonly code: string;
    readonly label: string;
    readonly fields: CredentialField[];
    readonly setupUrl: string | null;
    readonly popular: boolean;
};
export type ChannelMode = 'digest' | 'instant';
export type ChannelsPage = {
    readonly channels: ChannelData[];
    readonly kinds: ChannelKindData[];
    readonly labelMax: number;
    readonly defaultMode: ChannelMode;
    readonly digestHour: number;
    readonly push: PushDevicesData | null;
};
export type ClosesRun = 'series' | 'season';
export type CollectionBannerData = {
    readonly provider: string;
    readonly ref: string;
    readonly name: string;
    readonly backdropPath: string | null;
    readonly partCount: number | null;
    readonly partType: string;
};
export type CollectionCredit = {
    readonly personRef: string;
    readonly provider: string;
    readonly name: string;
    readonly profilePath: string | null;
    readonly department: Department;
    readonly roles: {
        readonly role: string;
    }[];
    readonly titleCount: number;
};
export type CollectionLink = {
    readonly ref: string;
    readonly name: string;
    readonly posterPath: string | null;
    readonly backdropPath: string | null;
};
export type CollectionOverview = {
    readonly titleCount: number;
    readonly releasedCount: number;
    readonly upcomingCount: number;
    readonly budget: number | null;
    readonly budgetCounted: number;
    readonly revenue: number | null;
    readonly revenueCounted: number;
    readonly totalRuntime: number | null;
    readonly studios: CreatorLink[];
    readonly productionCountries: string[];
    readonly originalLanguages: string[];
    readonly certifications: string[];
    readonly keywords: KeywordData[];
    readonly cast: CollectionCredit[];
    readonly crew: CollectionCredit[];
};
export type CollectionRelations = {
    readonly parent: CollectionLink | null;
    readonly subseries: CollectionLink[];
    readonly spinOffSeries: CollectionLink[];
};
export type CompanyCredit = {
    readonly media: MediaSummary;
    readonly role: CompanyRole;
    readonly category: CatalogueShelf;
};
export type CompanyDetails = {
    readonly ref: string;
    readonly name: string;
    readonly logoPath: string | null;
    readonly description: string | null;
    readonly founded: string | null;
    readonly country: string | null;
    readonly parentName: string | null;
    readonly parentRef: string | null;
    readonly externalLinks: Record<string, string>;
    readonly credits: CompanyCredit[];
    readonly hasMoreCredits: boolean;
};
export type CompanyFollowScope = 'developed' | 'published' | 'both';
export type CompanyRole = 'developer' | 'publisher' | 'porting' | 'supporting' | 'studio' | 'network';
export type ConnectAttemptStatus = 'pending' | 'expired' | 'failed' | 'connected' | 'cancelled';
export type ConnectDef = {
    readonly service: string;
    readonly name: string;
    readonly method: ConnectMethod;
    readonly fields: CredentialField[];
    readonly autoImportMinutes: number;
    readonly connection: ConnectionCard | null;
    readonly errorKey: string;
};
export type ConnectMethod = 'device' | 'redirect' | 'form';
export type ConnectionCard = {
    readonly id: number;
    readonly service: string;
    readonly account: string | null;
    readonly status: ConnectionStatus;
    readonly autoImport: boolean;
    readonly lastImportedAt: string | null;
    readonly lastError: string | null;
};
export type ConnectionStatus = 'active' | 'expired';
export type CountdownEntry = {
    readonly media: MediaSummary;
    readonly showTitle: string | null;
    readonly filterType: string;
};
export type CountdownSort = 'premiere' | 'title';
export type CraftPeople = {
    readonly department: Department;
    readonly types: string[];
    readonly people: PersonTally[];
};
export type CreatorKind = 'person' | 'company' | 'network' | 'collection';
export type CreatorLink = {
    readonly name: string;
    readonly kind: CreatorKind;
    readonly ref: string | null;
    readonly provider: string | null;
    readonly logoPath: string | null;
};
export type CredentialField = {
    readonly key: string;
    readonly label: string;
    readonly type: CredentialFieldType;
    readonly help: string | null;
    readonly optional: boolean;
    readonly secret: boolean;
};
export type CredentialFieldType = 'text' | 'url' | 'password' | 'email' | 'checkbox';
export type CreditData = {
    readonly personRef: string;
    readonly name: string;
    readonly profilePath: string | null;
    readonly department: Department;
    readonly role: string | null;
    readonly order: number;
    readonly episodeCount: number | null;
    readonly roles: {
        readonly role: string | null;
        readonly episodeCount: number | null;
    }[];
    readonly provider: string;
};
export type CreditPage = {
    readonly credits: CompanyCredit[];
    readonly hasMore: boolean;
};
export type DataSourceCredit = {
    readonly name: string;
    readonly url: string;
    readonly notices: string[];
    readonly logo: string | null;
    readonly logoWidth: number | null;
    readonly logoHeight: number | null;
    readonly licenseName: string | null;
    readonly licenseUrl: string | null;
};
export type DatePrecision = 'day' | 'month' | 'quarter' | 'year';
export type DayCount = {
    readonly day: string;
    readonly count: number;
};
export type DeliveryNote =
    | 'already_played'
    | 'below_threshold'
    | 'duplicate_delivery'
    | 'invalid_payload'
    | 'kind_not_actionable'
    | 'no_external_id_match'
    | 'no_progress_unit'
    | 'not_an_event'
    | 'parse_failed'
    | 'payload_too_large'
    | 'processing_failed'
    | 'rating_not_imported'
    | 'rating_out_of_range'
    | 'test_parse_failed'
    | 'test_passed'
    | 'test_provider_unreachable'
    | 'user_mismatch';
export type DeliveryRow = {
    readonly id: number;
    readonly status: WebhookDeliveryStatus;
    readonly kind: string | null;
    readonly label: string | null;
    readonly message: DeliveryNote | null;
    readonly at: string | null;
    readonly payload: string | null;
};
export type Department =
    | 'acting'
    | 'guest_star'
    | 'directing'
    | 'writing'
    | 'author'
    | 'translation'
    | 'narration'
    | 'production'
    | 'camera'
    | 'editing'
    | 'sound'
    | 'art'
    | 'costume_make_up'
    | 'visual_effects'
    | 'lighting'
    | 'crew';
export type DiscoverFilter = {
    readonly key: string;
    readonly label: string;
    readonly kind: DiscoverFilterKind;
    readonly options: DiscoverFilterOption[];
    readonly default: string | null;
    readonly unavailable: boolean;
    readonly open: boolean;
    readonly rangeMin: number | null;
    readonly rangeMax: number | null;
};
export type DiscoverFilterKind = 'select' | 'multi_select' | 'range';
export type DiscoverFilterOption = {
    readonly value: string;
    readonly label: string;
    readonly ascending?: string;
};
export type EpisodeBadge = 'series_premiere' | 'season_premiere' | 'mid_season_finale' | 'series_finale' | 'season_finale';
export type EpisodeImage = 'backdrop' | 'still';
export type EpisodePointer = {
    readonly type: string;
    readonly ref: string;
    readonly title: string;
    readonly number: number | null;
    readonly seasonNumber: number | null;
};
export type EpisodeRating = {
    readonly episode: number;
    readonly title: string;
    readonly rating: number | null;
    readonly airDate: string | null;
};
export type EpisodeSegment = {
    readonly state: EpisodeState;
    readonly episodes: number;
};
export type EpisodeState = 'watched' | 'skipped' | 'pending';
export type EpisodeType = 'standard' | 'finale' | 'mid_season';
export type EventCountdown = {
    readonly event: EventEntry;
    readonly mediumType: string;
    readonly filterType: string;
};
export type EventEntry = {
    readonly provider: string;
    readonly ref: string;
    readonly name: string;
    readonly startsAt: string;
    readonly endsAt: string;
    readonly liveStreamUrl: string | null;
    readonly logoPath: string | null;
};
export type EventPage = {
    readonly event: EventEntry;
    readonly description: string | null;
    readonly timeZone: string | null;
    readonly hidden: boolean | null;
    readonly titles: MediaSummary[];
};
export type ExportDownload = {
    readonly code: string;
    readonly label: string;
    readonly shelf: ExportShelf;
};
export type ExportShelf = 'backup' | 'spreadsheet' | 'service';
export type FeedScope = 'all' | 'premieres';
export type FilmReleaseType = 'premiere' | 'limited' | 'theatrical' | 'digital' | 'disc' | 'tv';
export type FollowEntry = {
    readonly media: MediaSummary;
    readonly kind: BellEntryKind;
    readonly personName: string | null;
};
export type FollowMode = 'follow' | 'mute';
export type FranchiseDetails = {
    readonly ref: string;
    readonly name: string;
    readonly series: MediaSummary[];
    readonly others: MediaSummary[];
};
export type GameKind =
    | 'dlc'
    | 'expansion'
    | 'bundle'
    | 'standalone_expansion'
    | 'mod'
    | 'episode'
    | 'season'
    | 'remake'
    | 'remaster'
    | 'expanded'
    | 'port'
    | 'fork'
    | 'pack'
    | 'update';
export type GameStore =
    'steam' | 'gog' | 'epicgames' | 'playstation' | 'xbox' | 'nintendo' | 'itch' | 'appstore' | 'googleplay' | 'gamepass' | 'utomik';
export type GenreOption = {
    readonly ref: string;
    readonly name: string;
};
export type GenreStat = {
    readonly name: string;
    readonly titles: number;
    readonly amount: number;
    readonly averageRating: number | null;
    readonly rated: number;
};
export type IdentityProviderOption = {
    readonly code: string;
    readonly label: string;
    readonly glyph: string;
};
export type ImportInputKind = 'file' | 'credentials' | 'connection';
export type ImportManifest = {
    readonly valid: boolean;
    readonly error: string | null;
    readonly categories: ManifestCategory[];
    readonly lists: ManifestList[];
    readonly supportsSelection: boolean;
};
export type ImportSourceDef = {
    readonly code: string;
    readonly label: string;
    readonly description: string;
    readonly inputKind: ImportInputKind;
    readonly accept: string | null;
    readonly credentials: CredentialField[];
    readonly notes: SetupNote[];
    readonly connect: ConnectDef | null;
};
export type KeywordData = {
    readonly name: string;
    readonly ref: string | null;
};
export type LanguageSupport = {
    readonly locale: string;
    readonly audio: boolean;
    readonly subtitles: boolean;
    readonly interface: boolean;
};
export type LinkedIdentity = {
    readonly id: number;
    readonly provider: string;
    readonly email: string | null;
};
export type ListDetailsData = {
    readonly id: number;
    readonly title: string | null;
    readonly description: string | null;
    readonly systemKey: string | null;
    readonly displayTitle: string;
    readonly collection: {
        provider: string;
        ref: string;
    } | null;
    readonly isPublic: boolean;
    readonly backdropPath: string | null;
    readonly backdrops: string[];
    readonly posters: string[];
    readonly owner: {
        id: number;
        name: string;
        username: string;
        avatar: string | null;
    } | null;
    readonly isOwner: boolean;
    readonly canDelete: boolean;
    readonly followMode: FollowMode | null;
    readonly collectionRelations: CollectionRelations | null;
};
export type ListItemEntry = {
    readonly id: number;
    readonly addedAt: string | null;
    readonly genres: string[];
    readonly mediaItemId: number | null;
    readonly imdbId: string | null;
    readonly media: MediaSummary;
};
export type ListOverview = {
    readonly id: number;
    readonly title: string | null;
    readonly systemKey: string | null;
    readonly displayTitle: string;
    readonly isPublic: boolean;
    readonly itemCount: number;
    readonly createdAt: string | null;
    readonly posterPaths: {
        path: string;
        logo: boolean;
    }[];
};
export type ListStatisticsData = {
    readonly itemCount: number;
    readonly types: TypeCount[];
    readonly trackableCount: number;
    readonly completedCount: number;
    readonly inProgressCount: number;
    readonly droppedCount: number;
    readonly upcomingCount: number;
    readonly totals: ListUnitTotal[];
};
export type ListUnitTotal = {
    readonly unit: ProgressUnit;
    readonly estimated: boolean;
    readonly amount: number;
    readonly behindAmount: number;
    readonly remainingAmount: number;
    readonly itemsCounted: number;
    readonly itemsTotal: number;
};
export type MakerTally = {
    readonly creator: CreatorLink;
    readonly titles: number;
    readonly best: number[];
};
export type MangaAuthorRole = 'story' | 'art';
export type MangaFormat = 'manga' | 'manhwa' | 'manhua' | 'one_shot' | 'doujinshi' | 'oel';
export type ManifestCategory = {
    readonly key: string;
    readonly label: string;
    readonly count: number;
    readonly preselected: boolean;
};
export type ManifestList = {
    readonly id: string;
    readonly name: string;
    readonly itemCount: number;
    readonly isPublic: boolean;
};
export type Marathon = {
    readonly media: MediaSummary;
    readonly day: string;
    readonly parts: number;
    readonly first: PartNumber;
    readonly last: PartNumber;
    readonly minutes: number;
};
export type MatureContent = 'safe' | 'suggestive' | 'adult';
export type MatureContentOption = {
    readonly provider: string;
    readonly types: string[];
    readonly levels: MatureContent[];
    readonly level: MatureContent;
};
export type MediaDetails = {
    readonly type: string;
    readonly ref: string;
    readonly provider: string;
    readonly title: string;
    readonly year: number | null;
    readonly posterPath: string | null;
    readonly backdropPath: string | null;
    readonly communityRating: number | null;
    readonly releaseDate: string | null;
    readonly releaseTime: string | null;
    readonly number: number | null;
    readonly totalRuntime: number | null;
    readonly progressTotal: number | null;
    readonly tagline: string | null;
    readonly overview: string | null;
    readonly runtime: number | null;
    readonly genres: string[];
    readonly status: TitleStatus | null;
    readonly originalLanguage: string | null;
    readonly certification: string | null;
    readonly certifications: CertificationData[];
    readonly releases: ReleaseEntry[];
    readonly platformReleases: PlatformRelease[];
    readonly keywords: KeywordData[];
    readonly externalLinks: Record<string, string>;
    readonly extra: {
        readonly seasonNumber?: number;
        readonly runtime?: number;
        readonly overview?: string;
        readonly releaseKind?: ReleaseKind;
        readonly platform?: string;
        readonly releaseStatus?: string;
        readonly releasePrecision?: DatePrecision;
        readonly episodeType?: EpisodeType;
        readonly episodeBadge?: EpisodeBadge;
        readonly kind?: CreatorKind;
        readonly makes?: string;
        readonly authors?: string[];
        readonly showTitle?: string;
        readonly showRef?: string;
        readonly showPoster?: string | null;
        readonly showBackdrop?: string | null;
        readonly coverColor?: string | null;
        readonly followedVia?: string;
        readonly releaseNote?: string;
        readonly episodeCount?: number | null;
        readonly gameType?: GameKind;
        readonly relatedTo?: string;
        readonly bookKind?: BookKind;
        readonly awaitedBy?: number;
        readonly knownForDepartment?: string | null;
        readonly knownFor?: string[];
        readonly itemCount?: number;
        readonly [key: string]: unknown;
    } & {
        readonly wikidataId?: string;
        readonly collection?: CollectionLink;
        readonly lastEpisodeToAir?: MediaSummary | null;
        readonly nextEpisodeToAir?: MediaSummary | null;
        readonly [key: string]: unknown;
    };
    readonly children: MediaSummary[];
    readonly cast: CreditData[];
    readonly crew: CreditData[];
    readonly videos: VideoData[];
    readonly backdrops: string[];
    readonly posters: string[];
    readonly logos: string[];
    readonly related: MediaSummary[];
    readonly similar: MediaSummary[];
    readonly rankings: RankingPosition[];
};
export type MediaEventType =
    | 'episode_aired'
    | 'season_premiere'
    | 'premiere'
    | 'home_release'
    | 'date_changed'
    | 'show_status'
    | 'season_announced'
    | 'related_announced'
    | 'person_new_title'
    | 'company_new_title'
    | 'collection_new_item'
    | 'titles_removed';
export type MediaSummary = {
    readonly type: string;
    readonly ref: string;
    readonly provider: string;
    readonly title: string;
    readonly year: number | null;
    readonly posterPath: string | null;
    readonly backdropPath: string | null;
    readonly communityRating: number | null;
    readonly releaseDate: string | null;
    readonly releaseTime: string | null;
    readonly number: number | null;
    readonly totalRuntime: number | null;
    readonly progressTotal: number | null;
    readonly extra: {
        readonly seasonNumber?: number;
        readonly runtime?: number;
        readonly overview?: string;
        readonly releaseKind?: ReleaseKind;
        readonly platform?: string;
        readonly releaseStatus?: string;
        readonly releasePrecision?: DatePrecision;
        readonly episodeType?: EpisodeType;
        readonly episodeBadge?: EpisodeBadge;
        readonly kind?: CreatorKind;
        readonly makes?: string;
        readonly authors?: string[];
        readonly showTitle?: string;
        readonly showRef?: string;
        readonly showPoster?: string | null;
        readonly showBackdrop?: string | null;
        readonly coverColor?: string | null;
        readonly followedVia?: string;
        readonly releaseNote?: string;
        readonly episodeCount?: number | null;
        readonly gameType?: GameKind;
        readonly relatedTo?: string;
        readonly bookKind?: BookKind;
        readonly awaitedBy?: number;
        readonly knownForDepartment?: string | null;
        readonly knownFor?: string[];
        readonly itemCount?: number;
        readonly [key: string]: unknown;
    };
};
export type MediaUserStatus = 'on_hold' | 'in_progress';
export type MultiplayerMode = 'online_coop' | 'local_coop' | 'lan_coop' | 'campaign_coop' | 'drop_in' | 'split_screen' | 'online' | 'local';
export type MultiplayerSupport = {
    readonly mode: MultiplayerMode;
    readonly players: number | null;
};
export type NotificationEntry = {
    readonly id: string;
    readonly readAt: string | null;
    readonly createdAt: string | null;
    readonly event: MediaEventType | null;
    readonly title: string;
    readonly detail: string[];
    readonly href: string;
    readonly imagePath: string | null;
    readonly mediaTitle: string;
    readonly data: Record<string | number, unknown>;
};
export type NotificationFeed = {
    readonly unread: number;
    readonly recent: NotificationEntry[];
};
export type OpenedSubject = {
    readonly key: string;
    readonly title: string;
    readonly caption: string | null;
    readonly href: string | null;
};
export type OriginTally = {
    readonly code: string;
    readonly titles: number;
};
export type OutgoingWebhookData = {
    readonly id: number;
    readonly label: string;
    readonly host: string;
    readonly events: WebhookEventGroup[];
    readonly enabled: boolean;
    readonly lastFailure: string | null;
    readonly newSecret: string;
};
export type OutgoingWebhooksPage = {
    readonly webhooks: OutgoingWebhookData[];
    readonly groups: WebhookGroupOption[];
    readonly newSecret: string;
    readonly labelMax: number;
    readonly urlMax: number;
    readonly limit: number;
};
export type PageInfo = {
    readonly currentPage: number;
    readonly totalPages: number;
    readonly total: number;
};
export type PartNumber = {
    readonly season: number | null;
    readonly number: number | null;
};
export type PeriodKind = 'year' | 'all' | 'range';
export type PeriodSpan = {
    readonly kind: PeriodKind;
    readonly year: number | null;
    readonly from: string | null;
    readonly to: string | null;
    readonly days: number;
};
export type PeriodSummary = {
    readonly time: TypeTally;
    readonly readingMinutes: number;
    readonly activeDays: number;
    readonly ratings: number;
    readonly averageRating: number | null;
    readonly ratingCounts: number[];
    readonly communityDelta: number | null;
};
export type PeriodTitles = {
    readonly titles: TypeTally;
    readonly completed: TypeTally;
    readonly inProgress: TypeTally;
    readonly onHold: TypeTally;
    readonly dropped: TypeTally;
};
export type PersonCredit = {
    readonly media: MediaSummary;
    readonly department: Department;
    readonly role: string | null;
    readonly popularity: number;
};
export type PersonDetails = {
    readonly ref: string;
    readonly name: string;
    readonly profilePath: string | null;
    readonly biography: string | null;
    readonly knownForDepartment: Department | null;
    readonly birthday: string | null;
    readonly deathday: string | null;
    readonly placeOfBirth: string | null;
    readonly origin: string | null;
    readonly alsoKnownAs: string[];
    readonly photos: string[];
    readonly externalLinks: Record<string, string>;
    readonly credits: PersonCredit[];
};
export type PersonFollowScope = 'known_for' | 'all';
export type PersonTally = {
    readonly name: string;
    readonly provider: string | null;
    readonly ref: string | null;
    readonly profilePath: string | null;
    readonly titles: number;
};
export type PlatformDetails = {
    readonly ref: string;
    readonly name: string;
    readonly abbreviation: string | null;
    readonly logoPath: string | null;
    readonly kind: string | null;
    readonly family: string | null;
    readonly generation: number | null;
    readonly summary: string | null;
    readonly upcoming: MediaSummary[];
    readonly topRated: MediaSummary[];
};
export type PlatformRelease = {
    readonly date: string;
    readonly platform: string | null;
    readonly status: string | null;
    readonly region: ReleaseRegion | null;
    readonly precision: DatePrecision;
};
export type PlayEntry = {
    readonly id: number;
    readonly watchedAt: string;
    readonly outcome: PlayOutcome;
    readonly amount: number | null;
    readonly cycle: number;
    readonly source: string | null;
};
export type PlayOutcome = 'completed' | 'dropped';
export type PlayingEntry = {
    readonly media: MediaSummary;
    readonly progress: number | null;
    readonly remainingShare: number | null;
    readonly at: string | null;
};
export type PositionStatus = 'on_hold' | 'in_progress' | 'dropped';
export type ProfileData = {
    readonly id: number;
    readonly name: string;
    readonly username: string;
    readonly bio: string | null;
    readonly avatar: string | null;
    readonly isPrivate: boolean;
    readonly memberSince: string | null;
};
export type ProgressSort = 'activity' | 'remaining' | 'title';
export type ProgressUnit = 'minutes' | 'pages' | 'chapters' | 'issues';
export type ProviderRating = {
    readonly score: number;
    readonly count: number;
};
export type PushDeviceData = {
    readonly id: number;
    readonly userAgent: string | null;
    readonly fingerprint: string;
    readonly subscribedAt: string;
    readonly stale: boolean;
};
export type PushDevicesData = {
    readonly publicKey: string;
    readonly devices: PushDeviceData[];
};
export type RankingKind = 'score' | 'popularity';
export type RankingPosition = {
    readonly kind: RankingKind;
    readonly position: number;
};
export type RatedTitle = {
    readonly media: MediaSummary;
    readonly rating: number | null;
};
export type RatingGap = {
    readonly media: MediaSummary;
    readonly rating: number;
    readonly community: number;
};
export type RatingPoint = {
    readonly type: string;
    readonly rating: number;
    readonly community: number;
    readonly titles: number;
};
export type ReleaseDateMode = 'both' | 'cinema' | 'digital' | 'none';
export type ReleaseDot = {
    readonly year: number;
    readonly type: string;
    readonly rating: number | null;
};
export type ReleaseEntry = {
    readonly region: string;
    readonly kind: FilmReleaseType;
    readonly date: string;
    readonly note: string | null;
};
export type ReleaseKind = 'premiere' | 'home' | 'both';
export type ReleaseRegion = 'europe' | 'north_america' | 'australia' | 'new_zealand' | 'japan' | 'china' | 'asia' | 'worldwide' | 'korea' | 'brazil';
export type ScheduleEntry = {
    readonly name: string;
    readonly label: string;
    readonly frequency: string;
    readonly nextRunAt: string;
    readonly lastRunAt: string | null;
    readonly lastStatus: TaskRunStatus | null;
};
export type ScrobbleSourceData = {
    readonly code: string;
    readonly name: string;
    readonly description: string;
    readonly notice: string | null;
    readonly instructions: SetupStep[];
    readonly canTest: boolean;
    readonly endpointLimit: number;
    readonly endpoints: WebhookEndpointData[];
};
export type SeasonRatings = {
    readonly season: number;
    readonly episodes: EpisodeRating[];
};
export type SetupNote = {
    readonly text: string;
    readonly url: string | null;
    readonly linkText: string | null;
};
export type SetupStep = {
    readonly title: string;
    readonly body: string;
    readonly code: string | null;
};
export type ShelfSort = 'suggested' | 'release' | 'title' | 'rating' | 'added' | 'remaining' | 'progress';
export type SortDirection = 'asc' | 'desc';
export type StatisticsActivity = {
    readonly bucket: ActivityBucket;
    readonly buckets: string[];
    readonly series: TypeSeries[];
    readonly completions: number;
    readonly watchMinutes: number;
    readonly readingMinutes: number;
    readonly days: Record<string, number> | null;
    readonly months: Record<string, number> | null;
    readonly weekdays: number[];
    readonly hours: number[];
    readonly busiestDay: DayCount | null;
    readonly firstWeekday: number;
};
export type StatisticsCreators = {
    readonly crafts: CraftPeople[];
};
export type StatisticsOrigins = {
    readonly countries: OriginTally[];
    readonly countryTypes: string[];
    readonly languages: OriginTally[];
    readonly languageTypes: string[];
    readonly makers: MakerTally[];
    readonly makerKind: CreatorKind | null;
    readonly titles: RatedTitle[];
};
export type StatisticsPage = {
    readonly period: PeriodSpan;
    readonly type: string | null;
    readonly years: number[];
    readonly types: TypeCount[];
    readonly earliest: string | null;
    readonly summary: PeriodSummary;
    readonly tiles: Record<string, WatchStats>;
    readonly titles: PeriodTitles;
    readonly first: Bookend | null;
    readonly last: Bookend | null;
};
export type StatisticsRatings = {
    readonly points: RatingPoint[];
    readonly ratedHigher: number;
    readonly ratedLower: number;
    readonly ratedSame: number;
    readonly best: RatedTitle[];
    readonly bestTied: number;
    readonly higher: RatingGap[];
    readonly lower: RatingGap[];
};
export type StatisticsRecords = {
    readonly streak: Streak | null;
    readonly busiestDay: BusiestDay | null;
    readonly marathon: Marathon | null;
    readonly firstWeekday: number;
};
export type StatisticsTaste = {
    readonly readIn: ProgressUnit | null;
    readonly readingEstimated: boolean;
    readonly top: TimeTitle[];
    readonly releases: ReleaseDot[];
    readonly genres: GenreStat[];
    readonly lengths: WholeLength[];
};
export type StoreLink = {
    readonly store: GameStore;
    readonly url: string;
    readonly subscription: boolean;
};
export type Streak = {
    readonly from: string;
    readonly to: string;
    readonly days: number;
    readonly shownFrom: string;
    readonly shownTo: string;
    readonly otherDays: string[];
};
export type SubscriptionFeed = {
    readonly url: string;
    readonly defaultScope: FeedScope;
};
export type Suggestion = {
    readonly media: MediaSummary;
    readonly reason: string[];
};
export type SuggestionOrigin = {
    readonly key: string;
    readonly label: string;
    readonly count: number;
};
export type SuggestionShelf = {
    readonly key: string;
    readonly title: string;
    readonly description: string;
    readonly total: number;
    readonly posters: string[];
};
export type SuggestionSubject = {
    readonly key: string;
    readonly title: string;
    readonly caption: string | null;
    readonly finished: number | null;
    readonly total: number | null;
    readonly posterPath: string | null;
    readonly count: number;
};
export type Surface = 'calendar' | 'countdowns' | 'notifications';
export type TaskRunItem = {
    readonly id: number;
    readonly name: string;
    readonly label: string;
    readonly status: TaskRunStatus;
    readonly summary: string | null;
    readonly errors: string[];
    readonly details: string[];
    readonly queuedAt: string;
    readonly finishedAt: string | null;
};
export type TaskRunStatus = 'pending' | 'started' | 'success' | 'failure';
export type TimeFormat = 'auto' | '12' | '24';
export type TimeTitle = {
    readonly media: MediaSummary;
    readonly amount: number;
    readonly estimatedMinutes: number | null;
    readonly parts: number | null;
};
export type TitleRelation = 'spin_off' | 'spin_off_of' | 'next' | 'previous' | 'same_universe';
export type TitleStatus =
    | 'rumored'
    | 'planned'
    | 'in_production'
    | 'post_production'
    | 'pilot'
    | 'alpha'
    | 'beta'
    | 'early_access'
    | 'not_yet_published'
    | 'released'
    | 'returning_series'
    | 'publishing'
    | 'on_hiatus'
    | 'ended'
    | 'finished'
    | 'canceled'
    | 'discontinued'
    | 'offline'
    | 'delisted';
export type TitleTie = {
    readonly relation: TitleRelation;
    readonly label: string | null;
    readonly media: MediaSummary;
};
export type TokenAbility = 'read' | 'write';
export type TrackingSource = 'on_hold' | 'dropped' | 'backlog' | 'activity' | 'followed' | 'creator';
export type TypeCount = {
    readonly type: string;
    readonly count: number;
};
export type TypeOption = {
    readonly key: string;
    readonly label: string;
    readonly description: string | null;
    readonly phrase: string;
    readonly switches: TypeOptionSwitch[];
    readonly onWhen: string[] | null;
    readonly choices: string[] | null;
    readonly ofTrackedTitles: boolean;
};
export type TypeOptionRequirement = {
    readonly preference: string;
    readonly label: string;
};
export type TypeOptionSwitch = {
    readonly surface: Surface;
    readonly preference: string;
    readonly requires: TypeOptionRequirement | null;
};
export type TypePanel = {
    readonly type: string;
    readonly options: TypeOption[];
};
export type TypeSeries = {
    readonly type: string;
    readonly counts: number[];
};
export type TypeTally = {
    readonly total: number;
    readonly byType: TypeCount[];
};
export type UpNextEntry = {
    readonly show: MediaSummary;
    readonly episode: MediaSummary;
    readonly poster: string | null;
    readonly badge: EpisodeBadge | null;
    readonly closes: ClosesRun | null;
    readonly watchedEpisodes: number;
    readonly skippedEpisodes: number;
    readonly airedEpisodes: number;
    readonly remainingEpisodes: number;
    readonly remainingMinutes: number;
    readonly watchedMinutes: number;
    readonly position: number;
    readonly segments: EpisodeSegment[];
};
export type UpNextSort = 'activity' | 'remaining' | 'released' | 'title';
export type UpNextTarget = 'episode' | 'season' | 'show';
export type UserList = {
    readonly id: number;
    readonly title: string;
    readonly systemKey: string | null;
};
export type UserMediaState = {
    readonly watched: boolean;
    readonly watchedAt: string | null;
    readonly droppedAt: string | null;
    readonly plays: number;
    readonly cycle: number;
    readonly completedCycles: number;
    readonly rating: number | null;
    readonly favorite: boolean;
    readonly listIds: number[];
    readonly follow: FollowMode | null;
    readonly followVia: string | null;
    readonly followSource: TrackingSource | null;
    readonly status: PositionStatus | null;
    readonly rootStatus: PositionStatus | null;
    readonly statusInherited: boolean;
    readonly progress: number | null;
    readonly playedAmount: number | null;
    readonly droppedAmount: number | null;
    readonly latestDropped: boolean;
    readonly watchedEpisodes: number | null;
    readonly airedEpisodes: number | null;
    readonly remainingEpisodes: number | null;
    readonly skippedEpisodes: number | null;
    readonly remainingMinutes: number | null;
    readonly watchedMinutes: number | null;
    readonly position: number | null;
    readonly nextEpisode: EpisodePointer | null;
    readonly inProgress: boolean;
    readonly progression: boolean;
};
export type VideoData = {
    readonly key: string;
    readonly site: VideoSite;
    readonly type: VideoKind;
    readonly name: string;
    readonly official: boolean;
    readonly publishedAt: string | null;
};
export type VideoKind = 'Trailer' | 'Teaser' | 'Clip' | 'Featurette' | 'Behind the Scenes' | 'Bloopers';
export type VideoSite = 'YouTube' | 'Vimeo';
export type WatchAvailability = {
    readonly region: string;
    readonly link: string | null;
    readonly stream: WatchProviderData[];
    readonly free: WatchProviderData[];
    readonly rent: WatchProviderData[];
    readonly buy: WatchProviderData[];
};
export type WatchCycleSummary = {
    readonly number: number;
    readonly startedAt: string | null;
    readonly watchedEpisodes: number;
    readonly airedEpisodes: number;
    readonly plays: number;
    readonly completed: boolean;
    readonly firstPlayAt: string | null;
    readonly lastPlayAt: string | null;
    readonly isActive: boolean;
    readonly canCancel: boolean;
};
export type WatchProviderData = {
    readonly name: string;
    readonly logoPath: string | null;
};
export type WatchStats = {
    readonly minutes: number;
    readonly watched: number;
    readonly plays: number;
    readonly titles: number;
    readonly estimatedMinutes: number | null;
};
export type WebPushMessage = {
    readonly title: string;
    readonly body: string | null;
    readonly url: string;
    readonly icon: string;
    readonly timestamp: number;
};
export type WebhookDeliveryStatus = 'received' | 'accepted' | 'ignored' | 'unmatched' | 'error' | 'test';
export type WebhookEndpointData = {
    readonly id: number;
    readonly label: string | null;
    readonly enabled: boolean;
    readonly serverUsername: string | null;
    readonly url: string;
    readonly loopback: boolean;
    readonly lastReceivedAt: string | null;
    readonly lastError: string | null;
    readonly deliveries: DeliveryRow[];
};
export type WebhookEventGroup = 'activity' | 'ratings' | 'favorites' | 'status' | 'lists' | 'notifications';
export type WebhookGroupOption = {
    readonly code: WebhookEventGroup;
    readonly label: string;
    readonly description: string;
};
export type WeekStart = 'auto' | 'monday' | 'sunday';
export type WholeLength = {
    readonly type: string;
    readonly titles: number;
    readonly total: number;
    readonly average: number;
    readonly longest: MediaSummary;
    readonly longestRuntime: number;
};
