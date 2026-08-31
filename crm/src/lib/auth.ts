export function shouldRedirectToLogin(
  isAuthenticated: boolean,
  loading: boolean,
  pathname: string,
): boolean {
  return !loading && !isAuthenticated && pathname !== "/login";
}

export function getPostLoginRedirect(from: string | null): string {
  if (from && from !== "/login" && from.startsWith("/")) {
    return from;
  }
  return "/";
}
