import type { ApiErrorEnvelope, ApiSuccess } from "./types";

export class ApiClientError extends Error {
  readonly code: string;
  readonly status: number;
  readonly requestId?: string;

  constructor(code: string, message: string, status: number, requestId?: string) {
    super(message);
    this.name = "ApiClientError";
    this.code = code;
    this.status = status;
    this.requestId = requestId;
  }
}

export function getApiBaseUrl(): string {
  return import.meta.env.VITE_API_BASE_URL ?? "/api/admin/v1";
}

export function parseApiError(body: unknown, status: number): ApiClientError {
  if (body && typeof body === "object" && "error" in body) {
    const envelope = body as ApiErrorEnvelope;
    return new ApiClientError(
      envelope.error.code,
      envelope.error.message,
      status,
      envelope.error.request_id,
    );
  }
  return new ApiClientError("UNKNOWN", "An unexpected error occurred.", status);
}

export function getStoredToken(): string | null {
  return localStorage.getItem("vpn_crm_token");
}

export function setStoredToken(token: string | null): void {
  if (token) {
    localStorage.setItem("vpn_crm_token", token);
  } else {
    localStorage.removeItem("vpn_crm_token");
  }
}

type RequestOptions = Omit<RequestInit, "body"> & {
  body?: unknown;
  token?: string | null;
};

export async function apiRequest<T>(
  path: string,
  options: RequestOptions = {},
): Promise<T> {
  const { body, token, headers, ...rest } = options;
  const authToken = token !== undefined ? token : getStoredToken();

  const response = await fetch(`${getApiBaseUrl()}${path}`, {
    ...rest,
    headers: {
      Accept: "application/json",
      ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
      ...(authToken ? { Authorization: `Bearer ${authToken}` } : {}),
      ...headers,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const text = await response.text();
  const parsed = text ? (JSON.parse(text) as unknown) : null;

  if (!response.ok) {
    throw parseApiError(parsed, response.status);
  }

  if (parsed && typeof parsed === "object" && "data" in parsed) {
    return (parsed as ApiSuccess<T>).data;
  }

  return parsed as T;
}
