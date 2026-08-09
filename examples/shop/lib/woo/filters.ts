import type { Filter } from "@/lib/types";

export interface ActiveFilterBadge {
  filterLabel: string;
  label: string;
  paramKey: string;
  value: string;
}

export function getActiveFilterBadges(
  filters: Filter[],
  activeFilters: Record<string, string | string[] | undefined>,
): ActiveFilterBadge[] {
  return filters.flatMap((filter) => {
    const current = activeFilters[filter.paramKey];
    const values = Array.isArray(current) ? current : current ? [current] : [];
    return values.flatMap((value) => {
      const option = filter.values.find((candidate) => candidate.value === value);
      return option
        ? [{ filterLabel: filter.label, label: option.label, paramKey: filter.paramKey, value }]
        : [];
    });
  });
}
