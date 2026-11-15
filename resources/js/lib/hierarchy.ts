/**
 * A position's place in the TV hierarchy, read from its ref: a season is
 * "show:n", an episode "show:s:e". The route of every level is built from
 * these parts, so the shape is read here and nowhere else.
 */
export interface HierarchyPlace {
    show: string;
    season: string | null;
    episode: string | null;
}

export function placeOf(ref: string): HierarchyPlace {
    const [show = ref, season = null, episode = null] = ref.split(':');

    return { show, season, episode };
}

/** The ref of the show a season or an episode belongs to (a show's own ref is itself). */
export function showRefOf(ref: string): string {
    return placeOf(ref).show;
}
