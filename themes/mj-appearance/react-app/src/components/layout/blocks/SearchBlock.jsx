import { SearchBar } from '@/components/layout/SearchBar';

export function SearchBlock({ maxWidth = '360px' }) {
  return (
    <div className="mj-block-search w-full flex items-center" style={{ maxWidth }}>
      <SearchBar />
    </div>
  );
}
