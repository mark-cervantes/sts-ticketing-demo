/**
 * TypeScript interfaces mirroring IssueResource.php and related API shapes.
 * Single source of truth for all issue-related frontend types.
 */

/** DB-backed status object from GET /api/statuses and IssueResource status_obj. */
export interface IssueStatus {
  id: number
  name: string
  slug: string
  color: string
  sort_order: number
  is_default: boolean
}

export type IssuePriority = 'low' | 'medium' | 'high' | 'critical'

export type SummaryStatus = 'pending' | 'processing' | 'ready' | 'failed'

export type IssueVisibility = 'private' | 'shared'

export type SharePermission = 'view' | 'comment' | 'edit'

export interface ShareUser {
  id: number
  name: string
  email: string
}

export interface Share {
  id: number
  permission: SharePermission
  created_at: string
  user: ShareUser
}

/** Display labels for share permissions. */
export const PERMISSION_LABELS: Record<SharePermission, string> = {
  view: 'Can view',
  comment: 'Can comment',
  edit: 'Can edit',
}

export interface IssueUser {
  id: number
  name: string
}

/** Per-emoji reaction summary from the API. */
export interface ReactionSummary {
  count: number
  /** Whether the current authenticated user reacted with this emoji. */
  reacted: boolean
}

export interface IssueComment {
  id: number
  body: string
  created_at: string
  user: IssueUser
  reactions_summary?: Record<string, ReactionSummary>
}

/** Response shape from POST /api/comments/{id}/reactions */
export interface ReactionToggleResponse {
  toggled: 'added' | 'removed'
  reactions_summary: Record<string, ReactionSummary>
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
  /** Status slug string — kept for backward compat (used in drag-drop data attrs etc.) */
  status: string
  /** Status foreign key ID */
  status_id: number
  /** Full status object from status_obj in IssueResource */
  status_obj: IssueStatus
  visibility: IssueVisibility
  summary_status: SummaryStatus
  summary: string | null
  suggested_next_action: string | null
  suggested_next_ticket: string | null
  needs_attention: boolean
  deadline_at: string | null
  user_id: number
  category_id: number
  created_at: string
  updated_at: string
  user: IssueUser
  category: IssueCategory
  comments_count?: number
  comments?: IssueComment[]
  can_comment?: boolean
  can_update?: boolean
}

/** Filter state for the Kanban board sidebar. */
export interface IssueFilters {
  /** Selected status IDs (integers). Empty means "all". */
  statuses: number[]
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

/** Column descriptor for Kanban rendering — status is now a slug string (dynamic). */
export interface KanbanColumnDef {
  status: string
  statusId: number
  label: string
  color: string
  issues: Issue[]
  loading: boolean
  hasMore: boolean
  currentPage: number
  /** Whether this column is the default status (prevents deletion). */
  isDefault: boolean
  /** Number of issues in this column (for delete-with-migration modal). */
  issueCount: number
}

/** Priority metadata for display & badge styling. */
export const PRIORITY_CONFIG: Record<IssuePriority, { label: string; order: number }> = {
  critical: { label: 'Critical', order: 0 },
  high: { label: 'High', order: 1 },
  medium: { label: 'Medium', order: 2 },
  low: { label: 'Low', order: 3 },
}
