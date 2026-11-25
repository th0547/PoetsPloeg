import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from './client';
import type {
  Absence,
  AssignResponse,
  Candidates,
  EmployeeRef,
  Planning,
  ShiftDetail,
  ShiftSummary,
  Today,
} from './types';

export const useToday = (date: string) =>
  useQuery({ queryKey: ['today', date], queryFn: () => api<Today>('today', { query: { date } }) });

export const usePlanning = (from: string, to: string) =>
  useQuery({ queryKey: ['planning', from, to], queryFn: () => api<Planning>('planning', { query: { from, to } }) });

export const useShift = (id: number) =>
  useQuery({ queryKey: ['shift', id], queryFn: () => api<ShiftDetail>(`shifts/${id}`) });

export const useCandidates = (id: number, enabled = true) =>
  useQuery({ queryKey: ['candidates', id], queryFn: () => api<Candidates>(`shifts/${id}/candidates`), enabled });

export interface OpenShifts {
  shift_count: number;
  slot_count: number;
  shifts: (ShiftSummary & { fixed_missing: { employee_id: number; name: string; reason: string }[] })[];
}

export const useOpenShifts = (weeks: number) =>
  useQuery({ queryKey: ['open-shifts', weeks], queryFn: () => api<OpenShifts>('open-shifts', { query: { weeks } }) });

export const useAbsences = (filters: { status?: string; period?: string }) =>
  useQuery({
    queryKey: ['absences', filters],
    queryFn: () => api<{ data: Absence[]; meta: { total: number } }>('absences', { query: filters }),
  });

export interface AbsenceDetail {
  absence: Absence;
  linked: boolean;
  shifts: { state: 'todo' | 'open' | 'done'; shift: ShiftSummary }[];
}

export const useAbsence = (id: number) =>
  useQuery({ queryKey: ['absence', id], queryFn: () => api<AbsenceDetail>(`absences/${id}`) });

export interface LeaveType {
  key: string;
  name: string;
  group: string | null;
  can_be_request: boolean;
}

export const useLeaveTypes = () =>
  useQuery({
    queryKey: ['leave-types'],
    queryFn: () => api<{ leave_types: LeaveType[] }>('leave-types'),
    staleTime: 60 * 60 * 1000,
  });

export const useEmployees = () =>
  useQuery({
    queryKey: ['employees'],
    queryFn: () => api<{ data: EmployeeRef[] }>('employees'),
    staleTime: 10 * 60 * 1000,
  });

/** Na een wijziging in de bezetting: alles wat ervan afhangt opnieuw ophalen. */
function useRefreshPlanning() {
  const client = useQueryClient();
  return () =>
    Promise.all(
      ['today', 'planning', 'shift', 'candidates', 'open-shifts', 'absences', 'absence'].map((key) =>
        client.invalidateQueries({ queryKey: [key] }),
      ),
    );
}

export function useAssign(shiftId: number) {
  const refresh = useRefreshPlanning();
  return useMutation({
    mutationFn: (input: { employee_id: number; replace_employee_id?: number; confirm?: boolean }) =>
      api<AssignResponse>(`shifts/${shiftId}/assignments`, { method: 'POST', body: input }),
    onSuccess: (data) => {
      if (!('needs_confirmation' in data)) void refresh();
    },
  });
}

export function useUnassign(shiftId: number) {
  const refresh = useRefreshPlanning();
  return useMutation({
    mutationFn: (employeeId: number) => api<ShiftDetail>(`shifts/${shiftId}/assignments/${employeeId}`, { method: 'DELETE' }),
    onSuccess: () => void refresh(),
  });
}

export function useCancelShift(shiftId: number) {
  const refresh = useRefreshPlanning();
  return useMutation({
    mutationFn: () => api<{ ok: boolean }>(`shifts/${shiftId}`, { method: 'DELETE' }),
    onSuccess: () => void refresh(),
  });
}

export interface ShiftUpdate {
  title: string;
  date: string;
  start_time: string;
  end_time: string;
  required_staff: number;
  notes: string | null;
  skills: number[];
}

export function useUpdateShift(shiftId: number) {
  const refresh = useRefreshPlanning();
  return useMutation({
    mutationFn: (input: ShiftUpdate) => api<ShiftDetail>(`shifts/${shiftId}`, { method: 'PATCH', body: input }),
    onSuccess: () => void refresh(),
  });
}

export interface AbsenceInput {
  employee_id: number;
  type: string;
  as_request: boolean;
  days: { date: string; start_time?: string; end_time?: string }[];
  note?: string;
}

export function useRegisterAbsence() {
  const refresh = useRefreshPlanning();
  return useMutation({
    mutationFn: (input: AbsenceInput) =>
      api<{ message: string; absences: Absence[] }>('absences', { method: 'POST', body: input }),
    onSuccess: () => void refresh(),
  });
}

export function useRejectAbsence(id: number) {
  const refresh = useRefreshPlanning();
  return useMutation({
    mutationFn: () => api<{ message: string }>(`absences/${id}/reject`, { method: 'POST' }),
    onSuccess: () => void refresh(),
  });
}

export function useWithdrawAbsence(id: number) {
  const refresh = useRefreshPlanning();
  return useMutation({
    mutationFn: () => api<{ message: string }>(`absences/${id}`, { method: 'DELETE' }),
    onSuccess: () => void refresh(),
  });
}
