import { cva, type VariantProps } from 'class-variance-authority';

export { default as Button } from './Button.vue';

export const buttonVariants = cva(
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl text-base font-semibold transition-colors disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default: 'bg-brand text-on-accent hover:bg-brand/90',
                destructive: 'border border-danger-line bg-transparent text-danger hover:bg-danger-surface',
                outline: 'border border-line bg-surface text-ink hover:border-line-strong',
                secondary: 'border border-line bg-surface-raised text-ink hover:border-line-strong',
                ghost: 'text-ink-secondary hover:bg-surface-raised hover:text-ink',
                link: 'text-brand-soft underline-offset-4 hover:underline',
            },
            size: {
                default: 'h-11 px-5 py-3',
                sm: 'h-9 px-4 text-sm',
                lg: 'h-12 px-8',
                icon: 'size-11',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

export type ButtonVariants = VariantProps<typeof buttonVariants>;
