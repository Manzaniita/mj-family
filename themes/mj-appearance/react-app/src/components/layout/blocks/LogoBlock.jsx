import { Logo } from '@/components/ui/Logo';

export function LogoBlock({ maxWidth, showSiteName = true, alignment }) {
  const alignClass = {
    center: 'justify-center text-center',
    right: 'justify-end text-right',
    left: 'justify-start text-left',
  }[alignment] || '';

  return (
    <div
      className={`mj-block-logo flex items-center min-w-0 ${alignClass}`}
      style={maxWidth ? { maxWidth } : undefined}
    >
      <Logo showSiteName={showSiteName} />
    </div>
  );
}
