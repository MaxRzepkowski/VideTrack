import { trans } from 'laravel-vue-i18n';

/** The part a person had in a title — the backend's Department codes. */
import type { Department } from '@/types/generated';

export type { Department };

// Thunks, so every `trans()` stays a literal call the key checks can see.
const LABELS: Record<Department, () => string> = {
    acting: () => trans('department.acting'),
    guest_star: () => trans('department.guest_star'),
    directing: () => trans('department.directing'),
    writing: () => trans('department.writing'),
    author: () => trans('department.author'),
    translation: () => trans('department.translation'),
    narration: () => trans('department.narration'),
    production: () => trans('department.production'),
    camera: () => trans('department.camera'),
    editing: () => trans('department.editing'),
    sound: () => trans('department.sound'),
    art: () => trans('department.art'),
    costume_make_up: () => trans('department.costume_make_up'),
    visual_effects: () => trans('department.visual_effects'),
    lighting: () => trans('department.lighting'),
    crew: () => trans('department.crew'),
};

/** The department in the viewer's language. */
export function departmentLabel(department: Department): string {
    return LABELS[department]();
}

/** A summary's `extra.knownForDepartment`, which arrives untyped. */
export function isDepartment(value: unknown): value is Department {
    return typeof value === 'string' && value in LABELS;
}
