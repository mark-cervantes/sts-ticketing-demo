/**
 * TypeScript interfaces mirroring IssueResource.php and related API shapes.
 * Single source of truth for all issue-related frontend types.
 */

export type IssueStatus = 'open' | 'in_progress' | 'resolved'

export type IssuePriority = 'low' | 'medium' | 'high' | 'critical'

export type SummaryStatus = 'pending' | 'processing' | 'ready' | 'failed'

export type IssueVisibility = 'private' | 'shared'

export interface IssueUser {
  id: number
  name: string
}

export interface IssueCategory {
  id: number
  name: string
  slug: string
}

/** Mirrors IssueResource.php toArray() — list shape (comments_count, no comments). */
export interface Issue {
  id: number
  title: string
  description: string
  priority: IssuePriority
  status: IssueStatus
  visibility: IssueVisibility
  summary_status: SummaryStatus
  summary: string | null
  suggested_next_action: string | null
  needs_attention: boolean
  deadline_at: string | null
  user_id: number
  category_id: number
  created_at: string
  updated_at: string
  user: IssueUser
  category: IssueCategory
  comments_count?: number
}

/** Filter state for the Kanban board sidebar. */
export interface IssueFilters {
  statuses: IssueStatus[]
  priorities: IssuePriority[]
  category: string | null // slug or null
}

/** Standard Laravel pagination links from API. */
export interface PaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

/** Standard Laravel paginated JSON:API envelope. */
export interface PaginatedResponse<T> {
  data: T[]
  links: PaginationLinks
  meta: {
    current_page: number
    from: number | null
    last_page: number
    path: string
    per_page: number
    to: number | null
    total: number
  }
}

/** Category shape from GET /api/categories. */
export interface Category {
  id: number
  name: string
  slug: string
}

/** Column descriptor for Kanban rendering. */
export interface KanbanColumnDef {
  status: IssueStatus
  label: string
  issues: Issue[]
  loading: boolean
  hasMore: boolean
  currentPage: number
}

/** Status metadata for display. */
export const STATUS_CONFIG: Record<IssueStatus, { label: string; order: number }> = {
  open: { label: 'Open', order: 0 },
  in_progress: { label: 'In Progress', order: 1 },
  resolved: { label: 'Resolved', order: 2 },
}

/** Priority metadata for display & badge styling. */
export const PRIORITY_CONFIG: Record<IssuePriority, { label: string; order: number }> = {
  critical: { label: 'Critical', order: 0 },
  high: { label: 'High', order: 1 },
  medium: { label: 'Medium', order: 2 },
  low: { label: 'Low', order: 3 },
}
