// Kleine fetch-client voor /api/v1: zet het token erbij en maakt van fouten een ApiError met de
// Nederlandse melding van de server ({ message, errors }).

export const API_URL = (process.env.EXPO_PUBLIC_API_URL ?? 'http://localhost:8000').replace(/\/+$/, '');

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** De eerste melding voor een veld, of de algemene melding. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

type TokenSource = () => string | null;
type UnauthorizedHandler = () => void;

let tokenSource: TokenSource = () => null;
let onUnauthorized: UnauthorizedHandler = () => {};

/** Door de AuthProvider gezet: waar het token vandaan komt en wat er bij een 401 gebeurt. */
export function configureClient(source: TokenSource, unauthorized: UnauthorizedHandler) {
  tokenSource = source;
  onUnauthorized = unauthorized;
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined>;
  /** Zonder token (inloggen). */
  anonymous?: boolean;
}

export function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = `${API_URL}/api/v1/${path.replace(/^\/+/, '')}`;
  const params = Object.entries(query ?? {})
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`);

  return params.length ? `${url}?${params.join('&')}` : url;
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  const token = options.anonymous ? null : tokenSource();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  let response: Response;
  try {
    response = await fetch(buildUrl(path, options.query), {
      method: options.method ?? 'GET',
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    });
  } catch {
    throw new ApiError(0, 'Geen verbinding met PoetsPloeg. Controleer je internet en probeer het opnieuw.');
  }

  const data = await response.json().catch(() => null);
  if (!response.ok) {
    if (response.status === 401 && token) {
      onUnauthorized();
    }
    const message =
      data?.message && data.message !== 'Unauthenticated.'
        ? data.message
        : response.status === 401
          ? 'Je bent uitgelogd. Log opnieuw in.'
          : `Er ging iets mis (${response.status}).`;
    throw new ApiError(response.status, message, data?.errors ?? {});
  }

  return data as T;
}
