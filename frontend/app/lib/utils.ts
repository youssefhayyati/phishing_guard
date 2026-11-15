import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function valueUpdater<T extends (...args: any[]) => any>(
  updaterOrValue: T | ReturnType<T>,
  ref: { value: unknown },
) {
  ref.value = typeof updaterOrValue === 'function'
    ? (updaterOrValue as any)(ref.value)
    : updaterOrValue
}
