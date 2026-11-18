/**
 * The world's outlines `WorldMap` draws. The package ships bare TopoJSON;
 * read as plain JSON, its `type` would widen to any string and no longer be
 * a topology.
 */
declare module 'world-atlas/countries-110m.json' {
    import type { GeometryCollection, Topology } from 'topojson-specification';

    const atlas: Topology<{ countries: GeometryCollection; land: GeometryCollection }>;
    export default atlas;
}
