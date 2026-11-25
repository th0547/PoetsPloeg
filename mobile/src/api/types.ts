// Vormen van de antwoorden van /api/v1 (zie docs/api.md in de repository).

export type Level = 'danger' | 'warning' | 'info';

export interface Warning {
  type?: string;
  level: Level;
  message: string;
}

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'planner' | 'teamleider';
  role_label: string;
  can: { view_audit_log: boolean; manage_policy: boolean };
}

export type LoginResponse =
  | { two_factor: true; challenge: string }
  | TokenResponse;

export interface TokenResponse {
  token: string;
  expires_at: string | null;
  user: User;
  recovery_codes_left: number | null;
}

export interface EmployeeRef {
  id: number;
  name: string;
  color: string | null;
  ink: string;
  initials: string;
}

export interface BuildingRef {
  id: number;
  name: string;
  short_name: string;
  color: string | null;
  ink: string;
}

export interface ShiftSummary {
  id: number;
  title: string;
  date: string;
  starts_at: string;
  ends_at: string;
  start_time: string;
  end_time: string;
  required_staff: number;
  open_slots: number;
  cancelled: boolean;
  state: 'done' | 'now' | 'later';
  building?: BuildingRef;
  assignments?: { employee: EmployeeRef; source: string; warnings: Warning[] }[];
}

export interface OpenShiftRow {
  id: number;
  title: string;
  date: string;
  start_time: string;
  end_time: string;
  open_slots: number;
  building: BuildingRef;
}

export interface Absence {
  id: number;
  employee?: EmployeeRef;
  type: string;
  type_label: string;
  status: 'approved' | 'pending' | 'rejected';
  status_label: string;
  title: string;
  starts_at: string;
  ends_at: string;
  period: string;
  note: string | null;
  shift_count?: number;
}

export interface Today {
  date: string;
  is_today: boolean;
  holiday: string | null;
  counts: {
    shifts: number;
    working_now: number;
    warnings: number;
    open_today: number;
    open_slots_14_days: number;
    absent: number;
    pending_requests: number;
  };
  current: ShiftSummary[];
  later: ShiftSummary[];
  finished: ShiftSummary[];
  open_shifts: OpenShiftRow[];
  absent: Absence[];
  pending_requests: Absence[];
}

export interface ShiftDetail {
  id: number;
  title: string;
  date: string;
  date_label: string;
  start_time: string;
  end_time: string;
  duration: string;
  required_staff: number;
  open_slots: number;
  extra_staff: number;
  notes: string | null;
  cancelled: boolean;
  state: 'done' | 'now' | 'later';
  fixed_missing: { employee_id: number; name: string; reason: string }[];
  template: { id: number; recurrence: string; url: string } | null;
  skills: { id: number; name: string }[];
  building: {
    id: number;
    name: string;
    color: string | null;
    address: string;
    contact: string | null;
    access: string | null;
    url: string;
  };
  assignments: {
    employee_id: number;
    name: string;
    phone: string | null;
    color: string | null;
    ink: string;
    initials: string;
    source: string;
    warnings: Warning[];
  }[];
}

export interface Reason {
  ok: boolean | null;
  text: string;
  soft?: boolean;
}

export interface Candidate {
  id: number;
  name: string;
  color: string | null;
  phone: string | null;
  ink: string;
  initials: string;
  week_hours: number;
  reasons: Reason[];
  warnings: Warning[];
  day_shifts: { start: string; end: string; title?: string; building?: string }[];
  score?: number;
  fit: 'best' | 'good' | 'caution' | 'excluded';
}

export interface Candidates {
  candidates: Candidate[];
  excluded: Candidate[];
}

export type AssignResponse =
  | { needs_confirmation: true; warnings: Warning[] }
  | (ShiftDetail & { approved_requests: string[] });

export interface PlanningShift {
  id: number;
  building_id: number;
  title: string;
  day: string;
  start: string;
  end: string;
  required: number;
  open: number;
  level: Level | null;
  assignments: { employee_id: number; source: string; level: Level | null; warnings: Warning[] }[];
}

export interface Planning {
  from: string;
  to: string;
  today: string;
  days: { date: string; holiday: string | null }[];
  employees: { id: number; name: string; color: string | null; initials: string; active: boolean }[];
  buildings: { id: number; name: string; short: string; color: string | null; city: string | null }[];
  shifts: PlanningShift[];
  absences: {
    id: number;
    employee_id: number;
    label: string;
    pending: boolean;
    from_day: string;
    to_day: string;
  }[];
}
