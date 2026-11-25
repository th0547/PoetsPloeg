import { beforeEach, describe, expect, it, jest } from '@jest/globals';
import { api, ApiError, buildUrl, configureClient } from '@/api/client';

const respond = (status: number, body: unknown) =>
  Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) } as Response);

describe('api client', () => {
  const fetchMock = jest.fn<(...args: any[]) => Promise<Response>>();
  beforeEach(() => {
    fetchMock.mockReset();
    globalThis.fetch = fetchMock as unknown as typeof fetch;
  });

  it('bouwt de url met query en zonder lege waarden', () => {
    expect(buildUrl('today', { date: '2026-11-25', leeg: '', niks: undefined })).toBe('http://localhost:8000/api/v1/today?date=2026-11-25');
  });

  it('stuurt het token mee', async () => {
    configureClient(() => 'abc', () => {});
    fetchMock.mockReturnValue(respond(200, { ok: true }));

    await expect(api('me')).resolves.toEqual({ ok: true });
    expect((fetchMock.mock.calls[0][1] as RequestInit & { headers: Record<string, string> }).headers.Authorization).toBe('Bearer abc');
  });

  it('geeft validatiefouten per veld terug', async () => {
    fetchMock.mockReturnValue(respond(422, { message: 'Deze code klopt niet.', errors: { code: ['Deze code klopt niet.'] } }));

    const error = (await api('auth/two-factor', { method: 'POST', anonymous: true, body: {} }).catch((e) => e)) as ApiError;
    expect(error).toBeInstanceOf(ApiError);
    expect(error.field('code')).toBe('Deze code klopt niet.');
    expect((fetchMock.mock.calls[0][1] as RequestInit & { headers: Record<string, string> }).headers.Authorization).toBeUndefined();
  });

  it('logt uit bij een 401 met token', async () => {
    const onUnauthorized = jest.fn();
    configureClient(() => 'verlopen', onUnauthorized);
    fetchMock.mockReturnValue(respond(401, { message: 'Unauthenticated.' }));

    const error = (await api('today').catch((e) => e)) as ApiError;
    expect(onUnauthorized).toHaveBeenCalled();
    expect(error.message).toBe('Je bent uitgelogd. Log opnieuw in.');
  });

  it('meldt een netwerkfout in het Nederlands', async () => {
    fetchMock.mockReturnValue(Promise.reject(new TypeError('Network request failed')));

    await expect(api('today')).rejects.toThrow('Geen verbinding met PoetsPloeg');
  });
});
