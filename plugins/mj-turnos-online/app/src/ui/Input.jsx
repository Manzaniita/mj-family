import { forwardRef } from 'react';
import { cn } from '@/lib/format';
import { useUid } from '@/lib/a11y';
import { Field } from './Field';
import { controlBase, controlSizes } from './styles';

/**
 * Campo de texto (text, tel, email, search, number, password…).
 * @param {{ label?, help?, error?, required?, id?, size?: 'sm'|'md'|'lg', icon?: Component (a la izquierda),
 *   prefix?: ReactNode, suffix?: ReactNode, className? (envoltorio), inputClassName?, ...input }} props
 */
export const Input = forwardRef(function Input(
  { label, help, error, required, id, size = 'md', icon: Icon, prefix, suffix, className, inputClassName, hint, ...rest },
  ref
) {
  const uid = useUid('mjto-in');
  const inputId = id || uid;
  const padLeft = Icon ? 'pl-10' : prefix ? 'pl-9' : '';
  const padRight = suffix ? 'pr-10' : '';
  return (
    <Field label={label} help={help} error={error} required={required} htmlFor={inputId} hint={hint} className={className}>
      <div className="relative flex items-center">
        {Icon && (
          <Icon size={18} className="pointer-events-none absolute left-3.5 text-ink-faint" aria-hidden="true" />
        )}
        {!Icon && prefix && (
          <span className="pointer-events-none absolute left-3.5 text-sm font-medium text-ink-faint">{prefix}</span>
        )}
        <input
          ref={ref}
          id={inputId}
          aria-invalid={error ? 'true' : undefined}
          aria-required={required || undefined}
          className={cn(controlBase, controlSizes[size] || controlSizes.md, padLeft, padRight, inputClassName)}
          {...rest}
        />
        {suffix && <span className="absolute right-3 flex items-center text-sm text-ink-faint">{suffix}</span>}
      </div>
    </Field>
  );
});
